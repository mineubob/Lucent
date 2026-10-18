<?php

namespace Tests\Feature;

use App\Models\Medias;
use App\Models\RebuildTarget;
use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesCommandOutput;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\TestCase;
use Lucent\Console\SyncCommand;
use Lucent\Facades\CommandLine;

/**
 * `sync --step` — hand-authored data steps spliced between the plan's
 * ADD-phase changes and its destructive drops.
 *
 * Each test writes its step files at runtime under TEMP_ROOT (wiped every
 * run — no committed .sql fixtures). The scenario model is the motivating
 * case: a live `medias` table with legacy nullable FK columns
 * (post_id/event_id/profile_id) whose model declares the morph pair
 * (attachable_type/attachable_id) instead — the plan is add ×2 + drop ×3
 * (plus any live-shape drift), and the step backfills the morph pair
 * between the adds and the drops.
 */
class SyncCommandStepTest extends TestCase
{
    use CapturesCommandOutput;
    use CopiesFixtures;
    use DatabaseTesting;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Model' => ['Medias.php', 'RebuildTarget.php'],
            // Step fixtures — all committed now. Callable `.php` fixtures
            // declare their SyncStep class behind a class_exists guard so
            // the same file can be re-required across this PHPUnit
            // process's tests (a bare second declaration is an uncatchable
            // "Cannot redeclare class" fatal); see the guard comment in
            // morph-backfill.php. The duplicate-run test's marker step is
            // the only runtime-authored step (writeStepFile).
            'Step' => [
                'morph-backfill.sql',
                'a-first.sql',
                'b-second.sql',
                'audit-insert.sql',
                'broken.sql',
                'explodes.sql',
                'gate-check.sql',
                'touch.sql',
                'wrong-return.php',
                'broken-php.php',
                'morph-backfill.php',
                'a-first.php',
                'gate-check.php',
                'gate-explodes.php',
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureCommandOutput();
    }

    // ---- Scenario builders -------------------------------------------------

    /**
     * Create the live `medias` table in the legacy shape (morph columns
     * absent, legacy FK-shaped columns present) and populate it.
     *
     * Built via a Radiant blueprint rather than raw SQLite DDL — the test
     * runs against both dialects, and AUTOINCREMENT / quoted-identifier
     * DDL is SQLite-only (see DatabaseTesting::createLegacyTable()).
     */
    private static function createLegacyMediasTable(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::BigInt, 'post_id', nullable: true)
            ->column(ColumnType::BigInt, 'event_id', nullable: true)
            ->column(ColumnType::BigInt, 'profile_id', nullable: true);

        Database::sqlConnection()->create($blueprint);

        $connection = Database::sqlConnection();

        $connection->statement(
            "INSERT INTO medias (post_id, event_id, profile_id) VALUES (11, NULL, NULL)"
        );
        $connection->statement(
            "INSERT INTO medias (post_id, event_id, profile_id) VALUES (NULL, 22, NULL)"
        );
        $connection->statement(
            "INSERT INTO medias (post_id, event_id, profile_id) VALUES (NULL, NULL, 33)"
        );
    }

    /**
     * Create a minimal `medias` table matching the model's id column, so
     * the plan is purely additive (add the morph pair) — the shape the
     * non-destructive marker test needs (nothing else may trip the gate).
     */
    private static function createMinimalMediasTable(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true);

        Database::sqlConnection()->create($blueprint);
    }

    /**
     * Write the duplicate-run test's marker step under TEMP_ROOT/steps and
     * return its absolute path. The ONLY runtime-authored step left: this
     * fixture's correctness depends on a load-time side effect pointing at
     * a per-run temp path, which a committed fixture cannot carry.
     */
    private static function writeStepFile(string $filename, string $contents): string
    {
        $dir = TEMP_ROOT . 'steps';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Absolute path of a committed step fixture copied by
     * setUpBeforeClass (FixtureLoader::copyStep → temp_install/steps/).
     */
    private static function stepPath(string $filename): string
    {
        return TEMP_ROOT . 'steps' . DIRECTORY_SEPARATOR . $filename;
    }

    // ---- Tests -------------------------------------------------------------

    #[DataProvider('databaseDriverProvider')]
    public function test_step_splices_between_adds_and_drops_and_backfills($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::stepPath('morph-backfill.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // The step renders with its distinct marker and statement count.
        $this->assertStringContainsString(
            'data step [morph-backfill.sql]: 3 statement(s)',
            $result
        );

        // Splice position: the step appears AFTER the morph-pair adds and
        // BEFORE the legacy-FK drops.
        $addPos = strpos($result, 'attachable_type');
        $stepPos = strpos($result, 'data step [morph-backfill.sql]');
        $dropPos = strpos($result, 'drop column(s) [post_id');

        $this->assertNotFalse($addPos, 'Plan must list the add of attachable_type');
        $this->assertNotFalse($stepPos, 'Plan must list the data step');
        $this->assertNotFalse($dropPos, 'Plan must list the drop of post_id');
        $this->assertLessThan($stepPos, $addPos, 'Step must run after the adds');
        $this->assertLessThan($dropPos, $stepPos, 'Step must run before the drops');

        // The backfill actually ran: the morph pair is populated.
        $connection = Database::sqlConnection();
        $rows = $connection->selectSql(
            'SELECT attachable_type, attachable_id FROM medias ORDER BY id',
            []
        );

        $this->assertCount(3, $rows);
        $this->assertSame('post', $rows[0]->attachable_type);
        $this->assertSame(11, (int) $rows[0]->attachable_id);
        $this->assertSame('event', $rows[1]->attachable_type);
        $this->assertSame(22, (int) $rows[1]->attachable_id);
        $this->assertSame('profile', $rows[2]->attachable_type);
        $this->assertSame(33, (int) $rows[2]->attachable_id);

        // The drops ran after the step: legacy FK columns are gone.
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertNotContains('post_id', $columns);
        $this->assertNotContains('event_id', $columns);
        $this->assertNotContains('profile_id', $columns);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_multiple_steps_keep_command_line_order($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $first = self::stepPath('a-first.sql');
        $second = self::stepPath('b-second.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$first} --step={$second} --force"
        );

        $firstPos = strpos($result, 'data step [a-first.sql]');
        $secondPos = strpos($result, 'data step [b-second.sql]');

        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertLessThan($secondPos, $firstPos, 'Steps must run in command-line order');
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_unterminated_step_file_aborts_before_any_ddl($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        // No `;\n` anywhere — no statement is terminated under the
        // documented restriction.
        $stepPath = self::stepPath('broken.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // Clear error naming the file and the offending line.
        $this->assertStringContainsString('broken.sql', $result);
        $this->assertStringContainsString("not terminated", $result);

        // Nothing applied — the plan never ran: morph columns absent,
        // legacy columns intact, data untouched.
        $connection = Database::sqlConnection();
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);

        $rows = $connection->selectSql('SELECT COUNT(*) AS c FROM medias', []);
        $this->assertSame(3, (int) $rows[0]->c);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bare_step_flag_is_a_usage_error($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step --force"
        );

        $this->assertStringContainsString('--step option expects a file path', $result);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_unreadable_step_file_aborts_before_any_ddl($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' "
            . "--step=" . TEMP_ROOT . 'steps/does-not-exist.sql --force'
        );

        $this->assertStringContainsString('not readable', $result);
        $this->assertStringContainsString('does-not-exist.sql', $result);

        // Nothing applied.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
    }

    public function test_non_destructive_marker_skips_the_gate(): void
    {
        // SQLite-only: the marker's gate behavior is dialect-independent,
        // and this test needs a purely additive plan (nothing else may trip
        // the gate).
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createMinimalMediasTable();

        // An INSERT-only step: non-destructive by classification. Marked,
        // it must run WITHOUT --force and WITHOUT an input stream — the
        // LUCENT_NON_INTERACTIVE fail-fast gate ("no TTY available") must
        // NOT fire for it.
        $stepPath = self::stepPath('audit-insert.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath}"
        );

        // No TTY gate error, and the step ran.
        $this->assertStringNotContainsString('no TTY available', $result);
        $this->assertStringContainsString('data step [audit-insert.sql]: 1 statement(s)', $result);
        $this->assertStringContainsString('planned change(s) applied', $result);

        $rows = Database::sqlConnection()->selectSql(
            "SELECT COUNT(*) AS c FROM medias WHERE attachable_type = 'post' AND attachable_id = 99",
            []
        );
        $this->assertSame(1, (int) $rows[0]->c);
    }

    public function test_unmarked_step_hits_the_no_tty_gate(): void
    {
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createLegacyMediasTable();

        // The SAME unmarked UPDATEs are destructive — with no TTY
        // and no input stream, the run must fail fast before applying
        // anything.
        $stepPath = self::stepPath('gate-check.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath}"
        );

        $this->assertStringContainsString('no TTY available', $result);

        // Nothing applied — morph columns absent.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertNotContains('attachable_type', $columns);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_dry_run_prints_step_contents_and_applies_nothing($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::stepPath('morph-backfill.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --dry-run"
        );

        $this->assertStringContainsString('Planned schema changes', $result);
        $this->assertStringContainsString('data step [morph-backfill.sql]: 3 statement(s)', $result);

        // The FULL step file contents are printed under the Data steps
        // header (first statement verbatim).
        $this->assertStringContainsString('Data steps:', $result);
        $this->assertStringContainsString(
            "UPDATE medias SET attachable_type = 'post', attachable_id = post_id WHERE post_id IS NOT NULL;",
            $result
        );

        $this->assertStringContainsString('Dry run — no changes applied', $result);

        // Nothing applied — morph columns absent, legacy columns intact.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);
    }

    public function test_failing_statement_rolls_the_plan_back(): void
    {
        // SQLite only: the rollback guarantee (transactional DDL) is the
        // assertion's subject, and MySQL's auto-committing DDL has no
        // equivalent behavior to assert.
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createLegacyMediasTable();

        // First statement is valid SQL but references a column that will
        // NOT exist in the plan's order of operations at step time — a
        // data-step syntax/runtime error. The whole plan fails; the
        // transaction rolls back: no morph columns, legacy columns intact.
        $stepPath = self::stepPath('explodes.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // The error surfaced (column not found).
        $this->assertStringContainsString('no_such_column', $result);

        // The transaction rolled back: the add before the step is undone.
        $connection = Database::sqlConnection();
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns, 'Legacy column must survive the rollback');
        $this->assertNotContains('attachable_type', $columns, 'Add before the failed step must roll back');
    }

    public function test_step_keeps_its_position_when_the_plan_defers(): void
    {
        // SQLite only: the deferral (FK-involved rebuild needing the
        // foreign_keys PRAGMA toggle outside any transaction) is a SQLite
        // behavior.
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);

        // A populated table the model will modify, REFERENCED by another
        // table: the NOT NULL-no-default add routes through the SQLite
        // rebuild, and the referencing table makes the rebuild FK-involved
        // → the whole plan (with the steps spliced into it) defers past
        // the lock.
        $connection = Database::sqlConnection();
        $connection->statement('CREATE TABLE rebuild_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL
        )');
        $connection->statement('CREATE TABLE watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id INTEGER NOT NULL,
            FOREIGN KEY (target_id) REFERENCES rebuild_targets(id)
        )');
        $connection->statement(
            "INSERT INTO rebuild_targets (label) VALUES ('one'), ('two')"
        );

        $stepPath = self::stepPath('touch.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/RebuildTarget$/' --step={$stepPath} --force"
        );

        // The deferred leg applied everything — including the step, in
        // its plan position (the step ran; its effect is visible).
        $this->assertStringContainsString('data step [touch.sql]: 1 statement(s)', $result);
        $this->assertStringContainsString('planned change(s) applied', $result);

        // The rebuild realized the desired shape AND the step ran after it.
        $columns = array_column(
            $connection->schemaInspector->table('rebuild_targets')->columns,
            'name',
        );
        $this->assertContains('status', $columns);

        $rows = $connection->selectSql(
            "SELECT status FROM rebuild_targets WHERE label = 'one'",
            []
        );
        $this->assertSame('migrated', $rows[0]->status, 'Step must have run in the deferred leg');
    }

    public function test_sync_command_help_lists_step_option(): void
    {
        $result = CommandLine::execute('');

        $this->assertStringContainsString('--step=', $result);
        $this->assertStringContainsString('repeatable', $result);
    }

    // ---- Callable (.php) step tests ----------------------------------------

    #[DataProvider('databaseDriverProvider')]
    public function test_php_step_splices_between_adds_and_drops_and_backfills($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::stepPath('morph-backfill.php');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // The callable step renders with its description (prefixed with the
        // same "data step [file]:" shape as its SQL sibling).
        $this->assertStringContainsString(
            'data step [morph-backfill.php]: Backfill the morph pair from the legacy FK columns',
            $result
        );

        // Splice position: same placement rule as .sql steps.
        $addPos = strpos($result, 'attachable_type');
        $stepPos = strpos($result, 'data step [morph-backfill.php]');
        $dropPos = strpos($result, 'drop column(s) [post_id');

        $this->assertNotFalse($addPos, 'Plan must list the add of attachable_type');
        $this->assertNotFalse($stepPos, 'Plan must list the callable step');
        $this->assertNotFalse($dropPos, 'Plan must list the drop of post_id');
        $this->assertLessThan($stepPos, $addPos, 'Step must run after the adds');
        $this->assertLessThan($dropPos, $stepPos, 'Step must run before the drops');

        // The step ran: morph pair populated via the bound writes.
        $connection = Database::sqlConnection();
        $rows = $connection->selectSql(
            'SELECT attachable_type, attachable_id FROM medias ORDER BY id',
            []
        );

        $this->assertCount(3, $rows);
        $this->assertSame('post', $rows[0]->attachable_type);
        $this->assertSame('event', $rows[1]->attachable_type);
        $this->assertSame('profile', $rows[2]->attachable_type);

        // The drops ran after the step.
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertNotContains('post_id', $columns);
        $this->assertNotContains('profile_id', $columns);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sql_and_php_steps_keep_command_line_order($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $first = self::stepPath('a-first.php');
        $second = self::stepPath('b-second.sql');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$first} --step={$second} --force"
        );

        $firstPos = strpos($result, 'data step [a-first.php]');
        $secondPos = strpos($result, 'data step [b-second.sql]');

        $this->assertNotFalse($firstPos, 'Callable step must appear in the plan');
        $this->assertNotFalse($secondPos, 'SQL step must appear in the plan');
        $this->assertLessThan($secondPos, $firstPos, 'Steps must run in command-line order regardless of form');
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_php_step_not_returning_syncstep_fails_fast($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::stepPath('wrong-return.php');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        $this->assertStringContainsString('wrong-return.php', $result);
        $this->assertStringContainsString(
            'must return a Lucent\Support\SyncStep instance; got string',
            $result
        );

        // Nothing applied — the load failed before the lock.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_php_step_that_throws_on_require_fails_fast($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        // A parse error at require time — the wrapped-throw path.
        $stepPath = self::stepPath('broken-php.php');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        $this->assertStringContainsString('broken-php.php', $result);
        $this->assertStringContainsString('could not be loaded', $result);

        // Nothing applied.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);
    }

    public function test_duplicate_php_step_require_runs_once_but_records_twice(): void
    {
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createMinimalMediasTable();

        // The step records its executions to a file OUTSIDE the step
        // definition: load-time state (a counter) vs run-time effect must
        // be distinguishable. The file's top-level code runs once (the
        // require-once guard) even though the step appears twice on the
        // command line — and each occurrence still becomes its own ordered
        // step record, so the RUN fires twice.
        $marker = TEMP_ROOT . 'steps/load-counter.txt';
        $markerVar = var_export($marker, true);

        $stepPath = self::writeStepFile('audit-insert.php', <<<PHP
            <?php

            use BlueprintAU\Radiant\Database\Connections\SqlConnection;
            use Lucent\Support\SyncStep;

            \\file_put_contents({$markerVar}, "L", \\FILE_APPEND);

            final class AuditInsertStep implements SyncStep
            {
                public function describe(): string
                {
                    return 'Insert one audit row';
                }

                public function destructive(): bool
                {
                    return false;
                }

                public function table(): string
                {
                    return 'medias';
                }

                public function run(SqlConnection \$conn): void
                {
                    \\file_put_contents({$markerVar}, "R", \\FILE_APPEND);

                    \$conn->statement(
                        'INSERT INTO medias (attachable_type, attachable_id) VALUES (?, ?)',
                        ['audit', 7]
                    );
                }
            }

            return new AuditInsertStep();
            PHP);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' "
            . "--step={$stepPath} --step={$stepPath} --force"
        );

        // Both occurrences rendered, in order.
        $firstPos = strpos($result, 'data step [audit-insert.php]');
        $secondPos = strrpos($result, 'data step [audit-insert.php]');
        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertLessThan($secondPos, $firstPos, 'Both occurrences must appear in the plan');

        // Load side effect once ("L"), run side effect per occurrence ("RR").
        $this->assertSame('LRR', file_get_contents($marker));

        // Each run inserted its row.
        $rows = Database::sqlConnection()->selectSql(
            "SELECT COUNT(*) AS c FROM medias WHERE attachable_type = 'audit'",
            []
        );
        $this->assertSame(2, (int) $rows[0]->c);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_dry_run_shows_callable_step_description_only($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::stepPath('morph-backfill.php');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --dry-run"
        );

        $this->assertStringContainsString('Planned schema changes', $result);
        $this->assertStringContainsString(
            'data step [morph-backfill.php]: Backfill the morph pair',
            $result
        );

        // The callable step's dry-run line: description + source pointer.
        $this->assertStringContainsString('Data steps:', $result);
        $this->assertStringContainsString(
            '(callable step — review the source at',
            $result
        );

        // No source dump — the PHP body must not appear (veto-gate source
        // line; the generated class name is random, so the body is what we
        // can assert on).
        $this->assertStringNotContainsString('More than one orphan media row', $result);
        $this->assertStringContainsString('Dry run — no changes applied', $result);

        // Nothing applied.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);
    }

    public function test_destructive_php_step_hits_the_no_tty_gate(): void
    {
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createLegacyMediasTable();

        // destructive(): true — the gate must treat the callable step
        // exactly as it treats an unmarked .sql step. The fixture carries
        // the class_exists guard: this file is re-required across the
        // PHPUnit process's tests, and a bare second class declaration is
        // an uncatchable fatal (real step files never need it).
        $stepPath = self::stepPath('gate-check.php');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath}"
        );

        $this->assertStringContainsString('no TTY available', $result);

        // Nothing applied.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertNotContains('attachable_type', $columns);
    }

    public function test_throwing_php_step_rolls_the_plan_back(): void
    {
        // SQLite only: the rollback guarantee (transactional DDL) is the
        // assertion's subject.
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createLegacyMediasTable();

        // The veto gate fires: the first UPDATE ran, then the DomainException
        // aborts the plan — the identical rollback semantics a failing SQL
        // statement has. The add before the step must roll back with it.
        $stepPath = self::stepPath('gate-explodes.php');

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // The veto surfaced through the exception chain.
        $this->assertStringContainsString('DomainException', $result);
        $this->assertStringContainsString('Refusing to continue', $result);

        // The transaction rolled back: the add before the step is undone.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns, 'Legacy column must survive the rollback');
        $this->assertNotContains('attachable_type', $columns, 'Add before the vetoed step must roll back');
    }
}
