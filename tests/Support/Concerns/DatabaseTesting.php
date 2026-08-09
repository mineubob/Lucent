<?php

namespace Tests\Support\Concerns;

use Lucent\Application;
use Lucent\Database;
use Lucent\Database\Migration;
use Lucent\Facades\Log;
use Lucent\Filesystem\Folder;

/**
 * Shared database testing infrastructure.
 *
 * Provides the canonical `databaseDriverProvider()` (SQLite + MySQL), the
 * `setupDatabase()` helper that configures a driver, drops existing tables
 * and migrates the given models, plus a couple of helpers used by the
 * database test classes.
 *
 * Opt-in trait: only test classes that actually exercise the database
 * should `use` it.
 */
trait DatabaseTesting
{
    /**
     * Data provider for database tests.
     *
     * Returns one dataset per driver (SQLite + MySQL), each with a single
     * connection config.
     *
     * Note: PHPUnit data providers must not declare parameters, so the
     * secondary-connection variant lives in {@see dualDatabaseDriverProvider()}
     * which delegates to the shared {@see driverConfigs()} helper.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function databaseDriverProvider(): array
    {
        return self::driverConfigs(1);
    }

    /**
     * Data provider for tests that need a secondary connection.
     *
     * For SQLite the secondary connection points at a separate file
     * (/storage/secondary.sqlite), so it is a genuinely distinct database.
     * For MySQL the secondary uses the same database name — the tests only
     * verify pooling/switching behaviour, not data isolation, so two
     * connections to the same DB are sufficient.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: array<string, string>}>
     */
    public static function dualDatabaseDriverProvider(): array
    {
        return self::driverConfigs(2);
    }

    /**
     * Build the driver datasets.
     *
     * @param int $connections 1 for a single connection, 2 to include a secondary.
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    private static function driverConfigs(int $connections): array
    {
        $mysql = [
            'DB_HOST'     => getenv('DB_HOST') ?: 'localhost',
            'DB_PORT'     => getenv('DB_PORT') ?: '3306',
            'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test_database',
            'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''
        ];

        $sqlite = ['DB_DATABASE' => '/storage/database.sqlite'];

        if ($connections === 2) {
            return [
                'sqlite' => [
                    'sqlite',
                    $sqlite,
                    ['driver' => 'sqlite', 'database' => '/storage/secondary.sqlite']
                ],
                'mysql' => [
                    'mysql',
                    $mysql,
                    [
                        'driver'   => 'mysql',
                        'host'     => $mysql['DB_HOST'],
                        'port'     => $mysql['DB_PORT'],
                        'database' => $mysql['DB_DATABASE'],
                        'username' => $mysql['DB_USERNAME'],
                        'password' => $mysql['DB_PASSWORD']
                    ]
                ]
            ];
        }

        return [
            'sqlite' => ['sqlite', $sqlite],
            'mysql'  => ['mysql', $mysql],
        ];
    }

    /**
     * Setup the database for tests.
     *
     * @param string $driver
     * @param array $config
     * @param array<class-string<\Lucent\Model\Model>> $models
     * @throws \Exception
     * @return void
     */
    protected static function setupDatabase(string $driver, array $config, array $models): void
    {
        $storage = new Folder("/storage");

        if (!$storage->exists()) {
            $storage->create(0755);
        }

        // Configure the database in memory rather than writing a .env file.
        // Replace (not merge) so a previous dataset's driver keys don't leak
        // into this one.
        $app = Application::getInstance();
        $app->setEnv(array_merge(['DB_DRIVER' => $driver], $config), false);

        // Recreate our new database singleton
        Database::reset();

        // Drop all our tables, disable FK checks to ensure we can drop them in any order.
        Database::disabling("foreign_key_checks", function () {
            $tables = Database\Schema::list();

            // Drop all our tables
            foreach ($tables as $table) {
                if (!$table->drop()) {
                    Log::channel("phpunit")->critical("[DatabaseTesting] Failed to drop all tables: Table {$table->name} failed to drop.");
                    throw new \Exception("Failed to drop all tables: Table {$table->name} failed to drop.");
                }
            }
        });

        Log::channel("phpunit")->info("[DatabaseTesting] Switched driver to " . $driver);

        $model_num = count($models);
        if ($model_num < 1) {
            Log::channel("phpunit")->info("[DatabaseTesting] No models provided for migration.");
            return;
        }

        $migrator = new Migration();

        foreach ($models as $model) {
            if (!class_exists($model)) {
                throw new \Exception("Model {$model} does not exist.");
            }

            if (!$migrator->make($model)) {
                throw new \Exception("Failed to migrate model {$model}");
            }
        }

        Log::channel("phpunit")->info("[DatabaseTesting] Migrated {$model_num} models");
    }

    /**
     * Get the current default database connection instance via reflection.
     *
     * @return mixed The 'default' connection, or null if none is registered.
     */
    private function getPrivateDatabaseInstance(): mixed
    {
        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('connections');
        $connections = $property->getValue($reflection);
        return $connections['default'] ?? null;
    }
}