<?php

namespace Lucent\Facades;

use Lucent\Database;

class Schema
{

    public static function hasTable($name) : bool
    {
        if (Database::getDriver() === null) {
            Database::initialize();
        }
        return Database::getDriver()->hasTable($name);
    }

    public static function hasColumn(string $table,string|array $column) : bool
    {
        if (Database::getDriver() === null) {
            Database::initialize();
        }
        return Database::getDriver()->hasColumn($table, $column);
    }

}