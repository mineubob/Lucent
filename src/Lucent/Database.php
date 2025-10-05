<?php

namespace Lucent;

use Exception;
use Lucent\Database\DatabaseInterface;
use Lucent\Database\Drivers\PDODriver;
use Lucent\Facades\App;
use Lucent\Facades\Log;

class Database
{
    private static ?DatabaseInterface $instance = null;

    public static function getInstance(): DatabaseInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $driver = Application::getInstance()->databaseDrivers[App::env("DB_DRIVER")] ?? null;

        if(!$driver) {
            throw new Exception("Unknown database driver provided");
        }

        self::$instance = new $driver();

        return self::$instance;
    }

    public static function createTable(string $table, array $columns): string
    {
        return self::getInstance()->createTable($table, $columns);
    }

    public static function statement(string $query, array $args = []): bool
    {
        return self::getInstance()->statement($query, $args);
    }

    public static function insert(string $query, array $args = []): bool
    {
        return self::getInstance()->insert($query, $args);
    }

    public static function update(string $query, array $args = []): bool
    {
        return self::getInstance()->update($query, $args);
    }

    public static function delete(string $query, array $args = []): bool
    {
        return self::getInstance()->delete($query, $args);
    }

    public static function select($query,bool $fetchAll = true, array $args = []): ?array
    {
        return self::getInstance()->select($query,$fetchAll, $args);
    }

    public static function transaction(callable $callback): bool
    {
        return self::getInstance()->transaction($callback);
    }

    public static function disabling(string $feature, callable $callback): mixed
    {
        $driver = self::getInstance()->getDriverName();
        $featureCommands = PDODriver::$map[$driver]["functions"][$feature] ?? null;

        // If feature not supported for this driver, just run callback
        if ($featureCommands === null) {
            Log::channel("db")->warning("Feature '{$feature}' not supported for driver '{$driver}'");
            return $callback();
        }

        try {
            self::getInstance()->statement($featureCommands["disable"]);
            return $callback();
        } finally {
            self::getInstance()->statement($featureCommands["enable"]);
        }
    }

    public static function getDriver() : DatabaseInterface
    {
        return self::getInstance();
    }

    public static function reset() : void
    {
        self::$instance = null;
    }
}