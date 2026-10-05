<?php

declare(strict_types=1);

namespace Lucent\Console;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use Lucent\Commandline\Components\ProgressBar;
use Lucent\Console\Support\ModelDiscovery;
use Lucent\Console\Support\ModelFilter;
use Lucent\Logging\ConsoleColors;

/**
 * The `sync` command — diff the discovered models' desired schema against
 * the live database and apply it.
 *
 * The whole flow runs under the `radiant:schema` lock whenever the dialect
 * allows: plan → display → confirm → apply in one transaction. A change that
 * routes through an FK-involved SQLite table rebuild cannot run inside any
 * transaction (the lock is itself one on SQLite, and the rebuild's PRAGMA
 * foreign_keys toggle is a no-op there) — when the plan carries one, the
 * whole plan defers past the lock transaction and applies outside it, the
 * same defer SchemaSynchronizer::sync() performs for one-shot callers. The
 * deferred window is fenced the way sync() fences its own defer: each
 * rebuild re-reads the live table per change and fails loud on drift it
 * cannot reconcile.
 *
 * Output is echoed progressively (visible live in a terminal); in captured
 * mode (tests) the ob_start wrapper in executeConsoleCommand() still
 * collects it, so the return value is always ''.
 *
 * Filters: `--filter` / `--exclude-filter` are matched against
 * fully-qualified class names; exclude wins. A pattern that is not a valid
 * regular expression is treated as a literal substring match. Excluded
 * models' tables are resolved best-effort into a protected list, passed to
 * the differ — a protected table is never dropped and never offered as a
 * rename target (an excluded model must not look orphaned to the differ).
 *
 * Transactional apply: on by default when the dialect supports transactional
 * DDL (SQLite, PostgreSQL); MySQL DDL auto-commits, so a warning is shown
 * and the apply runs non-transactionally. Opt out with --no-transactional.
 * A plan carrying a change that needs a transaction-free connection (an
 * FK-involved SQLite table rebuild) degrades automatically to per-change
 * atomicity — apply() decides; the command only passes the flag through.
 *
 * Additive-only mode: --no-drop-tables suppresses DropTable changes for
 * tables no model declares (orphans, other tools' tables) — the plan can
 * only create and alter. Column-level drops are NOT suppressed: a live
 * column missing from the model still diffs as a destructive DropColumn,
 * covered by the destructive confirm gate.
 *
 * Rename suggestions: when the differ flags a create/drop pair as a
 * POSSIBLE RENAME, the command prompts — confirming declares the rename on
 * the blueprint (renamedFrom) and re-plans, so the differ emits the real
 * RenameTable change together with any column shape drift in one plan.
 */
final class SyncCommand
{
    public static string $command = "sync";

    /**
     * The stream prompts read answers from. Injectable so tests can feed
     * scripted answers and actually exercise the prompt flows; real runs
     * use STDIN.
     */
    private static mixed $inputStream = null;

    /**
     * Replace the stream prompts read from (e.g. a memory stream in tests).
     * Pass null to restore STDIN.
     */
    public static function useInputStream(mixed $stream): void
    {
        self::$inputStream = $stream;
    }

    /**
     * Run the sync.
     *
     * @param array<string, mixed> $options CLI options (--filter,
     *        --exclude-filter, --dir, --force, --dry-run, --no-transactional,
     *        --no-drop-tables)
     * @return string Always '' — output is echoed progressively
     */
    public function run(array $options = []): string
    {
        // ---- Discover models ----
        $dirs = isset($options['dir'])
            ? array_map(trim(...), explode(',', (string) $options['dir']))
            : null;

        $models = (new ModelDiscovery())->discover($dirs);

        // ---- Apply filters (exclude wins) ----
        try {
            $filter = new ModelFilter(
                isset($options['filter']) ? (string) $options['filter'] : null,
                isset($options['exclude-filter']) ? (string) $options['exclude-filter'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            self::error($e->getMessage());
            return '';
        }

        ['active' => $active, 'excluded' => $excluded] = $filter->apply($models);

        if ($active === []) {
            self::warn("No models matched.");
            return '';
        }

        // ---- Build the desired state ----
        $desired = [];

        foreach ($active as $model) {
            $desired[] = Blueprint::fromMetadata($model);
        }

        // ---- Protected list: excluded models' tables (best-effort) ----
        $protected = array_values(MetadataFactory::tables($excluded, skipBroken: true));

        // ---- Transactional apply: default-on when the dialect supports it ----
        $connection = Database::sqlConnection();
        $transactional = !isset($options['no-transactional'])
            && $connection->supportsTransactionalDdl();

        if (!isset($options['no-transactional']) && !$transactional) {
            self::warn(
                'This dialect does not support transactional DDL — the apply will not be atomic.'
            );
        }

        $dryRun = isset($options['dry-run']);
        $force = (bool) ($options['force'] ?? false);
        $dropTables = !isset($options['no-drop-tables']);
        $synchronizer = new SchemaSynchronizer($connection);

        // The destructive-change gate — shared by the apply under the lock and
        // by a deferred apply outside it.
        $confirm = function (SchemaChange $change) use ($force): bool {
            if ($force) {
                return true;
            }

            self::prompt($change->description);

            return self::readAnswer();
        };

        // One progress bar spans both apply legs: under the lock, and — when
        // the plan carries a change needing a transaction-free connection —
        // after it. Created once the plan is known, assigned through the
        // reference so the deferred leg can finish it.
        $progress = null;
        $onChange = function (SchemaChange $change) use (&$progress): void {
            if ($progress !== null) {
                $progress->advance();
            }
        };

        /** @var list<SchemaChange>|null $deferred The plan must apply outside the lock transaction. */
        $deferred = null;
        $applied = [];
        $total = 0;

        try {
            // ---- Plan → declare renames → re-plan → display → apply, UNDER the lock ----
            // The whole flow stays inside the lock transaction whenever the
            // dialect allows. The plan-aware predicate
            // (changeRequiresStandaloneTransaction) resolves each change's
            // FK involvement through the plan itself — an alter in a
            // rename-led plan reads the rename source's live state — so the
            // check is answerable BEFORE anything applies and the plan can
            // be judged as a unit. When any change needs a transaction-free
            // connection (an FK-involved SQLite table rebuild must toggle
            // PRAGMA foreign_keys outside any transaction), the whole plan
            // is handed back and applied after the lock releases — the same
            // defer SchemaSynchronizer::sync() performs for one-shot
            // callers. Otherwise it applies here, in the same transaction,
            // in the differ's executable order (renames already precede the
            // alters that target the renamed tables).
            $connection->withLock(function () use (
                $connection,
                $synchronizer,
                $desired,
                $protected,
                $force,
                $dryRun,
                $dropTables,
                $transactional,
                $confirm,
                $onChange,
                &$progress,
                &$deferred,
                &$applied,
                &$total,
            ): void {
                // Fresh copies — declaring a rename mutates a blueprint
                // (renamedFrom), and the originals must stay clean.
                $pristine = array_map(fn(Blueprint $b): Blueprint => clone $b, $desired);

                // ---- Plan → declare renames → re-plan (NO database changes) ----
                // The loop only converges the PLAN: each flagged create/drop
                // pair is resolved by declaring the rename on the blueprint,
                // then re-planning so the differ verifies the declaration and
                // emits the real RenameTable change — together with any
                // column shape drift, in one plan. Bounded by the plan size
                // — each declaration converts one pair.
                $changes = self::planChanges($synchronizer, $pristine, $protected, $dropTables);

                if ($changes === []) {
                    self::success("Schema is in sync. Nothing to do.");
                    return;
                }

                for ($i = 0, $max = count($changes); $i < $max; $i++) {
                    if (!self::declareRenameSuggestions($changes, $pristine, $force)) {
                        break; // no suggestions left — the plan is final
                    }

                    $changes = self::planChanges($synchronizer, $pristine, $protected, $dropTables);

                    if ($changes === []) {
                        self::success("Schema is in sync. Nothing to do.");
                        return;
                    }
                }

                if ($changes === []) {
                    self::success("Schema is in sync. Nothing to do.");
                    return;
                }

                // ---- The blueprints are complete — display the final plan ----
                self::line(ConsoleColors::FG_CYAN . "Planned schema changes:" . ConsoleColors::RESET);
                foreach ($changes as $change) {
                    $marker = $change->destructive
                        ? ConsoleColors::FG_RED . "  ! " . ConsoleColors::RESET
                        : "    ";
                    self::line($marker . $change->description);
                }

                if ($dryRun) {
                    self::line(ConsoleColors::FG_CYAN . "Dry run — no changes applied." . ConsoleColors::RESET);
                    return;
                }

                // ---- Confirm gate ----
                $destructive = array_filter($changes, fn(SchemaChange $c): bool => $c->destructive);

                // Interactive when a TTY is present OR an input stream is
                // injected (tests feed scripted answers through the seam —
                // they must reach the prompt flows, not the fail-fast gate).
                if ($destructive !== [] && !$force && !self::canPrompt()) {
                    self::error(
                        "Destructive changes present and no TTY available — re-run with --force to apply."
                    );
                    return;
                }

                $total = count($changes);
                $progress = new ProgressBar($total);
                $progress->setFormat('[{bar}] {percent}% ({current}/{total})');

                // A change the dialect cannot apply inside a transaction (an
                // FK-involved SQLite table rebuild needs the foreign_keys
                // PRAGMA toggle outside one) defers the whole plan past the
                // lock transaction — the plan is a unit, and the alters that
                // would follow a rebuild in the same transaction would roll
                // back with it anyway. The race window is fenced by the
                // rebuild itself: it re-reads the live table per change and
                // its foreign_key_check gate fails loud on drift it cannot
                // reconcile.
                if (array_any(
                    $changes,
                    fn (SchemaChange $c): bool => $connection->changeRequiresStandaloneTransaction($c, $changes),
                )) {
                    $deferred = $changes;
                    return;
                }

                $applied = $synchronizer->apply(
                    $changes,
                    confirm: $confirm,
                    onChange: $onChange,
                    transactional: $transactional,
                );

                $progress->finish();
                self::success(count($applied) . " of " . $total . " planned change(s) applied.");
            }, 'radiant:schema');
        } catch (\Throwable $e) {
            self::error("Sync failed: " . $e->getMessage());
            return '';
        }

        if ($deferred === null) {
            return '';
        }

        // ---- Deferred apply: outside the lock transaction ----
        try {
            $applied = $synchronizer->apply(
                $deferred,
                confirm: $confirm,
                onChange: $onChange,
                transactional: $transactional,
            );

            $progress?->finish();

            self::success(count($applied) . " of " . $total . " planned change(s) applied.");
        } catch (\Throwable $e) {
            self::error("Sync failed: " . $e->getMessage());
        }

        return '';
    }

    /**
     * Plan the changes for the given blueprints. Protected tables are
     * enforced by the differ itself — never dropped, never offered as a
     * rename target — so no post-plan filtering is needed here. With
     * $dropTables off the plan is additive-only: undeclared live tables
     * are left untouched.
     *
     * @param list<Blueprint> $blueprints
     * @param list<string> $protected Tables never offered for drop or rename
     * @param bool $dropTables Whether undeclared live tables are offered for drop
     * @return list<SchemaChange>
     */
    private static function planChanges(
        SchemaSynchronizer $synchronizer,
        array $blueprints,
        array $protected,
        bool $dropTables = true,
    ): array {
        return $synchronizer->plan($blueprints, protected: $protected, dropTables: $dropTables);
    }

    /**
     * Resolve flagged create/drop pairs by DECLARING the rename on the
     * blueprint — the decision, not a guess.
     *
     * When the user confirms, the desired blueprint for the new table gets
     * a renamedFrom declaration; the caller re-plans so the differ verifies
     * the declaration and emits the real RenameTable change. Each old table
     * can only be claimed by one rename (the differ pairs every create with
     * its best-overlap drop, so multiple creates can claim the same drop —
     * first claim wins).
     *
     * With --force every suggestion is auto-accepted. Without a TTY or an
     * injected input stream the flagged pair is kept as-is (the plan shows
     * the POSSIBLE RENAME annotation and the destructive gate handles it)
     * — never block on stdin we cannot read.
     *
     * @param list<SchemaChange> $changes The current plan
     * @param list<Blueprint> $pristine The desired blueprints (mutated in place)
     * @param bool $force Auto-accept every suggestion
     * @return bool Whether any rename was declared (caller must re-plan)
     */
    private static function declareRenameSuggestions(array $changes, array &$pristine, bool $force): bool
    {
        // Pair up flagged creates with their flagged drops.
        $pairs = []; // createTable => dropTable
        foreach ($changes as $change) {
            if ($change->operation === SchemaOperation::CreateTable && $change->renameOf !== null) {
                $pairs[$change->table] = $change->renameOf;
            }
        }

        if ($pairs === []) {
            return false;
        }

        // Nothing to prompt with (no TTY, no injected stream) and no
        // --force: keep the flagged pair as-is.
        if (!$force && !self::canPrompt()) {
            return false;
        }

        $declared = false;
        $claimedOldTables = []; // each old table can only be renamed once

        foreach ($pairs as $newTable => $oldTable) {
            // Only the first claim on an old table becomes a rename.
            if (in_array($oldTable, $claimedOldTables, true)) {
                continue;
            }

            // readAnswer() already normalizes to a bool — a plain falsy
            // check is the correct gate (a strict string compare would
            // treat a typed "yes" as a decline and re-prompt every
            // remaining pair with the same old table).
            if ($force) {
                $answer = true;
            } else {
                self::prompt(
                    "POSSIBLE RENAME: [{$oldTable}] → [{$newTable}]. Treat as a rename?"
                );
                $answer = self::readAnswer();
            }

            if (!$answer) {
                continue;
            }

            foreach ($pristine as $index => $blueprint) {
                if ($blueprint->getTable() === $newTable) {
                    $pristine[$index] = $blueprint->renamedFrom($oldTable);
                    $claimedOldTables[] = $oldTable;
                    $declared = true;
                    break;
                }
            }
        }

        return $declared;
    }

    /**
     * Whether the process has an interactive terminal on stdin.
     *
     * The LUCENT_NON_INTERACTIVE env var forces the non-interactive path —
     * used by the test suite and CI runners; a TTY on stdin would otherwise
     * make the prompts block on fgets() forever. An injected input stream
     * (useInputStream) also implies non-interactive TTY detection is
     * irrelevant — prompts are answered from the stream.
     */
    private static function isInteractive(): bool
    {
        if (getenv('LUCENT_NON_INTERACTIVE') === '1') {
            return false;
        }

        return is_resource(STDIN) && stream_isatty(STDIN);
    }

    /**
     * Whether prompts can be asked AND answered: a TTY on stdin, or an
     * injected input stream (tests feed scripted answers through the
     * seam). The rename suggestions and the destructive confirm gate share
     * this definition so both prompt flows are reachable under the same
     * conditions.
     */
    private static function canPrompt(): bool
    {
        return self::isInteractive()
            || (self::$inputStream !== null && is_resource(self::$inputStream));
    }

    /**
     * Display a prompt question on STDERR.
     *
     * Prompts go to STDERR, never STDOUT: they are interaction, not result
     * output. This keeps them visible when stdout is redirected or captured
     * (ob_start in test/execute mode would otherwise SWALLOW the question —
     * the user would see a silent hang) and follows the git/composer/ssh
     * convention of separating interaction from results.
     */
    private static function prompt(string $question): void
    {
        fwrite(
            STDERR,
            ConsoleColors::FG_YELLOW . $question . ConsoleColors::RESET . "\n"
            . "Apply? Type yes or no: "
        );
    }

    /**
     * Read a yes/no answer from the injected stream or STDIN.
     */
    private static function readAnswer(): bool
    {
        $stream = self::$inputStream ?? STDIN;
        $answer = strtolower(trim((string) fgets($stream)));

        return in_array($answer, ['y', 'yes'], true);
    }

    private static function line(string $message): void
    {
        echo $message . "\n";
    }

    private static function success(string $message): void
    {
        echo ConsoleColors::FG_GREEN . $message . ConsoleColors::RESET . "\n";
    }

    private static function warn(string $message): void
    {
        echo ConsoleColors::FG_YELLOW . $message . ConsoleColors::RESET . "\n";
    }

    private static function error(string $message): void
    {
        echo ConsoleColors::FG_RED . $message . ConsoleColors::RESET . "\n";
    }
}
