<?php

declare(strict_types=1);

namespace Lucent\Console;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use Lucent\Console\Support\ModelDiscovery;
use Lucent\Console\Support\ModelFilter;
use Lucent\Logging\ConsoleColors;

/**
 * The `sync:legacy` command — a one-time table-name migration for
 * databases created by the pre-Radiant Lucent ORM.
 *
 * Old Lucent named tables after the model's short class name (e.g.
 * `TestUser`); Radiant defaults to the snake-cased plural (`test_users`).
 * For each discovered model, when the old-style table exists and the
 * Radiant name does not, the table is renamed — data travels with the
 * rename. No column or data conversion is performed (old DDL remains
 * valid under Radiant).
 *
 * Output is echoed progressively (visible live in a terminal); in captured
 * mode (tests) the ob_start wrapper in executeConsoleCommand() still
 * collects it, so the return value is always ''.
 *
 * Idempotent: a second run finds nothing to rename. Deprecated — will be
 * removed in a future release.
 *
 * --dry-run displays the rename plan and exits without applying anything.
 */
final class SyncLegacyCommand
{
    public static string $command = "sync:legacy";

    /**
     * Run the legacy table-name migration.
     *
     * @param array<string, mixed> $options CLI options (--dir, --filter,
     *        --exclude-filter, --dry-run)
     * @return string Always '' — output is echoed progressively
     */
    public function run(array $options = []): string
    {
        self::warn(
            "DEPRECATED: sync:legacy is a one-time migration and will be removed in a future release."
        );

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

        ['active' => $models] = $filter->apply($models);

        if ($models === []) {
            self::warn("No models found.");
            return '';
        }

        // ---- Resolve old and new table names ----
        $connection = Database::sqlConnection();
        $liveTables = $connection->schemaInspector->tables();

        // Atomic apply: on dialects with transactional DDL the whole rename
        // set commits or rolls back together (a mid-loop failure must not
        // leave half the tables renamed). MySQL DDL auto-commits, so the
        // apply runs non-transactionally there.
        $transactional = $connection->supportsTransactionalDdl();

        $renamed = 0;
        $skipped = 0;
        $dryRun = isset($options['dry-run']);

        try {
            $connection->withLock(function () use ($connection, $models, $liveTables, $transactional, $dryRun, &$renamed, &$skipped): void {
                $synchronizer = new SchemaSynchronizer($connection);

                // ---- Collect every rename first — apply ONCE, atomically ----
                $changes = [];

                foreach ($models as $model) {
                    $metadata = MetadataFactory::tryFor($model);

                    if ($metadata === null || $metadata->tableName === null) {
                        continue; // broken or table-less model — skip
                    }

                    $newTable = $metadata->tableName;
                    $oldTable = (new \ReflectionClass($model))->getShortName();

                    if ($oldTable === $newTable) {
                        continue; // names already match
                    }

                    if (!in_array($oldTable, $liveTables, true)) {
                        continue; // no old-style table — nothing to rename
                    }

                    if (in_array($newTable, $liveTables, true)) {
                        self::error("Cannot rename [{$oldTable}]: target [{$newTable}] already exists.");
                        $skipped++;
                        continue;
                    }

                    $changes[] = new SchemaChange(
                        $newTable,
                        \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::RenameTable,
                        (new Blueprint($newTable))->renamedFrom($oldTable),
                        false,
                        "rename table [{$oldTable}] to [{$newTable}] — data travels with the rename",
                        false,
                        $oldTable,
                    );
                }

                if ($changes === []) {
                    return;
                }

                if ($dryRun) {
                    foreach ($changes as $change) {
                        self::line("Would rename [{$change->renameOf}] → [{$change->table}].");
                    }
                    self::line("Dry run — no changes applied.");
                    return;
                }

                // Renames are not destructive — no confirm gate. Apply the
                // whole set in one call so a transactional dialect commits
                // or rolls back atomically.
                $applied = $synchronizer->apply($changes, transactional: $transactional);
                $renamed = count($applied);

                foreach ($applied as $change) {
                    self::success("Renamed [{$change->renameOf}] → [{$change->table}].");
                }
            }, 'radiant:schema');
        } catch (\Throwable $e) {
            self::error("sync:legacy failed: " . $e->getMessage());
            return '';
        }

        if ($renamed === 0 && $skipped === 0) {
            self::success("Nothing to rename — schema is already in the Radiant naming.");
        } else {
            self::line("Renamed {$renamed} table(s), skipped {$skipped}.");
        }

        return '';
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
