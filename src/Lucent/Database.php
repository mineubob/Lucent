<?php

namespace Lucent;

use Lucent\Database\DatabaseInterface;
use Lucent\Database\Drivers\MySQLDriver;
use Lucent\Database\Drivers\SQLiteDriver;
use Lucent\Facades\App;

class Database
{
    private static ?DatabaseInterface $instance = null;

    public static function getInstance(): DatabaseInterface
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $driver = App::env("DB_DRIVER", "mysql");

        self::$instance = match ($driver) {
            "sqlite" => new SQLiteDriver(),
            default => new MySQLDriver(),
        };

        return self::$instance;
    }

    public static function createTable(string $table, array $columns): string
    {
        return self::getInstance()->createTable($table, $columns);
    }

    public static function statement(string $query): bool
    {
        return self::getInstance()->statement($query);
    }

    public static function insert(string $query): bool
    {
        return self::getInstance()->insert($query);
    }

    public static function update(string $query): bool
    {
        return self::getInstance()->update($query);
    }

    public static function delete(string $query): bool
    {
        return self::getInstance()->delete($query);
    }

    public static function select($query,bool $fetchAll = true): ?array
    {
        return self::getInstance()->select($query,$fetchAll);
    }

    public static function transaction(callable $callback): bool
    {
        return self::getInstance()->transaction($callback);
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