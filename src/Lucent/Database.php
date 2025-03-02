<?php

namespace Lucent;

use Lucent\Database\DatabaseInterface;
use Lucent\Database\Drivers\MySQLDriver;
use Lucent\Database\Drivers\SQLiteDriver;
use Lucent\Facades\App;

class Database
{
    private static ?DatabaseInterface $instance = null;

    public static function initialize(): void
    {
        $driver = App::env("DB_DRIVER", "mysql");

        self::$instance = match ($driver) {
            "sqlite" => new SQLiteDriver(),
            default => new MySQLDriver(),
        };
    }

    public static function createTable(string $table, array $columns): string
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->createTable($table, $columns);
    }

    public static function statement(string $query): bool
    {
        if (self::$instance === null) {
            self::initialize();
        }

        return self::$instance->statement($query);
    }

    public static function insert(string $query): bool
    {
        if (self::$instance === null) {
            self::initialize();
        }

        return self::$instance->insert($query);
    }

    public static function update(string $query): bool
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->update($query);
    }

    public static function delete(string $query): bool
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->delete($query);
    }

    public static function select($query,bool $fetchAll = true): ?array
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->select($query,$fetchAll);
    }

    public static function transaction(callable $callback): bool
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->transaction($callback);
    }

    public static function getDriver() : DatabaseInterface
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance;
    }
}