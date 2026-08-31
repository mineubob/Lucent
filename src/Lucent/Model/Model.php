<?php
declare(strict_types=1);


namespace Lucent\Model;

use Exception;
use Lucent\Database;
use Lucent\Database\Dataset;
use Lucent\Helpers\Reflection\TypedProperty;
use Lucent\Validation\Constraints\Unique;
use ReflectionClass;

class Model
{
    public function hydrate(Dataset $dataset): void
    {
        $reflection = new ReflectionClass($this);

        foreach (Model::getDatabaseProperties($reflection) as $column) {
            $property = $reflection->getProperty($column->classPropertyName);
            $value = $dataset->get($column->name);

            TypedProperty::set($this, $property, $column->postProcess($value));
        }

        $parentClass = $reflection->getParentClass();
        if ($parentClass instanceof ReflectionClass) {
            foreach (Model::getDatabaseProperties($parentClass) as $column) {
                $property = $parentClass->getProperty($column->classPropertyName);
                $value = $dataset->get($column->name);

                TypedProperty::set($this, $property, $column->postProcess($value));
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
                $idProperty = $reflection->getProperty($pk->classPropertyName);
                $idValue = $idProperty->getValue($this);
                $idValue = $pk->preProcess($idValue);

                $query = "DELETE FROM {$reflection->getShortName()} WHERE {$pk->name} = ?";
            } else {
                // A custom property name was provided - use it for both the
                // reflection lookup and (via its column) the SQL column name.
                $idProperty = $reflection->getProperty($identifier);
                $idValue = $idProperty->getValue($this);

                $column = Column::fromProperty($idProperty);
                if ($column === null)
                    throw new \RuntimeException("Failed to get column!");

                $idValue = $column->preProcess($idValue);
                $query = "DELETE FROM {$reflection->getShortName()} WHERE {$column->name} = ?";
            }

            if (!Database::delete($query, [$idValue])) {
                return false;
            }

            return true;
        }

        if ($identifier === null) {
            $pk = Model::getDatabasePrimaryKey($parent);
            $idProperty = $reflection->getProperty($pk->classPropertyName);
            $idValue = $idProperty->getValue($this);
            $idValue = $pk->preProcess($idValue);
            $idColumnName = $pk->name;
        } else {
            $idProperty = $reflection->getProperty($identifier);
            $idValue = $idProperty->getValue($this);

            $column = Column::fromProperty($idProperty);
            if ($column === null)
                throw new \RuntimeException("Failed to get column!");

            $idValue = $column->preProcess($idValue);
            $idColumnName = $column->name;
        }

        //Delete extended model
        return Database::transaction(function () use ($idValue, $idColumnName, $parent, $reflection) {
            $query = "DELETE FROM {$reflection->getShortName()} WHERE {$idColumnName} = ?";
            $parentQuery = "DELETE FROM {$parent->getShortName()} WHERE {$idColumnName} = ?";

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

                    $lastId = $parentPK->preProcess($lastId);
                }

                // Get properties for the child model
                $childProps = $this->getProperties($reflection->getProperties(), $reflection->getName(), true);

                // Add the parent's primary key to the child properties
                $childProps[$parentPK->name] = [
                    'column' => $parentPK,
                    'value' => $lastId
                ];
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

            if ($pk->autoIncrement === true) {
                // Get the last inserted ID
                $lastId = Database::getDriver()->lastInsertId();

                // Set the ID
                TypedProperty::set($this, $reflection->getProperty($pk->classPropertyName), $lastId);
            }

            return true;
        }
    }

    /**
     * Summary of buildInsertQueryString
     * @param array<string, array{
     *      column: \Lucent\Model\Column,
     *      value: mixed
     *  }> $properties
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
            $columns .= "`$key`, ";
            $placeholders .= "?, ";
            $bindValues[] = $value['column']->preProcess($value['value']);
        }

        $columns = rtrim($columns, ", ") . ")";
        $placeholders = rtrim($placeholders, ", ") . ")";

        return "$columns$placeholders";
    }

    /**
     * Summary of getProperties
     * @param array<\ReflectionProperty> $properties
     * @param string $class
     * @param bool $skipAutoIncrement
     * @return array<string, array{
     *      column: \Lucent\Model\Column,
     *      value: mixed
     *  }>
     */
    public function getProperties(array $properties, string $class, bool $skipAutoIncrement): array
    {
        /** @var array<string, array{column: \Lucent\Model\Column, value: mixed}> */
        $output = [];
        foreach ($properties as $property) {
            $declaringClass = $property->getDeclaringClass();
            $dbColumn = Column::fromProperty($property);

            if ($dbColumn === null || $declaringClass->getName() !== $class)
                continue;

            if ($skipAutoIncrement && $dbColumn->autoIncrement)
                continue;

            if ($property->isInitialized($this)) {
                $output[$dbColumn->name] = [
                    "column" => $dbColumn,
                    "value" => $property->getValue($this)
                ];
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
                    $parentBindValues[] = $column->preProcess($value);
                }
            }

            if ($identifier === null) {
                $pk = Model::getDatabasePrimaryKey($parent);

                $idProperty = $parent->getProperty($pk->classPropertyName);
                $idValue = $idProperty->isInitialized($this) ? $idProperty->getValue($this) : null;
                $idValue = $pk->preProcess($idValue);
                $idColumnName = $pk->name;
            } else {
                $idProperty = $reflection->getProperty($identifier);
                $idValue = $idProperty->isInitialized($this) ? $idProperty->getValue($this) : null;
                $idColumn = Column::fromProperty($idProperty);
                if ($idColumn === null) {
                    throw new \RuntimeException("Failed to get column!");
                }
                $idValue = $idColumn->preProcess($idValue);
                $idColumnName = $idColumn->name;
            }

            $parentQuery = "UPDATE {$parent->getShortName()} SET " . implode(", ", $parentUpdates) . " WHERE {$idColumnName} = ?";
            $parentBindValues[] = $idValue;

            $childUpdates = [];
            $childBindValues = [];

            foreach (Model::getDatabaseProperties($reflection) as $column) {
                if (!$column->primaryKey) {
                    $property = $reflection->getProperty($column->classPropertyName);
                    $value = $property->isInitialized($this) ? $property->getValue($this) : null;

                    $childUpdates[] = "$column->name = ?";
                    $childBindValues[] = $column->preProcess($value);
                }
            }

            if (empty($childUpdates)) {
                // If no child updates, just update parent
                if (!Database::update($parentQuery, $parentBindValues)) {
                    return false;
                }
                return true;
            }

            $childQuery = "UPDATE {$reflection->getShortName()} SET " . implode(", ", $childUpdates) . " WHERE {$idColumnName} = ?";
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
            
            $idProperty = $reflection->getProperty($pk->classPropertyName);
            $idValue = $idProperty->isInitialized($this) ? $idProperty->getValue($this) : null;
            $idValue = $pk->preProcess($idValue);
            $idColumnName = $pk->name;
        } else {
            $idProperty = $reflection->getProperty($identifier);
            $idValue = $idProperty->isInitialized($this) ? $idProperty->getValue($this) : null;
            $idColumn = Column::fromProperty($idProperty);
            if ($idColumn === null) {
                throw new \RuntimeException("Failed to get column!");
            }
            $idValue = $idColumn->preProcess($idValue);
            $idColumnName = $idColumn->name;
        }

        // Non-extended model handling
        $updates = [];
        $bindValues = [];

        foreach (Model::getDatabaseProperties($reflection) as $column) {
            // Never update the primary key column in a save().
            if ($column->primaryKey) {
                continue;
            }

            $property = $reflection->getProperty($column->classPropertyName);
            $value = $property->isInitialized($this) ? $property->getValue($this) : null;

            $updates[] = "$column->name = ?";
            $bindValues[] = $column->preProcess($value);
        }

        if (empty($updates)) {
            return true; // No updates needed
        }

        $query = "UPDATE " . $reflection->getShortName() . " SET " . implode(", ", $updates) . " WHERE {$idColumnName} = ?";
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

        Database::log("error", "[Model] No primary key found for class {$reflection->getName()}");
        throw new \RuntimeException("No primary key found for class {$reflection->getName()}");
    }

    /**
     * @return Collection<static>
     */
    public static function where(string $column, string $value): Collection
    {
        return new Collection(static::class)->where($column, $value);
    }

    /**
     * Build a {@see Unique} constraint that checks a column against the
     * database for this model.
     *
     * The returned constraint fails when another row already holds the
     * validated value in the given column. When an $ignoreId is supplied, the
     * row with that primary key is excluded from the check — used when
     * updating an existing record so its own value does not count as a
     * duplicate.
     *
     * ```php
     * $request->validate([
     *     'email' => User::uniqueConstraint('email', $user->id),
     * ]);
     * ```
     *
     * @param string $column The column to check for uniqueness.
     * @param mixed $ignoreId Optional primary key of the row to exclude from
     *        the check (e.g. the current record being updated).
     * @return Unique The unique constraint.
     */
    public static function uniqueConstraint(string $column, mixed $ignoreId = null): Unique
    {
        return new Unique(function (mixed $value) use ($column, $ignoreId): bool {
            $query = new Collection(static::class)->where($column, (string) $value);

            if ($ignoreId !== null) {
                $pk = Model::getDatabasePrimaryKey(new ReflectionClass(static::class));
                $query->compare($pk->name, '!=', (string) $ignoreId);
            }

            return $query->count() > 0;
        });
    }

    /**
     * @return Collection<static>
     */
    public static function orWhere(string $column, string $value): Collection
    {
        return new Collection(static::class)->orWhere($column, $value);
    }

    /**
     * @return Collection<static>
     */
    public static function like(string $column, string $value): Collection
    {
        return new Collection(static::class)->like($column, $value);
    }

    /**
     * @return Collection<static>
     */
    public static function orLike(string $column, string $value): Collection
    {
        return new Collection(static::class)->orLike($column, $value);
    }

    /**
     * @return Collection<static>
     */
    public static function limit(int $count): Collection
    {
        return new Collection(static::class)->limit($count);
    }

    /**
     * @return Collection<static>
     */
    public static function offset(int $offset): Collection
    {
        return new Collection(static::class)->offset($offset);
    }

    /**
     * @return Collection<static>
     */
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

    public static function avg(string $column): float
    {
        return new Collection(static::class)->avg($column);
    }

    /**
     * Get the minimum value of a column across all rows.
     *
     * @param string $column The column name
     * @return mixed The minimum value, or null when no rows match
     */
    public static function min(string $column): mixed
    {
        return new Collection(static::class)->min($column);
    }

    /**
     * Get the maximum value of a column across all rows.
     *
     * @param string $column The column name
     * @return mixed The maximum value, or null when no rows match
     */
    public static function max(string $column): mixed
    {
        return new Collection(static::class)->max($column);
    }

    /**
     * @return array<static>
     */
    public static function get(): array
    {
        return new Collection(static::class)->get();
    }

    /**
     * @return static|null
     */
    public static function getFirst(): static|null
    {
        return new Collection(static::class)->getFirst();
    }

    /**
     * @return Collection<static>
     */
    public static function in(string $column, array $values, string $operator = "AND"): Collection
    {
        return new Collection(static::class)->in($column, $values, $operator);
    }

    /**
     * @return Collection<static>
     */
    public static function compare(string $column, string $logicalOperator, string $value): Collection
    {
        return new Collection(static::class)->compare($column, $logicalOperator, $value);
    }
}
