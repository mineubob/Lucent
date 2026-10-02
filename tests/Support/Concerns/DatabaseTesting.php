<?php

namespace Tests\Support\Concerns;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use Lucent\Application;

/**
 * Minimal Radiant database harness for Lucent's added-feature tests.
 *
 * The ORM and DB layer are tested in Radiant's own repo — this harness
 * only builds a DatabaseManager from the environment and syncs the given
 * fixture models' schema, so Lucent's commands and #[Bind] have something
 * to work against.
 *
 * Opt-in trait: only test classes that exercise the database should `use` it.
 */
trait DatabaseTesting
{
    /**
     * Data provider for database tests.
     *
     * SQLite in-memory only for the local suite; MySQL is exercised in CI
     * via the DB_* environment variables.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function databaseDriverProvider(): array
    {
        $mysql = [
            'driver'   => 'mysql',
            'host'     => getenv('DB_HOST') ?: 'localhost',
            'port'     => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE') ?: 'test_database',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];

        return [
            'sqlite' => ['sqlite', ['driver' => 'sqlite', 'database' => ':memory:']],
            'mysql'  => ['mysql', $mysql],
        ];
    }

    /**
     * Configure the Radiant DatabaseManager from the given config and sync
     * the given models' schema into it.
     *
     * @param string $driver Driver name (sqlite, mysql)
     * @param array<string, mixed> $config Radiant connection config
     * @param list<class-string<\BlueprintAU\Radiant\Model>> $models Models to sync
     */
    protected static function setupDatabase(string $driver, array $config, array $models): void
    {
        // Mirror the config into DB_* env vars and let Application's
        // configureDatabase() build the manager — the same path production
        // uses, so the harness exercises the real wiring.
        $env = ['DB_DRIVER' => $driver];

        foreach ($config as $key => $value) {
            if ($key === 'driver') {
                continue;
            }
            $env['DB_' . strtoupper((string) $key)] = (string) $value;
        }

        Application::getInstance()->setEnv($env, false);

        // MySQL is a persistent server shared across tests — drop any
        // tables left by earlier tests (and their auto-increment counters)
        // so every test starts from a clean schema. SQLite :memory: is
        // per-connection and already empty; dropping from an empty schema
        // is a no-op, so the call is unconditional.
        self::resetDatabase();

        if ($models === []) {
            return;
        }

        $connection = Database::sqlConnection();
        $synchronizer = new SchemaSynchronizer($connection);

        $desired = array_map(
            fn(string $model): Blueprint => Blueprint::fromMetadata($model),
            $models,
        );

        $synchronizer->sync($desired, confirm: fn() => true);
    }

    /**
     * Drop every table in the live schema.
     *
     * MySQL is a persistent server shared across tests — tables and rows
     * from an earlier test leak into later ones (auto-increment counters
     * included), so tests that assume a fresh database must reset it.
     * SQLite :memory: is per-connection and needs no reset, but dropping
     * from an empty schema is a no-op, so the call is unconditional.
     */
    protected static function resetDatabase(): void
    {
        $connection = Database::sqlConnection();

        foreach ($connection->schemaInspector->tables() as $table) {
            $connection->drop($table);
        }
    }

    /**
     * Create a legacy-named table (short class name) with the given
     * columns, dialect-agnostically — via a Radiant blueprint rather than
     * raw SQLite DDL (double-quoted identifiers and AUTOINCREMENT are
     * SQLite-only).
     *
     * @param string $table The legacy table name (e.g. "TestUser")
     * @param array<string, ColumnType> $columns Column name => type
     */
    protected static function createLegacyTable(string $table, array $columns): void
    {
        $blueprint = new Blueprint($table);

        $first = true;
        foreach ($columns as $name => $type) {
            $blueprint = $blueprint->column(
                $type,
                $name,
                primaryKey: $first,
                autoIncrement: $first,
            );
            $first = false;
        }

        Database::sqlConnection()->create($blueprint);
    }
}
