<?php

namespace Lucent\Database;

abstract class DatabaseInterface
{
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