<?php

namespace Lucent\Model;

use Lucent\Database;
use Lucent\Database\Dataset;
use ReflectionClass;

/**
 * Query builder / collection for Model rows.
 *
 * @template T of \Lucent\Model\Model
 */
final class Collection
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

    /**
     * @param class-string<T> $class
     */
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

    /**
     * @return static<T>
     */
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

    /**
     * @return static<T>
     */
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

    /**
     * @return static<T>
     */
    public function compare(string $column, string $logicalOperator, string $value, string $operator = 'AND'): self
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND';
        }
        
        $logicalOperator = strtoupper($logicalOperator);

        // Whitelist the comparison operator to prevent SQL operator injection.
        // The raw operator is concatenated into the query string, so it must
        // never accept arbitrary user input.
        $allowedOperators = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];
        if (!in_array($logicalOperator, $allowedOperators, true)) {
            throw new \InvalidArgumentException(
                "Invalid comparison operator provided: '$logicalOperator'. Allowed operators: " . implode(', ', $allowedOperators)
            );
        }

        $formattedColumn = $this->formatColumnName($column);

        $this->whereConditions[] = [
            'column' => $formattedColumn,
            'value' => $value,  // Store raw value
            'operator' => $operator,
            'type' => $logicalOperator  // Store the validated comparison operator (>, <, >=, etc)
        ];

        return $this;
    }

    /**
     * @return static<T>
     */
    public function orWhere(string $column, string $value): self
    {
        return $this->where($column, $value, 'OR');
    }

    /**
     * @return static<T>
     */
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

    /**
     * @return static<T>
     */
    public function orLike(string $column, string $value): self
    {
        return $this->like($column, $value, 'OR');
    }

    /**
     * @return static<T>
     */
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
        $parent = $this->reflection->getParentClass();

        // Extended model: the column may live on the parent class.
        if ($parent->getName() !== Model::class) {
            if (Model::hasDatabaseProperty($parent, $column)) {
                return $parent->getShortName() . '.' . $column;
            }
            if (Model::hasDatabaseProperty($this->reflection, $column)) {
                return $this->reflection->getShortName() . '.' . $column;
            }
        } elseif (Model::hasDatabaseProperty($this->reflection, $column)) {
            return $column;
        }

        // Column names are interpolated into SQL verbatim and cannot be bound,
        // so they must be validated against the model's declared properties.
        // Rejecting unknown columns prevents SQL injection via this position.
        throw new \InvalidArgumentException(
            "Column '{$column}' does not exist on model {$this->class}"
        );
    }

    /**
     * @return static<T>
     */
    public function limit(int $count): self
    {
        $this->limit = $count;
        return $this;
    }

    /**
     * @return static<T>
     */
    public function offset(int $count): self
    {
        $this->offset = $count;
        return $this;
    }

    /**
     * @return array<T>
     */
    public function get(): array
    {
        [$query, $bindValues] = $this->buildQuery();

        $cacheKey = $query . '|' . json_encode($bindValues);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        // Query caching is handled by Database::select() when a query cache
        // store has been injected via Database::setQueryCache(). The ORM
        // never constructs a cache driver itself.
        $results = Database::select($query, true, $bindValues);

        if ($results === null) {
            return [];
        }

        $instances = $this->hydrateAll($results);

        $this->cache[$cacheKey] = $instances;
        return $instances;
    }

    /**
     * Hydrate an array of raw result rows into model instances.
     *
     * @param array $results Raw rows from the database or query cache
     * @return array<T>
     */
    private function hydrateAll(array $results): array
    {
        $instances = [];
        $class = new ReflectionClass($this->class);

        foreach ($results as $result) {
            $instance = $class->newInstanceWithoutConstructor();
            $instance->hydrate(new Dataset($result));
            array_push($instances, $instance);
        }

        return $instances;
    }

    /**
     * @return T|null
     */
    public function getFirst(): mixed
    {
        // Save and restore the limit so reusing the same Collection for a
        // subsequent get()/count() is not silently limited to 1 row.
        $previousLimit = $this->limit;
        $this->limit = 1;
        [$query, $bindValues] = $this->buildQuery();
        $this->limit = $previousLimit;

        $data = Database::select($query, false, $bindValues);

        if ($data !== null && !empty($data)) {
            $class = new ReflectionClass($this->class);
            $instance = $class->newInstanceWithoutConstructor();
            $instance->hydrate(new Dataset($data));
            return $instance;
        }

        return null;
    }

    public function count(): int
    {
        $from = $this->buildFromClause();
        [$where, $bindValues] = $this->buildWhereClause();

        $query = "SELECT count(*) AS total_count {$from}{$where}";

        return (int) Database::select($query, false, $bindValues)["total_count"];
    }

    public function sum(string $column): float
    {
        // Whitelist the column against the model's database properties so an
        // arbitrary string can never be interpolated into the SQL query.
        // Also handle the extended-model case where the column lives on the
        // parent class.
        $columnInfo = $this->resolveAggregateColumn($column);
        $formattedColumn = $columnInfo['formatted'];
        $aggregateKey = $columnInfo['key'];

        $from = $this->buildFromClause();
        [$where, $bindValues] = $this->buildWhereClause();

        $query = "SELECT SUM({$formattedColumn}) AS {$aggregateKey} {$from}{$where}";

        return (float) Database::select($query, false, $bindValues)[$aggregateKey];
    }

    public function avg(string $column): float
    {
        $columnInfo = $this->resolveAggregateColumn($column);
        $formattedColumn = $columnInfo['formatted'];
        $aggregateKey = $columnInfo['key'];

        $from = $this->buildFromClause();
        [$where, $bindValues] = $this->buildWhereClause();

        $query = "SELECT AVG({$formattedColumn}) AS {$aggregateKey} {$from}{$where}";

        return (float) Database::select($query, false, $bindValues)[$aggregateKey];
    }

    /**
     * Get the minimum value of a column.
     *
     * @param string $column The column name
     * @return mixed The minimum value, or null when no rows match
     */
    public function min(string $column): mixed
    {
        $columnInfo = $this->resolveAggregateColumn($column);
        $formattedColumn = $columnInfo['formatted'];
        $aggregateKey = $columnInfo['key'];

        $from = $this->buildFromClause();
        [$where, $bindValues] = $this->buildWhereClause();

        $query = "SELECT MIN({$formattedColumn}) AS {$aggregateKey} {$from}{$where}";

        return Database::select($query, false, $bindValues)[$aggregateKey];
    }

    /**
     * Get the maximum value of a column.
     *
     * @param string $column The column name
     * @return mixed The maximum value, or null when no rows match
     */
    public function max(string $column): mixed
    {
        $columnInfo = $this->resolveAggregateColumn($column);
        $formattedColumn = $columnInfo['formatted'];
        $aggregateKey = $columnInfo['key'];

        $from = $this->buildFromClause();
        [$where, $bindValues] = $this->buildWhereClause();

        $query = "SELECT MAX({$formattedColumn}) AS {$aggregateKey} {$from}{$where}";

        return Database::select($query, false, $bindValues)[$aggregateKey];
    }

    private function resolveAggregateColumn(string $column): array
    {
        $parent = $this->reflection->getParentClass();

        // Extended model: column may live on the parent class.
        if ($parent->getName() !== Model::class) {
            if (Model::hasDatabaseProperty($parent, $column)) {
                return [
                    'formatted' => $parent->getShortName() . '.' . $column,
                    'key' => 'total_' . $column,
                ];
            }
        }

        if (Model::hasDatabaseProperty($this->reflection, $column)) {
            return [
                'formatted' => $column,
                'key' => 'total_' . $column,
            ];
        }

        throw new \InvalidArgumentException(
            "Column '{$column}' does not exist on model {$this->class}"
        );
    }

    private function buildQuery(): array
    {
        $select = "SELECT *";
        $from = $this->buildFromClause();
        [$where, $bindValues] = $this->buildWhereClause();
        $orderBy = $this->buildOrderByClause();
        $limit = $this->buildLimitClause();

        $query = "{$select} {$from}{$where}{$orderBy}{$limit}";

        return [$query, $bindValues];
    }

    private function buildFromClause(): string
    {
        $reflection = new ReflectionClass($this->class);
        $parent = $reflection->getParentClass();

        $array = explode("\\", $this->class);
        $className = end($array);

        // Handle inheritance
        if ($parent->getName() !== Model::class) {
            $pk = Model::getDatabasePrimaryKey($parent);
            return "FROM {$parent->getShortName()} JOIN {$className} ON {$className}.{$pk->name} = {$parent->getShortName()}.{$pk->name}";
        }

        return "FROM " . $className;
    }

    private function buildWhereClause(): array
    {
        $bindValues = [];
        $conditions = [];

        // Apply trait conditions into a LOCAL list so repeated calls to
        // buildQuery() (e.g. get() then count() on the same Collection) never
        // duplicate the trait WHERE clauses on the instance state.
        if (count(self::$traitConditions) > 0) {
            foreach (self::$traitConditions as $traitName => $condition) {
                if (class_exists($this->class) && in_array($traitName, $this->class_uses_recursive($this->class))) {
                    $column = $condition['column'];
                    $value = $condition['value'];

                    if ($value === null) {
                        $conditions[] = [
                            'column' => $column,
                            'value' => null,
                            'operator' => 'AND',
                            'type' => 'IS NULL'
                        ];
                    } else {
                        $conditions[] = [
                            'column' => $column,
                            'value' => $value,
                            'operator' => 'AND',
                            'type' => '='
                        ];
                    }
                }
            }
        }

        // Merge user-supplied conditions after trait conditions.
        $conditions = array_merge($conditions, $this->whereConditions);

        if (empty($conditions) && empty($this->likeConditions)) {
            return ['', $bindValues];
        }

        $whereParts = [];

        // Process WHERE conditions
        foreach ($conditions as $index => $condition) {
            $prefix = ($index > 0) ? $condition['operator'] . ' ' : '';

            // Handle different condition types
            if ($condition['type'] === 'IN') {
                // Guard against empty IN lists producing invalid SQL.
                if (count($condition['value']) === 0) {
                    $whereParts[] = $prefix . "0 = 1";
                    continue;
                }

                // Build IN clause with placeholders
                $placeholders = implode(', ', array_fill(0, count($condition['value']), '?'));
                $whereParts[] = $prefix . $condition['column'] . " IN (" . $placeholders . ")";

                // Add each value to bind array
                foreach ($condition['value'] as $val) {
                    $bindValues[] = is_bool($val) ? ($val ? 1 : 0) : $val;
                }
            } elseif ($condition['type'] === 'IS NULL') {
                $whereParts[] = $prefix . $condition['column'] . " IS NULL";
                // No bind value for IS NULL
            } else {
                // Regular comparison (=, >, <, >=, <=, !=)
                $whereParts[] = $prefix . $condition['column'] . " " . $condition['type'] . " ?";
                $bindValues[] = is_bool($condition['value']) ? ($condition['value'] ? 1 : 0) : $condition['value'];
            }
        }

        // Process LIKE conditions
        foreach ($this->likeConditions as $index => $condition) {
            $prefix = (!empty($conditions) || $index > 0) ? $condition['operator'] . ' ' : '';
            $whereParts[] = $prefix . $condition['column'] . " LIKE ?";
            $bindValues[] = '%' . $condition['value'] . '%';
        }

        return [' WHERE ' . implode(' ', $whereParts), $bindValues];
    }

    private function buildOrderByClause(): string
    {
        if (empty($this->orderByClauses)) {
            return '';
        }

        $orderClauses = [];

        foreach ($this->orderByClauses as $clause) {
            $orderClauses[] = $clause['column'] . ' ' . $clause['direction'];
        }

        return ' ORDER BY ' . implode(', ', $orderClauses);
    }

    private function buildLimitClause(): string
    {
        $clause = " LIMIT " . $this->limit;

        if ($this->offset !== 0) {
            $clause .= " OFFSET " . $this->offset;
        }

        return $clause;
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