<?php

namespace Lucent\Database;

abstract class DatabaseInterface
{
    public protected(set) array $allowed_statement_prefix;
    public protected(set) array $allowed_delete_prefix;
    public protected(set) array $allowed_insert_prefix;
    public protected(set) array $allowed_update_prefix;
    public protected(set) array $allowed_select_prefix;

    protected DatabaseValidator $validator;

    public function __construct()
    {
        $this->validator = new DatabaseValidator($this);
    }

    #[\Deprecated("Prefer use of the Schema::table function directly.")]
    abstract public function createTable(string $name, array $columns): string;
    abstract public function lastInsertId(): string|int;

    //Query Execution function
    abstract public function statement(string $query, array $params = []): bool;
    abstract public function insert(string $query, array $params = []): bool;
    abstract public function delete(string $query, array $params = []): bool;
    abstract public function update(string $query, array $params = []): bool;
    abstract public function select(string $query, bool $fetchAll = true, array $params = []): ?array;
    abstract public function transaction(callable $callback): bool;

    abstract public function getDriverName(): string;
    abstract public function closeDriver(): bool;
}