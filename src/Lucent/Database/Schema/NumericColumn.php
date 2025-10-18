<?php

namespace Lucent\Database\Schema;

class NumericColumn extends Column
{
    private bool $autoIncrement;
    private bool $unsigned;

    public function __construct($name, $typer, $driver, $table, $castType = null)
    {
        parent::__construct($name, $typer, $driver, $table, $castType);
        $this->autoIncrement = false;
        $this->unsigned = false;
    }

    public function autoIncrement(): NumericColumn
    {
        $this->autoIncrement = true;
        return $this;
    }

    public function unsigned(): NumericColumn
    {
        $this->unsigned = true;
        return $this;
    }

    public function toSql(): string
    {
        $precision = '';

        // Special handling for DECIMAL - it needs (precision, scale)
        if (strtolower($this->type) === 'decimal' && $this->driver !== "sqlite") {
            $precision = '(20,2)';
        }

        $this->length = 0; // Always 0 for numeric types

        // Get the base column string from parent
        $base = parent::toSql();

        // Add numeric-specific modifiers
        $unsigned = '';
        $ai = '';

        if ($this->driver === "mysql" && $this->unsigned) {
            $unsigned = ' UNSIGNED';
        }

        if ($this->autoIncrement) {
            if ($this->driver === "mysql") {
                $ai = ' AUTO_INCREMENT';
            } else if ($this->driver === "sqlite") {
                $ai = ' AUTOINCREMENT';
            }
        }

        // Insert modifiers: type + precision + unsigned + auto_increment
        return str_replace(
            " $this->type ",
            " $this->type $precision $unsigned",
            $base
        ) . $ai;
    }
}