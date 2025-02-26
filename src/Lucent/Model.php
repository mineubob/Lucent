<?php

namespace Lucent;

use Exception;
use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Facades\Log;
use ReflectionClass;

class Model
{

    protected Dataset $dataset;

    public function delete($propertyName = "id"): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        $query = "DELETE FROM ".$reflection->getShortName()." WHERE ".$propertyName."=";

        if ($parent->getName() !== Model::class) {
            $propertyReflection = $reflection->getProperty($propertyName);
            $propertyReflection->setAccessible(true);
            $value = $propertyReflection->getValue($this);

            try {
                // Manually manage the transaction
                Database::query("BEGIN TRANSACTION");

                // Delete from child table
                $childResult = Database::query("DELETE FROM ".$reflection->getShortName()." WHERE ".$propertyName."='".$value."'");
                if (!$childResult) {
                    throw new Exception("Failed to delete from child table");
                }

                // Delete from parent table
                $parentResult = Database::query("DELETE FROM ".$parent->getShortName()." WHERE ".$propertyName."='".$value."'");
                if (!$parentResult) {
                    throw new Exception("Failed to delete from parent table");
                }

                // Commit if both operations succeed
                return Database::query("COMMIT");
            } catch (Exception $e) {
                // Rollback on failure
                Database::query("ROLLBACK");
                Log::channel("db")->error("Delete failed: " . $e->getMessage());
                return false;
            }
        }else {


            $reflection = new ReflectionClass($this);

            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $value = $property->getValue($this);

            $query .= "'" . $value . "'";

            if(Database::query($query)){
                return true;
            }else{
                Log::channel("db")->error("Failed to delete model with query ".$query);
                return false;
            }
        }
    }

    public function create(): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        if ($parent->getName() !== Model::class) {
            // This is a child model, handle parent first
            $parentPK = Model::getDatabasePrimaryKey($parent);
            $parentProperties = $this->getProperties($parent->getProperties(),$parent->getName());

            // Insert into parent table first
            $parentTable = $parent->getShortName();
            $parentQuery = "INSERT INTO {$parentTable}" . $this->buildQueryString($parentProperties);

            Log::channel("phpunit")->info("Parent query: " . $parentQuery);
            $result = Database::query($parentQuery);

            if (!$result) {
                Log::channel("phpunit")->error("Failed to create parent model: " . $parentQuery);
                return false;
            }

            if($parentPK["AUTO_INCREMENT"] === true){

                // Get the last inserted ID
                $lastId = Database::lastInsertId();
                //Set the ID
                $reflection->getParentClass()->getProperty($parentPK["NAME"])->setValue($this,$lastId);
            }else{
                $lastId = $reflection->getProperty($parentPK["NAME"])->getValue($this);
            }

            // Get properties for the child model
            $childProps = $this->getProperties($reflection->getProperties(),$reflection->getName());

            // Add the parent's primary key to the child properties
            $childProps[$parentPK["NAME"]] = $lastId;

            // Insert into the current model's table
            $tableName = $reflection->getShortName();
            $childQuery = "INSERT INTO {$tableName}" . $this->buildQueryString($childProps);

            Log::channel("phpunit")->info("Child query: " . $childQuery);
            $result = Database::query($childQuery);

            if (!$result) {
                Log::channel("phpunit")->error("Failed to create child model: " . $childQuery);
                return false;
            }

            return true;
        } else {

            Log::channel("phpunit")->info("Process code for non inherited model");
            // No inheritance - just collect properties from this model
            $properties = $this->getProperties($reflection->getProperties(),$reflection->getName());

            // Insert into the current model's table
            $tableName = $reflection->getShortName();
            $query = "INSERT INTO {$tableName}" . $this->buildQueryString($properties);

            Log::channel("phpunit")->info("Query: " . $query);
            $result = Database::query($query);

            if (!$result) {
                Log::channel("phpunit")->error("Failed to create model: " . $query);
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
    public function getProperties(array $properties,string $class) : array
    {
        $output = [];
        foreach ($properties as $property) {

            $attributes = $property->getAttributes(DatabaseColumn::class);
            $declaringClass = $property->getDeclaringClass();

            if (count($attributes) > 0 && $declaringClass->getName() === $class) {

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

        $parent = $reflection->getParentClass();
        $updates = [];

        if ($parent->getName() !== Model::class) {

            $query = "BEGIN TRANSACTION;";

            Database::query($query);

            $query = "UPDATE {$parent->getShortName()} SET ";

            foreach (Model::getDatabaseProperties($parent->getName()) as $property) {
                if(!$property["PRIMARY_KEY"]) {
                    $value = $parent->getProperty($property["NAME"])->getValue($this);
                    $updates[] = $property["NAME"] . "='" . $value . "'";
                }
            }


            $query .= implode(", ", $updates);
            $query .= " WHERE {$identifier}='{$idValue}'";

            Log::channel("phpunit")->info("Query: " . $query);

            if(!Database::query($query)){
                Log::channel("phpunit")->error("Failed to update model with query ".$query);
                return false;
            }

            $updates = [];

            $query = "UPDATE {$reflection->getShortName()} SET ";

            foreach (Model::getDatabaseProperties($reflection->getName()) as $property) {
                if(!$property["PRIMARY_KEY"]) {
                    $value = $reflection->getProperty($property["NAME"])->getValue($this);
                    $updates[] = $property["NAME"] . "='" . $value . "'";
                }
            }

            $query .= implode(", ", $updates);
            $query .= " WHERE {$identifier}='{$idValue}'";

            Log::channel("phpunit")->info("Query: " . $query);

            if(!Database::query($query)){
                Log::channel("phpunit")->error("Failed to update model with query ".$query);
                return false;
            }

            if(Database::query("COMMIT;")){
                return true;
            }else{
                Log::channel("phpunit")->error("Failed to commit model, rollback changes");
                return Database::query("ROLLBACK");
            }

        }

        $query = "UPDATE " . $reflection->getShortName() . " SET ";
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

    public static function hasDatabaseProperty(string $class, string $name) : bool
    {
        return array_key_exists($name,Model::getDatabaseProperties($class));
    }

    public static function getDatabaseProperties(string $class) : array{
        $reflection = new ReflectionClass($class);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {

            if($property->getDeclaringClass()->getName() === $reflection->getName()) {
                $attributes = $property->getAttributes(DatabaseColumn::class);
                foreach ($attributes as $attribute) {
                    $instance = $attribute->newInstance();
                    $instance->setName($property->name);
                    $properties[$property->name] = $instance->column;
                }
            }
        }

        return $properties;
    }

    public static function getDatabasePrimaryKey(ReflectionClass $reflection): ?array
    {
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DatabaseColumn::class);
            foreach ($attributes as $attribute) {
                $instance = $attribute->newInstance();
                $instance->setName($property->name);
                return $instance->column;
            }
        }

        // Return empty string or throw an exception if no primary key found
        return null;
    }


}