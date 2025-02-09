<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 7/11/2023
 */

namespace Lucent\Database;

use Lucent\Database;
use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Facades\Log;
use ReflectionClass;


class Migration
{
    private array $types;
    private ?string $primaryKey;
    private array $callbacks = [];
    private array $preservedData = [];
    private array $columnMap = [];

    public function __construct()
    {
        $this->types[1] = "binary";
        $this->types[2] = "tinyint";
        $this->types[3] = "decimal";
        $this->types[4] = "int";
        $this->types[5] = "json";
        $this->types[6] = "timestamp";
        $this->types[7] = "enum";
        $this->types[8] = "date";
        $this->types[10] = "text";
        $this->types[12] = "varchar";
    }

    public function make($class): bool
    {
        $reflection = new ReflectionClass($class);
        $tableName = $reflection->getShortName();

        // Backup existing data if table exists
        $this->backupExistingData($tableName);

        // Get the new column structure
        $columns = $this->analyzeNewStructure($reflection);

        // Drop the existing table
        $query = "DROP TABLE IF EXISTS " . $tableName;
        if (!Database::query($query)) {
            Log::error("Failed to drop table {$tableName}");
            return false;
        }

        // Create new table
        $query = $this->buildCreateTableQuery($tableName, $columns);
        if (!Database::query($query)) {
            Log::channel("db")->critical("Failed to create table {$tableName}");
            return false;
        }

        // Restore data if we have any
        if (!empty($this->preservedData)) {
            $this->restoreData($tableName);
        }

        return true;
    }

    private function backupExistingData(string $tableName): void
    {
        try {
            // Check if table exists
            $result = Database::query("SHOW TABLES LIKE '{$tableName}'");
            if ($result && $result->num_rows > 0) {
                Log::channel("db")->info("Backing up data from {$tableName}");
                $data = Database::fetchAll("SELECT * FROM {$tableName}");
                if (!empty($data)) {
                    $this->preservedData = $data;
                    Log::channel("db")->info("Backed up " . count($data) . " rows from {$tableName}");
                }
            }
        } catch (\Exception $e) {
            Log::channel("db")->critical("Could not backup data from {$tableName}: " . $e->getMessage());
        }
    }

    private function analyzeNewStructure(ReflectionClass $reflection): array
    {
        $columns = [];
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DatabaseColumn::class);
            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $instance->setName($property->name);
                $columns[] = $instance->getColumn();
            }
        }
        return $columns;
    }

    private function buildCreateTableQuery(string $tableName, array $columns): string
    {
        $query = "CREATE TABLE `{$tableName}` (";

        foreach ($columns as $column) {
            $query .= $this->buildColumnString($column);
        }

        foreach ($this->callbacks as $callback) {
            $query .= $callback . ",";
        }

        $query .= "PRIMARY KEY (`" . $this->primaryKey . "`)";
        $query .= ");";

        return $query;
    }

    private function restoreData(string $tableName): void
    {
        if (empty($this->preservedData)) {
            return;
        }

        Log::channel("db")->info("Attempting to restore data to {$tableName}");

        foreach ($this->preservedData as $row) {
            $columns = array_keys($row);
            $values = array_values($row);

            // Escape values
            $values = array_map(function($value) {
                if ($value === null) {
                    return 'NULL';
                }
                return "'" . addslashes($value) . "'";
            }, $values);

            $query = sprintf(
                "INSERT INTO %s (`%s`) VALUES (%s)",
                $tableName,
                implode('`, `', $columns),
                implode(', ', $values)
            );

            try {
                if (!Database::query($query)) {
                    Log::channel("db")->critical("Failed to restore row in {$tableName}");
                }
            } catch (\Exception $e) {
                Log::channel("db")->critical("Error restoring data: " . $e->getMessage());
            }
        }

        Log::channel("db")->info("Completed data restoration for {$tableName}");
    }

    private function buildColumnString(array $column): string
    {
        $string = match ($column["TYPE"]) {
            LUCENT_DB_DECIMAL => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]] . "(20,2)",
            LUCENT_DB_JSON, LUCENT_DB_TIMESTAMP, LUCENT_DB_DATE => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]],
            LUCENT_DB_ENUM => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]] . $this->buildValues($column["VALUES"]),
            default => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]] . "(" . $column["LENGTH"] . ")",
        };

        if (!$column["ALLOW_NULL"]) {
            $string .= " NOT NULL";
        }

        if ($column["PRIMARY_KEY"] === true) {
            $this->primaryKey = $column["NAME"];
        }

        if ($column["AUTO_INCREMENT"]) {
            $string .= " AUTO_INCREMENT";
        }

        if ($column["DEFAULT"] !== null) {
            if ($column["DEFAULT"] !== LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP) {
                $string .= " DEFAULT '" . $column["DEFAULT"] . "'";
            } else {
                $string .= " DEFAULT " . $column["DEFAULT"];
            }
        }

        if ($column["ON_UPDATE"] !== null) {
            $string .= " ON UPDATE " . $column["ON_UPDATE"];
        }

        if ($column["UNIQUE"] !== null) {
            $callback = "UNIQUE (" . $column["NAME"] . ")";
            array_push($this->callbacks, $callback);
        }

        if ($column["UNIQUE_KEY_TO"] !== null) {
            $callback = "UNIQUE KEY unique_" . $column["NAME"] . "_to_" . $column["UNIQUE_KEY_TO"] . " (" . $column["UNIQUE_KEY_TO"] . "," . $column["NAME"] . ")";
            array_push($this->callbacks, $callback);
        }

        if ($column["REFERENCES"] !== null) {
            $callback = "FOREIGN KEY (" . $column["NAME"] . ") REFERENCES " . $column["REFERENCES"];
            array_push($this->callbacks, $callback);
        }

        return $string . ",";
    }

    private function buildValues(array $values): string
    {
        $output = "(";
        foreach ($values as $value) {
            $output .= "'" . $value . "',";
        }
        $output = rtrim($output, ",");
        return $output . ")";
    }
}