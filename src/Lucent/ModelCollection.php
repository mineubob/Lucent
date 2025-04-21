<?php

namespace Lucent;

use Lucent\Database\Dataset;
use ReflectionClass;
use ReflectionException;

/**
 * ModelCollection class for querying database models
 *
 * Provides a fluent interface for building and executing database queries
 * with support for where conditions, like conditions, limit, offset, and
 * logical operators (AND/OR).
 */
class ModelCollection
{
    /**
     * The fully qualified class name of the model
     *
     * @var string
     */
    private string $class;

    /**
     * Array of WHERE conditions with their operators
     *
     * @var array
     */
    private array $whereConditions;

    /**
     * Array of LIKE conditions with their operators
     *
     * @var array
     */
    private array $likeConditions;

    /**
     * Array to store order by clauses
     *
     * @var array
     */
    private array $orderByClauses = [];

    /**
     * Maximum number of records to return
     *
     * @var int
     */
    private int $limit;

    /**
     * Number of records to skip
     *
     * @var int
     */
    private int $offset;

    /**
     * Reflection of the model class
     *
     * @var ReflectionClass
     */
    private ReflectionClass $reflection;

    /**
     * Query result cache
     *
     * @var array
     */
    private array $cache;

    private static array $traitConditions = [];

    /**
     * Create a new ModelCollection instance
     *
     * @param string $class The fully qualified class name of the model
     * @return ModelCollection
     * @throws ReflectionException
     */
    public function __construct($class){
        $this->class = $class;
        $this->whereConditions = [];
        $this->likeConditions = [];
        $this->limit = 10;
        $this->offset = 0;
        $this->cache = [];
        $this->reflection = new ReflectionClass($class);

        return $this;
    }

    /**
     * Add a WHERE condition to the query
     *
     * Adds a condition to match records where the specified column equals the provided value.
     * Multiple conditions can be combined with logical operators (AND/OR).
     *
     * @param string $column The column name to check
     * @param string $value The value to match
     * @param string $operator The logical operator (AND or OR) to use
     * @return ModelCollection
     */
    public function where(string $column, string $value, string $operator = 'AND'): ModelCollection
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND'; // Default to AND if invalid operator provided
        }

        // Format column name based on inheritance structure
        $formattedColumn = $this->formatColumnName($column);

        $this->whereConditions[] = [
            'column' => $formattedColumn,
            'value' => $value,
            'operator' => $operator
        ];

        return $this;
    }

    /**
     * Add a WHERE condition with OR logic
     *
     * Convenience method that adds a WHERE condition using OR logic,
     * equivalent to calling where($column, $value, 'OR').
     *
     * @param string $column The column name to check
     * @param string $value The value to match
     * @return ModelCollection
     */
    public function orWhere(string $column, string $value): ModelCollection
    {
        return $this->where($column, $value, 'OR');
    }

    /**
     * Add a LIKE condition to the query
     *
     * Adds a condition to match records where the specified column contains the provided value.
     * The search is case-insensitive and matches partial strings (adds % wildcards automatically).
     * Multiple conditions can be combined with logical operators (AND/OR).
     *
     * @param string $column The column name to search
     * @param string $value The value to search for (partial match)
     * @param string $operator The logical operator (AND or OR) to use
     * @return ModelCollection
     */
    public function like(string $column, string $value, string $operator = 'AND'): ModelCollection
    {
        $operator = strtoupper($operator);
        if ($operator !== 'AND' && $operator !== 'OR') {
            $operator = 'AND'; // Default to AND if invalid operator provided
        }

        // Format column name based on inheritance structure
        $formattedColumn = $this->formatColumnName($column);

        $this->likeConditions[] = [
            'column' => $formattedColumn,
            'value' => $value,
            'operator' => $operator
        ];

        return $this;
    }

    /**
     * Add a LIKE condition with OR logic
     *
     * Convenience method that adds a LIKE condition using OR logic,
     * equivalent to calling like($column, $value, 'OR').
     *
     * @param string $column The column name to search
     * @param string $value The value to search for (partial match)
     * @return ModelCollection
     */
    public function orLike(string $column, string $value): ModelCollection
    {
        return $this->like($column, $value, 'OR');
    }

    /**
     * Add an ORDER BY clause to the query
     *
     * @param string $column The column to sort by
     * @param string $direction The sort direction ('ASC' or 'DESC')
     * @return ModelCollection
     */
    public function orderBy(string $column, string $direction = 'ASC'): ModelCollection
    {
        // Normalize direction
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            $direction = 'ASC'; // Default to ASC if invalid direction provided
        }

        // Format column name based on inheritance structure
        $formattedColumn = $this->formatColumnName($column);

        $this->orderByClauses[] = [
            'column' => $formattedColumn,
            'direction' => $direction
        ];

        return $this;
    }

    /**
     * Format column name based on inheritance structure
     *
     * Handles column prefixing for inherited models to ensure correct
     * table references in SQL queries. If a model extends another model,
     * this determines whether the column belongs to the parent or child table.
     *
     * @param string $column Original column name
     * @return string Formatted column name with appropriate table prefix
     */
    private function formatColumnName(string $column): string
    {
        if ($this->reflection->getParentClass()->getName() !== Model::class) {
            if (!Model::hasDatabaseProperty($this->reflection->getName(), $column)) {
                return "{$this->reflection->getParentClass()->getShortName()}.{$column}";
            } else {
                return "{$this->reflection->getShortName()}.{$column}";
            }
        }

        return $column;
    }

    /**
     * Set the limit for the query
     *
     * Specifies the maximum number of records to return in the query result.
     *
     * @param int $count Maximum number of records to return
     * @return ModelCollection
     */
    public function limit(int $count): ModelCollection
    {
        $this->limit = $count;
        return $this;
    }

    /**
     * Set the offset for the query
     *
     * Specifies the number of records to skip before starting to return results.
     * Used for pagination in combination with limit().
     *
     * @param int $count Number of records to skip
     * @return ModelCollection
     */
    public function offset(int $count): ModelCollection
    {
        $this->offset = $count;
        return $this;
    }

    /**
     * Execute the query and get all matching records
     *
     * Builds and executes the SQL query based on all conditions and returns
     * an array of model instances. Results are cached by query string for
     * improved performance on repeated calls.
     *
     * @return array Array of model instances matching the query conditions
     */
    public function get(): array
    {
        $query = $this->buildQuery();

        if (array_key_exists($query, $this->cache)) {
            return $this->cache[$query];
        }

        $results = Database::select($query);

        if ($results === null) {
            return [];
        }

        $instances = [];
        $class = new ReflectionClass($this->class);

        foreach ($results as $result) {
            array_push($instances, $class->newInstance(new Dataset($result)));
        }

        $this->cache[$query] = $instances;
        return $instances;
    }

    /**
     * Execute the query and get the first matching record
     *
     * Builds and executes the SQL query with a limit of 1 and returns
     * a single model instance or null if no matching record is found.
     *
     * @return mixed|null A model instance or null if no matching record exists
     */
    public function getFirst(): mixed
    {
        $this->limit = 1;
        $data = Database::select($this->buildQuery(), false);

        if ($data !== null && !empty($data)) {
            $class = new ReflectionClass($this->class);
            return $class->newInstance(new Dataset($data));
        } else {
            return null;
        }
    }

    /**
     * Return the collection object for method chaining
     *
     * Helper method that returns the current ModelCollection instance
     * to facilitate method chaining in query building.
     *
     * @return ModelCollection Current ModelCollection instance
     */
    public function collection(): ModelCollection
    {
        return $this;
    }

    /**
     * Count the number of records that match the query
     *
     * Executes a COUNT(*) query with the current conditions to determine
     * the total number of matching records without retrieving the actual records.
     *
     * @return int Number of matching records
     */
    public function count(): int
    {
        $query = str_replace("*", "count(*)", $this->buildQuery());
        return (int)Database::select($query, false)["count(*)"];
    }

    /**
     *
     * Calculate the sum of values in a specified column
     *
     * Executes a SUM() query with the current conditions to calculate
     * the total sum of values in the specified column for all matching records.
     * Use the same WHERE conditions as the main query.
     *
     * @param string $column The column name to sum
     * @return float The sum of all values in the specified column
     */
    public function sum(string $column): float
    {
        $query = str_replace("*", "sum({$column})", $this->buildQuery());
        return (float)Database::select($query, false)["sum({$column})"];
    }

    /**
     * Build the SQL query based on the conditions
     *
     * Constructs a complete SQL query string from all the conditions, joins,
     * limits, and offsets specified in the ModelCollection. Handles model
     * inheritance by creating appropriate JOIN clauses when needed.
     *
     * @return string Complete SQL query ready for execution
     */
    private function buildQuery(): string
    {
        $modelClass = $this->class;
        $reflection = new \ReflectionClass($modelClass);
        $parent = $reflection->getParentClass();

        $array = explode("\\", $this->class);
        $className = end($array);
        $query = "SELECT * FROM " . $className;

        // Handle inheritance
        if ($parent->getName() !== Model::class) {
            $pk = Model::getDatabasePrimaryKey($parent);
            $query = "SELECT * FROM {$parent->getShortName()} JOIN {$className} ON {$className}.{$pk["NAME"]} = {$parent->getShortName()}.{$pk["NAME"]}";
        }

        // Apply trait conditions if enabled and the model uses those traits
        if (count(ModelCollection::$traitConditions) > 0) {
            foreach (self::$traitConditions as $traitName => $condition) {
                // Check if the model uses this trait
                if (class_exists($this->class) && in_array($traitName, $this->class_uses_recursive($this->class))) {
                    $column = $condition['column'];
                    $value = $condition['value'];

                    // For null comparisons
                    if ($value === null) {
                        $this->whereConditions[] = [
                            'column' => $column,
                            'value' => 'IS NULL',
                            'operator' => 'AND',
                            'is_raw' => true // Mark as raw SQL to avoid quoting
                        ];
                    } else {
                        $this->whereConditions[] = [
                            'column' => $column,
                            'value' => $value,
                            'operator' => 'AND'
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
                // Only add the operator for conditions after the first one
                $prefix = ($index > 0) ? $condition['operator'] . ' ' : '';

                // Check if this is a raw SQL condition
                if (isset($condition['is_raw']) && $condition['is_raw']) {
                    $conditions[] = $prefix . $condition['column'] . " " . $condition['value'];
                } else {
                    $conditions[] = $prefix . $condition['column'] . "='" . $condition['value'] . "'";
                }
            }

            // Process LIKE conditions
            foreach ($this->likeConditions as $index => $condition) {
                // If we already have WHERE conditions or this is not the first LIKE condition,
                // prepend the operator
                $prefix = (!empty($this->whereConditions) || $index > 0) ? $condition['operator'] . ' ' : '';
                $conditions[] = $prefix . $condition['column'] . " LIKE '%" . $condition['value'] . "%'";
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

        return $query;
    }

    // Register a condition that will be applied automatically when querying models
    public static function registerTraitCondition(string $traitName, string $column, $value): void
    {
        self::$traitConditions[$traitName] = [
            'column' => $column,
            'value' => $value
        ];
    }

    /**
     * Get all traits used by a class, including traits used by parent classes
     *
     * @param string $class
     * @return array
     */
    private function class_uses_recursive(string $class): array
    {
        $traits = [];

        // Get traits of the current class
        $className = is_object($class) ? get_class($class) : $class;
        $traits = class_uses($className) ?: [];

        // Get traits of all parent classes
        $parentClass = get_parent_class($className);
        if ($parentClass) {
            $traits = array_merge($this->class_uses_recursive($parentClass), $traits);
        }

        return $traits;
    }
}