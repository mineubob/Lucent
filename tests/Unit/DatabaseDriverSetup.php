<?php

namespace Unit;

use Lucent\Database;
use Lucent\Facades\FileSystem;
use Lucent\Filesystem\Folder;
use PHPUnit\Framework\TestCase;
use Lucent\Application;

class DatabaseDriverSetup extends TestCase
{
    protected static function setupDatabase(string $driver, array $config): void
    {
        $storage = new Folder("/storage");
        $storage->create(0755);

        $env = "DB_DRIVER={$driver}\n";

        foreach ($config as $key => $value) {
            $env .= "{$key}={$value}\n";
        }

        $path = FileSystem::rootPath().DIRECTORY_SEPARATOR. '.env';
        file_put_contents($path, $env);

        $app = Application::getInstance();
        $app->LoadEnv();
        Database::initialize();

        if ($driver === "mysql") {
            // For MySQL, disable foreign key checks before dropping tables
            Database::statement("SET FOREIGN_KEY_CHECKS=0");

            // Get all tables in the database
            $tables = Database::select("SHOW TABLES");

            // Drop each table
            foreach ($tables as $table) {
                $tableName = current($table); // Get the table name from result
                Database::statement("DROP TABLE IF EXISTS `{$tableName}`");
            }

            // Re-enable foreign key checks
            Database::statement("SET FOREIGN_KEY_CHECKS=1");
        }
    }
}