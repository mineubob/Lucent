<?php

namespace Lucent\Model;

use Lucent\Database;
use Lucent\Database\Dataset;
use ReflectionClass;

class Collection
{
    private string $class;
    private array $whereConditions;
    private array $likeConditions;
    private array $orderByClauses = [];
    private int $limit;
    private int $offset;
    private ReflectionClass $reflection;
    private array $cache;
    private static array $traitConditions = [];

    // NEW: Store bind values separately
    private array $bindValues = [];

    public function __construct($class)
    {
        $this->class = $class;
        $this->whereConditions = [];
        $this->likeConditions = [];
        $this->limit = 10;
        $this->offset = 0;
        $this->cache = [];
        $this->reflection = new ReflectionClass($class);
    }

    public function where(string $column, string $value, string $operator = 'AND'): self
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND';
        }

        $formattedColumn = $this->formatColumnName($column);

        $this->whereConditions[] = [
            'column' => $formattedColumn,
            'value' => $value,  // Store raw value
            'operator' => $operator,
            'type' => '='  // NEW: Track comparison type
        ];

        return $this;
    }

    public function in(string $column, array $values, string $operator = 'AND'): self
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND';
        }

        $formattedColumn = $this->formatColumnName($column);

        $this->whereConditions[] = [
            'column' => $formattedColumn,
            'value' => $values,  // Store array of values
            'operator' => $operator,
            'type' => 'IN'  // NEW: Mark as IN clause
        ];

        return $this;
    }

    public function compare(string $column, string $logicalOperator, string $value, string $operator = 'AND'): self
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND';
        }

        $formattedColumn = $this->formatColumnName($column);

        $this->whereConditions[] = [
            'column' => $formattedColumn,
            'value' => $value,  // Store raw value
            'operator' => $operator,
            'type' => $logicalOperator  // NEW: Store the comparison operator (>, <, >=, etc)
        ];

        return $this;
    }

    public function orWhere(string $column, string $value): self
    {
        return $this->where($column, $value, 'OR');
    }

    public function like(string $column, string $value, string $operator = 'AND'): self
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND';
        }

        $formattedColumn = $this->formatColumnName($column);

        $this->likeConditions[] = [
            'column' => $formattedColumn,
            'value' => $value,  // Store raw value
            'operator' => $operator
        ];

        return $this;
    }

    public function orLike(string $column, string $value): self
    {
        return $this->like($column, $value, 'OR');
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            $direction = 'ASC';
        }

        $formattedColumn = $this->formatColumnName($column);

        $this->orderByClauses[] = [
            'column' => $formattedColumn,
            'direction' => $direction
        ];

        return $this;
    }

    private function formatColumnName(string $column): string
    {
        if ($this->reflection->getParentClass()->getName() !== Model::class) {
            if (!Model::hasDatabaseProperty($this->reflection, $column)) {
                return "{$this->reflection->getParentClass()->getShortName()}.{$column}";
            } else {
                return "{$this->reflection->getShortName()}.{$column}";
            }
        }

        return $column;
    }

    public function limit(int $count): self
    {
        $this->limit = $count;
        return $this;
    }

    public function offset(int $count): self
    {
        $this->offset = $count;
        return $this;
    }

    public function get(): array
    {
        [$query, $bindValues] = $this->buildQuery();

        $cacheKey = $query . '|' . json_encode($bindValues);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $results = Database::select($query, true, $bindValues);

        if ($results === null) {
            return [];
        }

        $instances = [];
        $class = new ReflectionClass($this->class);

        foreach ($results as $result) {
            $instance = $class->newInstanceWithoutConstructor();
            $instance->hydrate(new Dataset($result));
            array_push($instances, $instance);
        }

        $this->cache[$cacheKey] = $instances;
        return $instances;
    }

    public function getFirst(): mixed
    {
        $this->limit = 1;
        [$query, $bindValues] = $this->buildQuery();

        $data = Database::select($query, false, $bindValues);

        if ($data !== null && !empty($data)) {
            $class = new ReflectionClass($this->class);
            $instance = $class->newInstanceWithoutConstructor();
            $instance->hydrate(new Dataset($data));
            return $instance;
        }

        return null;
    }

    public function collection(): self
    {
        return $this;
    }

    public function count(): int
    {
        [$query, $bindValues] = $this->buildQuery();
        $query = str_replace("*", "count(*)", $query);
        return (int) Database::select($query, false, $bindValues)["count(*)"];
    }

    public function sum(string $column): float
    {
        [$query, $bindValues] = $this->buildQuery();
        $query = str_replace("*", "sum({$column})", $query);
        return (float) Database::select($query, false, $bindValues)["sum({$column})"];
    }

    // MODIFIED: Now returns both query and bind values
    private function buildQuery(): array
    {
        $bindValues = [];
        $modelClass = $this->class;
        $reflection = new ReflectionClass($modelClass);
        $parent = $reflection->getParentClass();

        $array = explode("\\", $this->class);
        $className = end($array);
        $query = "SELECT * FROM " . $className;

        // Handle inheritance
        if ($parent->getName() !== Model::class) {
            $pk = Model::getDatabasePrimaryKey($parent);
            $query = "SELECT * FROM {$parent->getShortName()} JOIN {$className} ON {$className}.{$pk->name} = {$parent->getShortName()}.{$pk->name}";
        }

        // Apply trait conditions
        if (count(self::$traitConditions) > 0) {
            foreach (self::$traitConditions as $traitName => $condition) {
                if (class_exists($this->class) && in_array($traitName, $this->class_uses_recursive($this->class))) {
                    $column = $condition['column'];
                    $value = $condition['value'];

                    if ($value === null) {
                        $this->whereConditions[] = [
                            'column' => $column,
                            'value' => null,
                            'operator' => 'AND',
                            'type' => 'IS NULL'
                        ];
                    } else {
                        $this->whereConditions[] = [
                            'column' => $column,
                            'value' => $value,
                            'operator' => 'AND',
                            'type' => '='
                        ];
                    }
                }
            }
        }

        // Build WHERE conditions
        if (!empty($this->whereConditions) || !empty($this->likeConditions)) {
            $query .= " WHERE ";
            $conditions = [];

            // Process WHERE conditions
            foreach ($this->whereConditions as $index => $condition) {
                $prefix = ($index > 0) ? $condition['operator'] . ' ' : '';

                // Handle different condition types
                if ($condition['type'] === 'IN') {
                    // Build IN clause with placeholders
                    $placeholders = implode(', ', array_fill(0, count($condition['value']), '?'));
                    $conditions[] = $prefix . $condition['column'] . " IN (" . $placeholders . ")";

                    // Add each value to bind array
                    foreach ($condition['value'] as $val) {
                        $bindValues[] = is_bool($val) ? ($val ? 1 : 0) : $val;
                    }
                } elseif ($condition['type'] === 'IS NULL') {
                    $conditions[] = $prefix . $condition['column'] . " IS NULL";
                    // No bind value for IS NULL
                } else {
                    // Regular comparison (=, >, <, >=, <=, !=)
                    $conditions[] = $prefix . $condition['column'] . " " . $condition['type'] . " ?";
                    $bindValues[] = is_bool($condition['value']) ? ($condition['value'] ? 1 : 0) : $condition['value'];
                }
            }

            // Process LIKE conditions
            foreach ($this->likeConditions as $index => $condition) {
                $prefix = (!empty($this->whereConditions) || $index > 0) ? $condition['operator'] . ' ' : '';
                $conditions[] = $prefix . $condition['column'] . " LIKE ?";
                $bindValues[] = '%' . $condition['value'] . '%';
            }

            $query .= implode(' ', $conditions);
        }

        if (!empty($this->orderByClauses)) {
            $query .= " ORDER BY ";
            $orderClauses = [];

            foreach ($this->orderByClauses as $clause) {
                $orderClauses[] = $clause['column'] . ' ' . $clause['direction'];
            }

            $query .= implode(', ', $orderClauses);
        }

        // Add limit and offset
        $query .= " LIMIT " . $this->limit;

        if ($this->offset != 0) {
            $query .= " OFFSET " . $this->offset;
        }

        return [$query, $bindValues];
    }

    public static function registerTraitCondition(string $traitName, string $column, $value): void
    {
        self::$traitConditions[$traitName] = [
            'column' => $column,
            'value' => $value
        ];
    }

    private function class_uses_recursive(string $class): array
    {
        $traits = [];
        $className = is_object($class) ? get_class($class) : $class;
        $traits = class_uses($className) ?: [];

        $parentClass = get_parent_class($className);
        if ($parentClass) {
            $traits = array_merge($this->class_uses_recursive($parentClass), $traits);
        }

        return $traits;
    }
}