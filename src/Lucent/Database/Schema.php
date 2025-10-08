<?php

namespace Lucent\Database;

use Lucent\Database;
use Lucent\Database\Schema\Table;

class Schema
{
    /**
     * Create a new table.
     * @param string $name
     * @param ?callable(Table): void $callback
     * @return Database\Schema\Table
     */
    public static function table(string $name, ?callable $callback = null): Table
    {
        $table = new Table($name);
        if($callback) {
            $callback($table);
        }
        return $table;
    }

    /**
     * Get all tables in the database
     *
     * @return Table[]
     */
    public static function list(): array
    {
        $driver = Database::getDriver()->getDriverName();
        $query = Database\Drivers\PDODriver::$map[$driver]["functions"]["list_tables"];
        $result = Database::select($query);

        $tables = [];
        foreach ($result as $row) {
            $tables[] = new Table(current($row));
        }

        return $tables;
    }


}