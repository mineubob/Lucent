<?php

namespace Lucent;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Database\Migration;
use Lucent\Facades\Log;
use ReflectionClass;

class Model
{

    private int $autoId = -1;
    protected Dataset $dataset;

    public function delete($property = "id"): bool
    {

        $query = "DELETE FROM ".$this->getSimpleClassName()." WHERE ".$property."=";

        $reflection = new ReflectionClass($this);

        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $value = $property->getValue($this);

        $query .= "'".$value."'";

        if(Database::query($query)){
            return true;
        }else{
            Log::channel("db")->error("Failed to delete model with query ".$query);
            return false;
        }
    }

    public function create(): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        if ($parent->getName() !== Model::class) {
            // This is a child model, handle parent first
            $parentPK = Migration::getPrimaryKeyFromModel($parent);
            $parentProperties = $this->getProperties($parent->getProperties());

            // Insert into parent table first
            $parentTable = $parent->getShortName();
            $parentQuery = "INSERT INTO {$parentTable}" . $this->buildQueryString($parentProperties);

            Log::channel("db")->info("Parent query: " . $parentQuery);
            $result = Database::query($parentQuery);

            if (!$result) {
                Log::channel("db")->error("Failed to create parent model: " . $parentQuery);
                return false;
            }

            // Get the last inserted ID
            $lastId = Database::lastInsertId();

            // Get properties for the child model
            $childProps = $this->getProperties($reflection->getProperties());

            // Add the parent's primary key to the child properties
            $childProps[$parentPK["NAME"]] = $lastId;

            // Insert into the current model's table
            $tableName = $this->getSimpleClassName();
            $childQuery = "INSERT INTO {$tableName}" . $this->buildQueryString($childProps);

            Log::channel("db")->info("Child query: " . $childQuery);
            $result = Database::query($childQuery);

            if (!$result) {
                Log::channel("db")->error("Failed to create child model: " . $childQuery);
                return false;
            }

            return true;
        } else {
            // No inheritance - just collect properties from this model
            $properties = $this->getProperties($reflection->getProperties());

            // Insert into the current model's table
            $tableName = $this->getSimpleClassName();
            $query = "INSERT INTO {$tableName}" . $this->buildQueryString($properties);

            Log::channel("db")->info("Query: " . $query);
            $result = Database::query($query);

            if (!$result) {
                Log::channel("db")->error("Failed to create model: " . $query);
                return false;
            }

            return true;
        }
    }
    public function buildQueryString(array $properties): string
    {
        if (empty($properties)) {
            return " DEFAULT VALUES";  // SQLite syntax for inserting default values
        }

        $query = "";
        $columns = " (";
        $values = " VALUES (";

        foreach ($properties as $key => $value) {
            $columns .= "`" . $key . "`, ";

            // Handle NULL values and formatting for different types
            if ($value === null) {
                $values .= "NULL, ";
            } else if (is_bool($value)) {
                // Convert boolean to integer for SQLite
                $values .= ($value ? "1" : "0") . ", ";
            } else if (is_numeric($value)) {
                $values .= $value . ", ";
            } else {
                // Escape single quotes in string values
                $escaped = str_replace("'", "''", $value);
                $values .= "'" . $escaped . "', ";
            }
        }

        $columns = rtrim($columns, ", ") . ")";
        $values = rtrim($values, ", ") . ")";

        return $columns . $values;
    }
    public function getProperties(array $properties) : array
    {
        $output = [];
        foreach ($properties as $property) {

            $attributes = $property->getAttributes(DatabaseColumn::class);

            if (count($attributes) > 0) {

                $value = $property->getValue($this);
                if ($value !== null) {
                    $skip = false;

                    foreach ($attributes as $attribute) {
                        $instance = $attribute->newInstance();
                        $skip = $instance->shouldSkip();
                    }

                    if (!$skip) {
                        $output[$property->name] = $property->getValue($this);
                    }

                }
            }
        }

        return $output;
    }

    public function save(string $identifier = "id"): bool
    {
        $reflection = new ReflectionClass($this);
        $idProperty = $reflection->getProperty($identifier);
        $idProperty->setAccessible(true);
        $idValue = $idProperty->getValue($this);

        // If no ID is set, treat as a create operation
        if ($idValue === null) {
            return $this->create();
        }

        $query = "UPDATE " . $this->getSimpleClassName() . " SET ";
        $updates = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DatabaseColumn::class);

            if (count($attributes) > 0) {
                $property->setAccessible(true);
                $value = $property->getValue($this);

                if ($value !== null) {
                    $skip = false;

                    foreach ($attributes as $attribute) {
                        $instance = $attribute->newInstance();
                        $skip = $instance->shouldSkip();
                    }

                    if (!$skip && $property->getName() !== $identifier) {
                        $updates[] = $property->getName() . "='" . $value . "'";
                    }
                }
            }
        }

        if (empty($updates)) {
            return true; // No updates needed
        }

        $query .= implode(", ", $updates);
        $query .= " WHERE {$identifier}='{$idValue}'";

        try {
            return Database::query($query);
        } catch (\Exception $e) {
            Log::channel("db")->error("Failed to save model with query " . $query . ". Error: " . $e->getMessage());
            return false;
        }
    }

    public static function where(string $column, string $value): ModelCollection
    {
        $collection = new ModelCollection(static::class);
        return $collection->where($column,$value);
    }

    public static function limit(int $count) : ModelCollection
    {
        $collection = new ModelCollection(static::class);
        return $collection->limit($count);

    }

    public static function offset(int $count) : ModelCollection
    {
        $collection = new ModelCollection(static::class);
        return $collection->offset($count);

    }

    private function getSimpleClassName(): string
    {
        $array = explode("\\", static::class);
        return end($array);
    }

    public function getAutoId() :int{
        return $this->autoId;
    }

}