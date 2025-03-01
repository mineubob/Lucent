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

        if ($parent->getName() !== Model::class) {
            $propertyReflection = $reflection->getProperty($propertyName);
            $propertyReflection->setAccessible(true);
            $value = $propertyReflection->getValue($this);

            // FIXED: Correct table names - delete from child first, then parent
            $childQuery = "DELETE FROM " . $reflection->getShortName() . " WHERE " . $propertyName . "='" . $value . "'";
            $parentQuery = "DELETE FROM " . $parent->getShortName() . " WHERE " . $propertyName . "='" . $value . "'";

            try {
                return Database::transaction(function() use ($childQuery, $parentQuery) {
                    $childResult = Database::delete($childQuery);
                    if (!$childResult) {
                        return false;
                    }
                    return Database::delete($parentQuery);
                });
            } catch (Exception $e) {
                // Rollback on failure
                Log::channel("db")->error("Delete failed: " . $e->getMessage());
                return false;
            }
        } else {
            // Standard model deletion (unchanged)
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $value = $property->getValue($this);

            $query = "DELETE FROM " . $reflection->getShortName() . " WHERE " . $propertyName . "='" . $value . "'";

            if(Database::delete($query)){
                return true;
            } else {
                Log::channel("db")->error("Failed to delete model with query " . $query);
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
            $parentProperties = $this->getProperties($parent->getProperties(), $parent->getName());

            // Start a transaction
            return Database::transaction(function() use ($reflection, $parent, $parentPK, $parentProperties) {
                // Insert into parent table first
                $parentTable = $parent->getShortName();
                $parentQuery = "INSERT INTO {$parentTable}" . $this->buildQueryString($parentProperties);

                Log::channel("phpunit")->info("Parent query: " . $parentQuery);
                $result = Database::insert($parentQuery);

                if (!$result) {
                    Log::channel("phpunit")->error("Failed to create parent model: " . $parentQuery);
                    // The transaction will be rolled back automatically
                    return false;
                }

                if ($parentPK["AUTO_INCREMENT"] === true) {
                    // Get the last inserted ID
                    $lastId = Database::getDriver()->lastInsertId();

                    // Set the ID
                    $parent->getProperty($parentPK["NAME"])->setValue($this, $lastId);
                } else {
                    $lastId = $reflection->getProperty($parentPK["NAME"])->getValue($this);
                }

                // Get properties for the child model
                $childProps = $this->getProperties($reflection->getProperties(), $reflection->getName());

                // Add the parent's primary key to the child properties
                $childProps[$parentPK["NAME"]] = $lastId;

                // Insert into the current model's table
                $tableName = $reflection->getShortName();
                $childQuery = "INSERT INTO {$tableName}" . $this->buildQueryString($childProps);

                Log::channel("phpunit")->info("Child query: " . $childQuery);
                $result = Database::insert($childQuery);

                if (!$result) {
                    Log::channel("phpunit")->error("Failed to create child model: " . $childQuery);
                    // The transaction will be rolled back automatically
                    return false;
                }

                // The transaction will be committed automatically
                return true;
            });
        } else {
            // Standard model creation (no transaction needed)
            Log::channel("phpunit")->info("Process code for non inherited model");
            // No inheritance - just collect properties from this model
            $properties = $this->getProperties($reflection->getProperties(), $reflection->getName());

            // Insert into the current model's table
            $tableName = $reflection->getShortName();
            $query = "INSERT INTO {$tableName}" . $this->buildQueryString($properties);

            Log::channel("phpunit")->info("Query: " . $query);
            $result = Database::insert($query);

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
            // Extended model handling
            $parentQuery = "UPDATE {$parent->getShortName()} SET ";
            $parentUpdates = [];

            foreach (Model::getDatabaseProperties($parent->getName()) as $property) {
                if(!$property["PRIMARY_KEY"]) {
                    $propName = $property["NAME"];
                    $parentProp = $parent->getProperty($propName);
                    $parentProp->setAccessible(true);
                    $value = $parentProp->getValue($this);

                    // Format the value based on its type
                    if ($value === null) {
                        $parentUpdates[] = $propName . "=NULL";
                    } else if (is_bool($value) || (isset($property["TYPE"]) && strpos($property["TYPE"], 'tinyint') !== false)) {
                        $parentUpdates[] = $propName . "=" . ($value ? "1" : "0");
                    } else if (is_numeric($value)) {
                        $parentUpdates[] = $propName . "=" . $value;
                    } else {
                        $parentUpdates[] = $propName . "='" . addslashes($value) . "'";
                    }
                }
            }

            $parentQuery .= implode(", ", $parentUpdates);
            $parentQuery .= " WHERE {$identifier}=";
            $parentQuery .= is_numeric($idValue) ? $idValue : "'" . $idValue . "'";

            Log::channel("phpunit")->info("Query: " . $parentQuery);

            $childUpdates = [];
            $childQuery = "UPDATE {$reflection->getShortName()} SET ";

            foreach (Model::getDatabaseProperties($reflection->getName()) as $property) {
                if(!$property["PRIMARY_KEY"]) {
                    $propName = $property["NAME"];
                    $reflProp = $reflection->getProperty($propName);
                    $reflProp->setAccessible(true);
                    $value = $reflProp->getValue($this);

                    // Format the value based on its type
                    if ($value === null) {
                        $childUpdates[] = $propName . "=NULL";
                    } else if (is_bool($value) || (isset($property["TYPE"]) && strpos($property["TYPE"], 'tinyint') !== false)) {
                        $childUpdates[] = $propName . "=" . ($value ? "1" : "0");
                    } else if (is_numeric($value)) {
                        $childUpdates[] = $propName . "=" . $value;
                    } else {
                        $childUpdates[] = $propName . "='" . addslashes($value) . "'";
                    }
                }
            }

            if (empty($childUpdates)) {
                // If no child updates, just update parent
                return Database::update($parentQuery);
            }

            $childQuery .= implode(", ", $childUpdates);
            $childQuery .= " WHERE {$identifier}=";
            $childQuery .= is_numeric($idValue) ? $idValue : "'" . $idValue . "'";

            Log::channel("phpunit")->info("Query: " . $childQuery);

            return Database::transaction(function() use ($childQuery, $parentQuery) {
                Database::update($parentQuery);
                return Database::update($childQuery);
            });
        }

        // Non-extended model handling
        Log::channel("phpunit")->info("Saving standard model (non extended)");

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
                        // Format the value based on its type
                        $propName = $property->getName();

                        if (is_bool($value)) {
                            $updates[] = $propName . "=" . ($value ? "1" : "0");
                        } else if (is_numeric($value)) {
                            $updates[] = $propName . "=" . $value;
                        } else {
                            $updates[] = $propName . "='" . addslashes($value) . "'";
                        }
                    }
                }
            }
        }

        if (empty($updates)) {
            return true; // No updates needed
        }

        $query .= implode(", ", $updates);
        $query .= " WHERE {$identifier}=";
        $query .= is_numeric($idValue) ? $idValue : "'" . $idValue . "'";

        Log::channel("phpunit")->info("Query: " . $query);

        try {
            return Database::update($query);
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