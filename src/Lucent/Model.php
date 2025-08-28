<?php

namespace Lucent;

use Error;
use Exception;
use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Facades\Log;
use ReflectionClass;

class Model
{

    public protected(set) Dataset $dataset;

    public function hydrate(Dataset $dataset): void
    {
        $this->dataset = $dataset;

        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties() as $property) {
            $propertyName = $property->getName();

            // Skip the dataset property itself
            if ($propertyName === 'dataset') {
                continue;
            }

            $value = match ($property->getType()->getName()) {
                'string' => (string) $dataset->get($propertyName),
                'int', 'integer' => (int) $dataset->integer($propertyName),
                'float', 'double' => (float) $dataset->get($propertyName),
                'bool', 'boolean' => (bool) $dataset->get($propertyName),
                'array' => (array) $dataset->get($propertyName),
                'null' => null,
                default => $dataset->get($propertyName)
            };

            /** @noinspection PhpExpressionResultUnusedInspection */
            $property->setAccessible(true);
            $property->setValue($this, $value);
        }
    }

    public static function getSqlString(mixed $value): string
    {
        // Handle NULL values.
        if (!isset($value)) {
            return "NULL";
        }

        // formatting for different types
        $type = gettype($value);

        if ($type === "boolean") {
            return $value ? "1" : "0";
        } else if ($type === "integer" || $type === "double") {
            return strval($value);
        } else {
            // Escape single quotes in string values
            $escaped = str_replace("'", "''", $value);
            return "'" . $escaped . "'";
        }
    }

    public function delete($propertyName = "id"): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        if ($parent->getName() !== Model::class) {
            $propertyReflection = $reflection->getProperty($propertyName);
            $propertyReflection->setAccessible(true);
            $value = $propertyReflection->getValue($this);

            // FIXED: Correct table names - delete from child first, then parent
            $childQuery = "DELETE FROM " . $reflection->getShortName() . " WHERE " . $propertyName . "='" . $this->getSqlString($value) . "'";
            $parentQuery = "DELETE FROM " . $parent->getShortName() . " WHERE " . $propertyName . "='" . $this->getSqlString($value) . "'";

            try {
                return Database::transaction(function () use ($childQuery, $parentQuery) {
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

            $query = "DELETE FROM " . $reflection->getShortName() . " WHERE " . $propertyName . "='" . $this->getSqlString($value) . "'";

            if (Database::delete($query)) {
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
            return Database::transaction(function () use ($reflection, $parent, $parentPK, $parentProperties) {
                // Insert into parent table first
                $parentTable = $parent->getShortName();
                $parentQuery = "INSERT INTO {$parentTable}" . $this->buildInsertQueryString($parentProperties);

                Log::channel("db")->info("Parent query: " . $parentQuery);
                $result = Database::insert($parentQuery);

                if (!$result) {
                    Log::channel("db")->error("Failed to create parent model: " . $parentQuery);
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
                $childQuery = "INSERT INTO {$tableName}" . $this->buildInsertQueryString($childProps);

                Log::channel("db")->info("Child query: " . $childQuery);
                $result = Database::insert($childQuery);

                if (!$result) {
                    Log::channel("db")->error("Failed to create child model: " . $childQuery);
                    // The transaction will be rolled back automatically
                    return false;
                }

                // The transaction will be committed automatically
                return true;
            });
        } else {
            // Standard model creation (no transaction needed)
            Log::channel("db")->info("Process code for non inherited model");
            // No inheritance - just collect properties from this model
            $properties = $this->getProperties($reflection->getProperties(), $reflection->getName());

            // Insert into the current model's table
            $tableName = $reflection->getShortName();
            $query = "INSERT INTO {$tableName}" . $this->buildInsertQueryString($properties);

            Log::channel("db")->info("Query: " . $query);
            $result = Database::insert($query);

            if (!$result) {
                Log::channel("db")->error("Failed to create model: " . $query);
                return false;
            }

            $pk = Model::getDatabasePrimaryKey($reflection);

            // Get the last inserted ID
            $lastId = Database::getDriver()->lastInsertId();

            // Set the ID
            $reflection->getProperty($pk["NAME"])->setValue($this, $lastId);

            return true;
        }
    }

    public function buildInsertQueryString(array $properties): string
    {
        if (empty($properties)) {
            return " DEFAULT VALUES";  // SQLite syntax for inserting default values
        }

        $columns = " (";
        $values = " VALUES (";

        foreach ($properties as $key => $value) {

            Log::channel("db")->info("Processing column: " . $key . " with value: " . $value);
            $columns .= "`" . $key . "`, ";
            $values .= $this->getSqlString($value) . ", ";
        }

        $columns = rtrim($columns, ", ") . ")";
        $values = rtrim($values, ", ") . ")";

        return $columns . $values;
    }
    public function getProperties(array $properties, string $class): array
    {
        $output = [];
        foreach ($properties as $property) {

            $attributes = $property->getAttributes(DatabaseColumn::class);
            $declaringClass = $property->getDeclaringClass();

            if (count($attributes) > 0 && $declaringClass->getName() === $class) {

                if (!$property->isInitialized($this)) {
                    $value = null;
                } else {
                    $value = $property->getValue($this);
                }

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
                if (!$property["PRIMARY_KEY"]) {
                    $propName = $property["NAME"];
                    $parentProp = $parent->getProperty($propName);
                    $parentProp->setAccessible(true);

                    if (!$parentProp->isInitialized($this)) {
                        $value = null;
                    } else {
                        $value = $parentProp->getValue($this);
                    }

                    $parentUpdates[] = $propName . " = " . $this->getSqlString($value);
                }
            }

            $parentQuery .= implode(", ", $parentUpdates);
            $parentQuery .= " WHERE {$identifier} = ";
            $parentQuery .= $this->getSqlString($idValue);

            Log::channel("db")->info("Query: " . $parentQuery);

            $childUpdates = [];
            $childQuery = "UPDATE {$reflection->getShortName()} SET ";

            foreach (Model::getDatabaseProperties($reflection->getName()) as $property) {
                if (!$property["PRIMARY_KEY"]) {
                    $propName = $property["NAME"];
                    $reflProp = $reflection->getProperty($propName);
                    $reflProp->setAccessible(true);

                    if (!$reflProp->isInitialized($this)) {
                        $value = null;
                    } else {
                        $value = $reflProp->getValue($this);
                    }

                    $childUpdates[] = $propName . " = " . $this->getSqlString($value);
                }
            }

            if (empty($childUpdates)) {
                // If no child updates, just update parent
                return Database::update($parentQuery);
            }

            $childQuery .= implode(", ", $childUpdates);
            $childQuery .= " WHERE {$identifier} = ";
            $childQuery .= $this->getSqlString($idValue);

            Log::channel("db")->info("Query: " . $childQuery);

            return Database::transaction(function () use ($childQuery, $parentQuery) {
                Database::update($parentQuery);
                return Database::update($childQuery);
            });
        }

        // Non-extended model handling
        Log::channel("db")->info("Saving standard model (non extended)");

        $query = "UPDATE " . $reflection->getShortName() . " SET ";
        $updates = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DatabaseColumn::class);

            if (count($attributes) > 0) {
                $property->setAccessible(true);


                if (!$property->isInitialized($this)) {
                    $value = null;
                } else {
                    $value = $property->getValue($this);
                }

                if ($value !== null) {
                    $skip = false;

                    foreach ($attributes as $attribute) {
                        $instance = $attribute->newInstance();
                        $skip = $instance->shouldSkip();
                    }

                    if (!$skip && $property->getName() !== $identifier) {
                        // Format the value based on its type
                        $propName = $property->getName();

                        $updates[] = $propName . " = " . $this->getSqlString($value);
                    }
                }
            }
        }

        if (empty($updates)) {
            return true; // No updates needed
        }

        $query .= implode(", ", $updates);
        $query .= " WHERE {$identifier} = ";
        $query .= $this->getSqlString($idValue);

        Log::channel("db")->info("Query: " . $query);

        try {
            return Database::update($query);
        } catch (Exception $e) {
            Log::channel("db")->error("Failed to save model with query " . $query . ". Error: " . $e->getMessage());
            return false;
        }
    }

    public static function hasDatabaseProperty(string $class, string $name): bool
    {
        return array_key_exists($name, Model::getDatabaseProperties($class));
    }

    public static function getDatabaseProperties(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {

            if ($property->getDeclaringClass()->getName() === $reflection->getName()) {
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

    public static function where(string $column, string $value): ModelCollection
    {
        return new ModelCollection(static::class)->where($column, $value);
    }
    public static function orWhere(string $column, string $value): ModelCollection
    {
        return new ModelCollection(static::class)->orWhere($column, $value);
    }

    public static function like(string $column, string $value): ModelCollection
    {
        return new ModelCollection(static::class)->like($column, $value);
    }
    public static function orLike(string $column, string $value): ModelCollection
    {
        return new ModelCollection(static::class)->orLike($column, $value);
    }

    public static function limit(int $count): ModelCollection
    {
        return new ModelCollection(static::class)->limit($count);
    }

    public static function offset(int $offset): ModelCollection
    {
        return new ModelCollection(static::class)->offset($offset);
    }

    public static function orderBy(string $column, string $direction = "ASC"): ModelCollection
    {
        return new ModelCollection(static::class)->orderBy($column, $direction);
    }

    public static function count(): int
    {
        return new ModelCollection(static::class)->count();
    }

    public static function sum(string $column): float
    {
        return new ModelCollection(static::class)->sum($column);
    }

    public static function collection(): ModelCollection
    {
        return new ModelCollection(static::class);
    }

    public static function get(): array
    {
        return new ModelCollection(static::class)->get();
    }

    public static function getFirst(): self|null
    {
        return new ModelCollection(static::class)->getFirst();
    }

    public static function in(string $column, array $values, string $operator = "AND"): ModelCollection
    {
        return new ModelCollection(static::class)->in($column, $values, $operator);
    }

    public static function compare(string $column, string $logicalOperator, string $value): ModelCollection
    {
        return new ModelCollection(static::class)->compare($column, $logicalOperator, $value);
    }


}