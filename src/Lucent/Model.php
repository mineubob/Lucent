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

    public function delete($propertyName = "id"): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        $property = $reflection->getProperty($propertyName);
        $value = $property->getValue($this);

        //Delete normal model
        if($parent->getName() === Model::class){
            $query = "DELETE FROM {$reflection->getShortName()} WHERE {$propertyName}= ?";

            if(!Database::delete($query,[$value])){
                Log::channel("db")->info("Failed to deleted model with query " . $query);
                return false;
            }

            return true;
        }

        //Delete extended model
        return Database::transaction(function() use ($value, $propertyName, $parent, $reflection) {

            $query = "DELETE FROM {$reflection->getShortName()} WHERE {$propertyName} = ?";
            $parentQuery = "DELETE FROM {$parent->getShortName()} WHERE {$propertyName} = ?";

            if (!Database::delete($query, [$value])) {
                Log::channel("db")->info("Failed to deleted model with query " . $query);
                return false;
            }

            if(!Database::delete($parentQuery, [$value])){
                Log::channel("db")->info("Failed to deleted model with query " . $parentQuery);
                return false;
            }

            return true;
        });
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

                $values = [];
                // Insert into parent table first
                $parentTable = $parent->getShortName();
                $parentQuery = "INSERT INTO {$parentTable}" . $this->buildInsertQueryString($parentProperties,$values);

                Log::channel("db")->info("Parent query: " . $parentQuery);
                $result = Database::insert($parentQuery,$values);

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
                $values = [];
                // Insert into the current model's table
                $tableName = $reflection->getShortName();
                $childQuery = "INSERT INTO {$tableName}" . $this->buildInsertQueryString($childProps,$values);

                Log::channel("db")->info("Child query: " . $childQuery);
                $result = Database::insert($childQuery,$values);

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
            $properties = $this->getProperties($reflection->getProperties(), $reflection->getName());

            // Insert into the current model's table
            $tableName = $reflection->getShortName();

            $values = [];
            $query = "INSERT INTO {$tableName}" . $this->buildInsertQueryString($properties,$values);

            Log::channel("db")->info("Query: " . $query);

            $result = Database::insert($query,$values);

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

    public function buildInsertQueryString(array $properties, array &$bindValues = []): string
    {
        if (empty($properties)) {
            return " DEFAULT VALUES";
        }

        $columns = " (";
        $placeholders = " VALUES (";
        $bindValues = [];

        foreach ($properties as $key => $value) {

            $columns .= "`" . $key . "`, ";
            $placeholders .= "?, ";

            // Convert booleans to integers for MySQL PDO compatibility
            if (is_bool($value)) {
                $bindValues[] = $value ? 1 : 0;
            } else {
                $bindValues[] = $value;
            }
        }

        $columns = rtrim($columns, ", ") . ")";
        $placeholders = rtrim($placeholders, ", ") . ")";

        return $columns . $placeholders;
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
        $idValue = $idProperty->getValue($this);

        $parent = $reflection->getParentClass();

        if ($parent->getName() !== Model::class) {
            // Extended model handling
            $parentUpdates = [];
            $parentBindValues = [];

            foreach (Model::getDatabaseProperties($parent->getName()) as $property) {
                if (!$property["PRIMARY_KEY"]) {
                    $propName = $property["NAME"];
                    $parentProp = $parent->getProperty($propName);

                    $value = $parentProp->isInitialized($this) ? $parentProp->getValue($this) : null;

                    $parentUpdates[] = $propName . " = ?";
                    $parentBindValues[] = is_bool($value) ? ($value ? 1 : 0) : $value;
                }
            }

            $parentQuery = "UPDATE {$parent->getShortName()} SET " . implode(", ", $parentUpdates) . " WHERE {$identifier} = ?";
            $parentBindValues[] = $idValue;

            $childUpdates = [];
            $childBindValues = [];

            foreach (Model::getDatabaseProperties($reflection->getName()) as $property) {
                if (!$property["PRIMARY_KEY"]) {
                    $propName = $property["NAME"];
                    $reflProp = $reflection->getProperty($propName);

                    $value = $reflProp->isInitialized($this) ? $reflProp->getValue($this) : null;

                    $childUpdates[] = $propName . " = ?";
                    $childBindValues[] = is_bool($value) ? ($value ? 1 : 0) : $value;
                }
            }

            if (empty($childUpdates)) {
                // If no child updates, just update parent
                if (!Database::update($parentQuery, $parentBindValues)) {
                    Log::channel("db")->error("Failed to update parent: " . $parentQuery);
                    return false;
                }
                return true;
            }

            $childQuery = "UPDATE {$reflection->getShortName()} SET " . implode(", ", $childUpdates) . " WHERE {$identifier} = ?";
            $childBindValues[] = $idValue;

            return Database::transaction(function () use ($childQuery, $parentQuery, $childBindValues, $parentBindValues) {
                if (!Database::update($parentQuery, $parentBindValues)) {
                    Log::channel("db")->error("Failed to update parent in transaction");
                    return false;
                }
                if (!Database::update($childQuery, $childBindValues)) {
                    Log::channel("db")->error("Failed to update child in transaction");
                    return false;
                }
                return true;
            });
        }

        // Non-extended model handling
        $updates = [];
        $bindValues = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DatabaseColumn::class);

            if (count($attributes) > 0) {
                $value = $property->isInitialized($this) ? $property->getValue($this) : null;

                if ($value !== null) {
                    $skip = false;

                    foreach ($attributes as $attribute) {
                        $instance = $attribute->newInstance();
                        $skip = $instance->shouldSkip();
                    }

                    if (!$skip && $property->getName() !== $identifier) {
                        $updates[] = $property->getName() . " = ?";
                        $bindValues[] = is_bool($value) ? ($value ? 1 : 0) : $value;
                    }
                }
            }
        }

        if (empty($updates)) {
            return true; // No updates needed
        }

        $query = "UPDATE " . $reflection->getShortName() . " SET " . implode(", ", $updates) . " WHERE {$identifier} = ?";
        $bindValues[] = $idValue;

        try {
            if (!Database::update($query, $bindValues)) {
                Log::channel("db")->error("Failed to save model: " . $query);
                return false;
            }
            return true;
        } catch (Exception $e) {
            Log::channel("db")->error("Failed to save model: " . $e->getMessage());
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