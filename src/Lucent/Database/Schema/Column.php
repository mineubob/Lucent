<?php

namespace Lucent\Database\Schema;

use Lucent\Database;
use Lucent\Database\Drivers\PDODriver;
use Lucent\Database\SqlSerializable;
use Lucent\Facades\Log;

class Column implements SqlSerializable
{

    protected string $name;
    protected string $type;
    protected mixed $default;
    protected bool $nullable;
    protected int $length;
    protected bool $primaryKey;
    protected bool $unique;
    protected ?array $references;
    protected array $values;
    protected string $driver;
    protected Table $table;

    protected ?string $castType;

    public function __construct(string $name, string $type, string $driver, Table $table, ?string $castType = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->default = null;
        $this->nullable = false;
        $this->length = 0;
        $this->primaryKey = false;
        $this->unique = false;
        $this->references = null;
        $this->values = [];
        $this->table = $table;

        $this->driver = $driver;
        $this->castType = $castType;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;
        return $this;
    }

    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    public function length(int $length): self
    {
        $this->length = $length;
        return $this;
    }

    public function primaryKey(): self
    {
        $this->primaryKey = true;
        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;
        return $this;
    }

    public function references(string $data): self
    {
        $data = explode('(', $data);
        $this->references = ["table" => $data[0], "column" => substr($data[1], 0, -1)];

        return $this;
    }

    /**
     * @param array<string> $values
     * @return Database\Schema\Column
     */
    public function values(array $values): self
    {
        $this->values = $values;
        return $this;
    }

    public function exists(): bool
    {
        $query = PDODriver::$map[$this->driver]["functions"]["column_exists"];
        $query = str_replace("{table}", $this->table->name, $query);

        return Database::statement($query, [$this->name]);
    }

    //What the fuck is this
    //"UNIQUE_KEY_TO" => null,
    public function toSql(): string
    {
        $length = "";
        $default = "";
        $primaryKey = "";
        $nullable = "";
        $unique = "";
        $references = "";

        // Types that don't accept length specifications in MySQL
        $typesWithoutLength = ['text', 'longtext', 'mediumtext', 'json', 'date', 'timestamp', 'blob'];
        $normalizedType = strtolower($this->type);

        if ($this->driver !== "sqlite" && $this->length > 0 && !in_array($normalizedType, $typesWithoutLength)) {
            $length = "({$this->length})";
        }

        if ($this->default !== null) {
            $defaultValue = $this->default;

            if ($this->castType !== null) {
                settype($defaultValue, $this->castType);
            }

            // Check if we need quotes or not
            if (is_bool($defaultValue) || is_int($defaultValue) || is_float($defaultValue)) {
                $default = " DEFAULT " . (int) $defaultValue;
            } else {
                $default = " DEFAULT '" . $defaultValue . "'";
            }
        }

        if ($this->primaryKey) {
            $primaryKey = " PRIMARY KEY";
        }

        //If this is a PK it will enforce not null regardless
        if (!$this->nullable && !$this->primaryKey) {
            $nullable = " NOT NULL";
        }

        if ($this->unique) {
            $unique = " UNIQUE";
        }

        if ($this->references !== null) {
            $table = $this->references['table'];
            $column = $this->references['column'];
            $references = " REFERENCES {$table}({$column})";
        }

        return "`{$this->name}` {$this->type}{$length}{$default}{$primaryKey}{$nullable}{$unique}{$references}";
    }
}