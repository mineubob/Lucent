<?php

namespace Unit;

use Lucent\Database;
use PHPUnit\Framework\TestCase;
use Lucent\Facades\File;
use Lucent\Application;

class DatabaseDriverSetup extends TestCase
{
    protected static function setupDatabase(string $driver, array $config): void
    {
        // Temporarily set EXTERNAL_ROOT to match TEMP_ROOT for testing
        File::overrideRootPath(TEMP_ROOT.DIRECTORY_SEPARATOR);

        // Create storage directory in our temp environment
        if (!is_dir(File::rootPath() . 'storage')) {
            mkdir(File::rootPath() . 'storage', 0755, true);
        }

        $env = "DB_DRIVER={$driver}\n";

        foreach ($config as $key => $value) {
            $env .= "{$key}={$value}\n";
        }

        $path = File::rootPath(). '.env';
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