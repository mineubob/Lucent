<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 7/11/2023
 */

namespace Lucent\Database;

use Lucent\Database;
use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Drivers\MySQLDriver;
use Lucent\Database\Drivers\SQLiteDriver;
use Lucent\Facades\App;
use Lucent\Facades\Log;
use ReflectionClass;


class Migration
{
    private ?string $primaryKey;
    private array $callbacks = [];
    private array $preservedData = [];

    private DatabaseInterface $driver;

    public function __construct()
    {
        $this->driver = match(App::env("DB_DRIVER")) {
            "sqlite" => new SQLiteDriver(),
            default => new MySQLDriver()
        };
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
            Log::channel("phpunit")->error("Failed to drop table {$tableName}");
            return false;
        }

        // Create new table using the appropriate driver
        $query = $this->driver->createTable($tableName, $columns);
        if (!Database::query($query)) {
            Log::channel("phpunit")->critical("Failed to create table {$tableName}");
            return false;
        }

        // Restore data if we have any
        if (!empty($this->preservedData)) {
            $this->restoreData($tableName);
        }

        return true;
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


    private function backupExistingData(string $tableName): void
    {
        try {
            // Check if table exists - SQLite compatible version
            $result = Database::query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$tableName]
            );

            if ($result && $result->num_rows > 0) {
                Log::channel("phpunit")->info("Backing up data from {$tableName}");
                $data = Database::fetchAll("SELECT * FROM {$tableName}");
                if (!empty($data)) {
                    $this->preservedData = $data;
                    Log::channel("phpunit")->info("Backed up " . count($data) . " rows from {$tableName}");
                }
            }
        } catch (\Exception $e) {
            Log::channel("phpunit")->critical("Could not backup data from {$tableName}: " . $e->getMessage());
        }
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


}