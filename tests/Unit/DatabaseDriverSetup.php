<?php

namespace Unit;

use Lucent\Database;
use Lucent\Facades\Log;
use Lucent\Filesystem\File;
use Lucent\Filesystem\Folder;
use PHPUnit\Framework\TestCase;
use Lucent\Application;

class DatabaseDriverSetup extends TestCase
{
    protected static function setupDatabase(string $driver, array $config): void
    {
        $storage = new Folder("/storage");

        if (!$storage->exists()) {
            $storage->create(0755);
        }

        $content = "DB_DRIVER={$driver}\n";

        foreach ($config as $key => $value) {
            $content .= "{$key}={$value}\n";
        }

        $env = new File(DIRECTORY_SEPARATOR . ".env");

        if (!$env->exists() || !$env->write($content)) {
            Log::channel("phpunit")->critical("DatabaseDriverSetup] Failed to create .env file");
            throw new \Exception("[DatabaseDriverSetup] Failed to create .env file");
        }

        $app = Application::getInstance();
        $app->loadEnv();

        //Recreate our new database singleton
        Database::reset();

        //Drop all our tables, disable FK checks to ensure we can drop them in any order.
        Database::disabling(LUCENT_DB_FOREIGN_KEY_CHECKS, function () {
            $tables = Database\Schema::list();

            //Drop all our tables
            foreach ($tables as $table) {
                if (!$table->drop()) {
                    Log::channel("phpunit")->critical("[DatabaseDriverSetup] Failed to drop all tables: Table {$table->name} failed to drop.");
                    throw new \Exception("Failed to drop all tables: Table {$table->name} failed to drop.");
                }
            }
        });

        Log::channel("phpunit")->info("[DatabaseDriverSetup] Switched driver to " . $driver);
    }
}