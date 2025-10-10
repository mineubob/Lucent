<?php

namespace Unit;

use Lucent\Database;
use Lucent\Facades\Log;
use Lucent\Filesystem\File;
use Lucent\Filesystem\Folder;
use Lucent\Logging\Channel;
use Lucent\Logging\Drivers\CliDriver;
use Lucent\Logging\Drivers\FileDriver;
use Lucent\Logging\Drivers\TeeDriver;
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
            throw new \Exception("[DatabaseDriverSetup] Failed to create .env file");
        }

        $app = Application::getInstance();
        $app->loadEnv();

        $phpunitLog = new Channel("phpunit", new TeeDriver(new CliDriver(), new FileDriver("phpunit.log")), false);
        $app->addLoggingChannel("phpunit", $phpunitLog);

        $dbLog = new Channel("db", new TeeDriver(new CliDriver(), new FileDriver("db.log")));
        $app->addLoggingChannel("db", $dbLog);

        $fsLog = new Channel("fs", new TeeDriver(new CliDriver(), new FileDriver("fs.log")));
        $app->addLoggingChannel("fs", $fsLog);

        //Recreate our new database singleton
        Database::reset();

        //Drop all our tables, disable FK checks to ensure we can drop them in any order.
        $failedTables = Database::disabling(LUCENT_DB_FOREIGN_KEY_CHECKS, function () {
            $tables = Database\Schema::list();
            $failedTables = [];

            //Drop all our tables
            foreach ($tables as $table) {
                if (!$table->drop()) {
                    $failedTables[] = $table->name;
                }
            }

            return $failedTables;
        });
        if (count($failedTables) >= 1) {
            $table_names = implode(', ', $failedTables);
            throw new \Exception("Failed to drop all tables: Tables {$table_names} failed to drop.");
        }

        Log::channel("db")->info("Switched driver to " . $driver);
    }
}