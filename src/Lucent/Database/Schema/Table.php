<?php

namespace Lucent\Database\Schema;

use Lucent\Database;
use Lucent\Database\Drivers\PDODriver;
use Lucent\Database\SqlSerializable;
use Lucent\Facades\Log;

class Table implements SqlSerializable
{

    private array $columns;
    public private(set) string $name;

    private $driver;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->columns = [];
        $this->driver = Database::getDriver()->getDriverName();
    }

    public function binary(string $name) : Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["binary"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function tinyint(string $name) : NumericColumn
    {
        $column = new NumericColumn($name, PDODriver::$map[$this->driver]["types"]["tinyint"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function decimal(string $name) : NumericColumn
    {
        $column = new NumericColumn($name, PDODriver::$map[$this->driver]["types"]["decimal"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function int(string $name): NumericColumn
    {
        $column = new NumericColumn($name,PDODriver::$map[$this->driver]["types"]["int"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function bigint(string $name): NumericColumn
    {
        $column = new NumericColumn($name,PDODriver::$map[$this->driver]["types"]["bigint"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function json(string $name) : Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["json"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function timestamp(string $name) : Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["timestamp"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function enum(string $name) : Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["enum"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function date(string $name) : Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["date"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function text(string $name) : Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["text"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function varchar(string $name): Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["varchar"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function boolean(string $name): NumericColumn
    {
        $column = new NumericColumn($name, PDODriver::$map[$this->driver]["types"]["bool"],$this->driver, $this,"boolean");
        $this->columns[] = $column;
        return $column;
    }

    public function float(string $name): NumericColumn
    {
        $column = new NumericColumn($name, PDODriver::$map[$this->driver]["types"]["float"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function double(string $name): NumericColumn
    {
        $column = new NumericColumn($name, PDODriver::$map[$this->driver]["types"]["double"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function char(string $name): Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["char"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function longtext(string $name): Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["longtext"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function mediumtext(string $name): Column
    {
        $column = new Column($name, PDODriver::$map[$this->driver]["types"]["mediumtext"],$this->driver,$this);
        $this->columns[] = $column;
        return $column;
    }

    public function column(string $name): Column
    {
        return new Column($name,"undefined",$this->driver,$this);
    }

    public function drop(): void
    {
        // Validate table name against allowed characters
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->name)) {
            Log::channel("db")->error("Attempted to drop table with invalid name: {$this->name}");
            throw new \InvalidArgumentException(
                "Invalid table name '{$this->name}'. Table names must contain only alphanumeric characters and underscores."
            );
        }

        $query = PDODriver::$map[$this->driver]["functions"]["drop_table"];
        $query = str_replace('{table}', $this->name, $query);
        Database::statement($query);
    }

    public function exists() : bool
    {
        $result = Database::select(
            PDODriver::$map[$this->driver]["functions"]["table_exists"],
            false,
            [$this->name]
        );

        return $result && reset($result); // Get first value from result array
    }

    public function toSql(): string
    {
        $columnsSql = implode(', ', array_map(
            fn(SqlSerializable $col) => $col->toSql(),
            $this->columns
        ));

        return "CREATE TABLE IF NOT EXISTS `{$this->name}` ({$columnsSql})";
    }
}