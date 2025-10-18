<?php

namespace Lucent\Model;

use Exception;
use Lucent\Database;
use Lucent\Database\Dataset;
use Lucent\Facades\Log;
use Lucent\Helpers\Reflection\TypedProperty;
use ReflectionClass;

class Model
{
    public protected(set) Dataset $dataset;

    public function hydrate(Dataset $dataset): void
    {
        $this->dataset = $dataset;

        $reflection = new ReflectionClass($this);

        foreach (Model::getDatabaseProperties($reflection) as $column) {
            $property = $reflection->getProperty($column->classPropertyName);
            $value = $dataset->get($column->name);

            TypedProperty::set($this, $property, $value);
        }

        $parentClass = $reflection->getParentClass();
        if ($parentClass instanceof ReflectionClass) {
            foreach (Model::getDatabaseProperties($parentClass) as $column) {
                $property = $parentClass->getProperty($column->classPropertyName);
                $value = $dataset->get($column->name);

                TypedProperty::set($this, $property, $value);
            }
        }
    }

    public function delete(?string $identifier = null): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        //Delete normal model
        if ($parent->getName() === Model::class) {
            if ($identifier === null) {
                $pk = Model::getDatabasePrimaryKey($reflection);
                $identifier = $pk->name;
            }
            $idProperty = $reflection->getProperty($identifier);
            $idValue = $idProperty->getValue($this);

            $column = Column::fromProperty($idProperty);
            if ($column === null) throw new \RuntimeException("Failed to get column!");

            $query = "DELETE FROM {$reflection->getShortName()} WHERE {$column->name} = ?";

            if (!Database::delete($query, [$idValue])) {
                return false;
            }

            return true;
        }

        if ($identifier === null) {
            $pk = Model::getDatabasePrimaryKey($parent);
            $identifier = $pk->name;
        }
        $idProperty = $reflection->getProperty($identifier);
        $idValue = $idProperty->getValue($this);

        $column = Column::fromProperty($idProperty);
        if ($column === null) throw new \RuntimeException("Failed to get column!");

        //Delete extended model
        return Database::transaction(function () use ($idValue, $column, $parent, $reflection) {
            $query = "DELETE FROM {$reflection->getShortName()} WHERE {$column->name} = ?";
            $parentQuery = "DELETE FROM {$parent->getShortName()} WHERE {$column->name} = ?";

            if (!Database::delete($query, [$idValue])) {
                return false;
            }

            if (!Database::delete($parentQuery, [$idValue])) {
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
            $parentProperties = $this->getProperties($parent->getProperties(), $parent->getName(), true);

            // Start a transaction
            return Database::transaction(function () use ($reflection, $parent, $parentPK, $parentProperties) {
                $values = [];

                // Insert into parent table first
                $parentTable = $parent->getShortName();
                $parentQuery = "INSERT INTO {$parentTable}" . $this->buildInsertQueryString($parentProperties, $values);

                $result = Database::insert($parentQuery, $values);

                if (!$result) {
                    // The transaction will be rolled back automatically
                    return false;
                }

                if ($parentPK->autoIncrement === true) {
                    // Get the last inserted ID
                    $lastId = Database::getDriver()->lastInsertId();

                    // Set the ID
                    TypedProperty::set($this, $parent->getProperty($parentPK->classPropertyName), $lastId);
                } else {
                    $lastId = $reflection->getProperty($parentPK->classPropertyName)->getValue($this);
                }

                // Get properties for the child model
                $childProps = $this->getProperties($reflection->getProperties(), $reflection->getName(), true);

                // Add the parent's primary key to the child properties
                $childProps[$parentPK->name] = $lastId;
                $values = [];
                // Insert into the current model's table
                $tableName = $reflection->getShortName();
                $childQuery = "INSERT INTO {$tableName}" . $this->buildInsertQueryString($childProps, $values);

                $result = Database::insert($childQuery, $values);

                if (!$result) {
                    // The transaction will be rolled back automatically
                    return false;
                }

                // The transaction will be committed automatically
                return true;
            });
        } else {
            // Standard model creation (no transaction needed)
            $properties = $this->getProperties($reflection->getProperties(), $reflection->getName(), true);

            // Insert into the current model's table
            $tableName = $reflection->getShortName();

            $values = [];
            $query = "INSERT INTO {$tableName}" . $this->buildInsertQueryString($properties, $values);

            $result = Database::insert($query, $values);

            if (!$result) {
                return false;
            }

            $pk = Model::getDatabasePrimaryKey($reflection);

            // Get the last inserted ID
            $lastId = Database::getDriver()->lastInsertId();

            // Set the ID
            TypedProperty::set($this, $reflection->getProperty($pk->classPropertyName), $lastId);

            return true;
        }
    }

    /**
     * Summary of buildInsertQueryString
     * @param array<string, mixed> $properties
     * @param array $bindValues
     * @return string
     */
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

    /**
     * Summary of getProperties
     * @param array<\ReflectionProperty> $properties
     * @param string $class
     * @param bool $skipAutoIncrement
     * @return array<string, mixed>
     */
    public function getProperties(array $properties, string $class, bool $skipAutoIncrement): array
    {
        $output = [];
        foreach ($properties as $property) {
            $declaringClass = $property->getDeclaringClass();
            $dbColumn = Column::fromProperty($property);

            if ($dbColumn === null || $declaringClass->getName() !== $class)
                continue;

            if ($skipAutoIncrement && $dbColumn->autoIncrement)
                continue;

            if ($property->isInitialized($this)) {
                $output[$dbColumn->name] = $property->getValue($this);
            }
        }

        return $output;
    }

    public function save(?string $identifier = null): bool
    {
        $reflection = new ReflectionClass($this);
        $parent = $reflection->getParentClass();

        if ($parent->getName() !== Model::class) {
            // Extended model handling
            $parentUpdates = [];
            $parentBindValues = [];

            foreach (Model::getDatabaseProperties($parent) as $column) {
                if (!$column->primaryKey) {
                    $parentProperty = $parent->getProperty($column->classPropertyName);
                    $value = $parentProperty->isInitialized($this) ? $parentProperty->getValue($this) : null;

                    $parentUpdates[] = "$column->name = ?";
                    $parentBindValues[] = is_bool($value) ? ($value ? 1 : 0) : $value;
                }
            }

            if ($identifier === null) {
                $pk = Model::getDatabasePrimaryKey($parent);
                $identifier = $pk->name;
            }
            $idProperty = $reflection->getProperty($identifier);
            $idValue = $idProperty->getValue($this);

            $parentQuery = "UPDATE {$parent->getShortName()} SET " . implode(", ", $parentUpdates) . " WHERE {$identifier} = ?";
            $parentBindValues[] = $idValue;

            $childUpdates = [];
            $childBindValues = [];

            foreach (Model::getDatabaseProperties($reflection) as $column) {
                if (!$column->primaryKey) {
                    $property = $reflection->getProperty($column->classPropertyName);
                    $value = $property->isInitialized($this) ? $property->getValue($this) : null;

                    $childUpdates[] = "$column->name = ?";
                    $childBindValues[] = is_bool($value) ? ($value ? 1 : 0) : $value;
                }
            }

            if (empty($childUpdates)) {
                // If no child updates, just update parent
                if (!Database::update($parentQuery, $parentBindValues)) {
                    return false;
                }
                return true;
            }

            $childQuery = "UPDATE {$reflection->getShortName()} SET " . implode(", ", $childUpdates) . " WHERE {$identifier} = ?";
            $childBindValues[] = $idValue;

            return Database::transaction(function () use ($childQuery, $parentQuery, $childBindValues, $parentBindValues) {
                if (!Database::update($parentQuery, $parentBindValues)) {
                    return false;
                }
                if (!Database::update($childQuery, $childBindValues)) {
                    return false;
                }
                return true;
            });
        }
        
        if ($identifier === null) {
            $pk = Model::getDatabasePrimaryKey($reflection);
            $identifier = $pk->name;
        }
        $idProperty = $reflection->getProperty($identifier);
        $idValue = $idProperty->getValue($this);

        // Non-extended model handling
        $updates = [];
        $bindValues = [];

        foreach (Model::getDatabaseProperties($reflection) as $column) {
            $property = $reflection->getProperty($column->classPropertyName);
            $value = $property->isInitialized($this) ? $property->getValue($this) : null;

            $updates[] = "$column->name = ?";
            $bindValues[] = is_bool($value) ? ($value ? 1 : 0) : $value;
        }

        if (empty($updates)) {
            return true; // No updates needed
        }

        $query = "UPDATE " . $reflection->getShortName() . " SET " . implode(", ", $updates) . " WHERE {$identifier} = ?";
        $bindValues[] = $idValue;

        try {
            if (!Database::update($query, $bindValues)) {
                return false;
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function hasDatabaseProperty(ReflectionClass $class, string $name): bool
    {
        return array_key_exists($name, Model::getDatabaseProperties($class));
    }

    /**
     * Get the Model Columns on this Model.
     * @param ReflectionClass $class
     * @return array<string, Column>
     */
    public static function getDatabaseProperties(ReflectionClass $class): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class->getName())
                continue;

            $column = Column::fromProperty($property);

            if ($column !== null) {
                $properties[$column->name] = $column;
            }
        }

        return $properties;
    }

    public static function getDatabasePrimaryKey(ReflectionClass $reflection): Column
    {
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName())
                continue;

            $dbColumn = Column::fromProperty($property);
            if ($dbColumn !== null && $dbColumn->primaryKey === true) {
                return $dbColumn;
            }
        }

        Log::channel("lucent.db")->error("[Model] No primary key found for class {$reflection->getName()}");
        throw new \RuntimeException("No primary key found for class {$reflection->getName()}");
    }

    public static function where(string $column, string $value): Collection
    {
        return new Collection(static::class)->where($column, $value);
    }
    public static function orWhere(string $column, string $value): Collection
    {
        return new Collection(static::class)->orWhere($column, $value);
    }

    public static function like(string $column, string $value): Collection
    {
        return new Collection(static::class)->like($column, $value);
    }
    public static function orLike(string $column, string $value): Collection
    {
        return new Collection(static::class)->orLike($column, $value);
    }

    public static function limit(int $count): Collection
    {
        return new Collection(static::class)->limit($count);
    }

    public static function offset(int $offset): Collection
    {
        return new Collection(static::class)->offset($offset);
    }

    public static function orderBy(string $column, string $direction = "ASC"): Collection
    {
        return new Collection(static::class)->orderBy($column, $direction);
    }

    public static function count(): int
    {
        return new Collection(static::class)->count();
    }

    public static function sum(string $column): float
    {
        return new Collection(static::class)->sum($column);
    }

    public static function collection(): Collection
    {
        return new Collection(static::class);
    }

    public static function get(): array
    {
        return new Collection(static::class)->get();
    }

    public static function getFirst(): self|null
    {
        return new Collection(static::class)->getFirst();
    }

    public static function in(string $column, array $values, string $operator = "AND"): Collection
    {
        return new Collection(static::class)->in($column, $values, $operator);
    }

    public static function compare(string $column, string $logicalOperator, string $value): Collection
    {
        return new Collection(static::class)->compare($column, $logicalOperator, $value);
    }
}