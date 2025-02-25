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

    public static function query(string $query): bool|array
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->query($query);
    }

    public static function fetch(string $query): array
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->fetch($query);
    }

    public static function fetchAll(string $query): array
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->fetchAll($query);
    }

    public static function createTable(string $table, array $columns): string
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->createTable($table, $columns);
    }

    public static function tableExists(string $table): bool
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->tableExists($table);
    }

    public static function lastInsertId(): string|int
    {
        if (self::$instance === null) {
            self::initialize();
        }
        return self::$instance->lastInsertId();
    }
}