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

    public function __construct(){
        $this->validator = new DatabaseValidator($this);
    }

    abstract public function createTable(string $name, array $columns): string;
    abstract public function getTypeMap() :array;
    abstract public function lastInsertId(): string|int;

    //Query Execution function
    abstract public function statement(string $query): bool;
    abstract public function insert(string $query): bool;
    abstract public function delete(string $query): bool;
    abstract public function update(string $query): bool;
    abstract public function select(string $query,bool $fetchAll = true): ?array;
    abstract public function transaction(callable $callback): bool;

    //Table functions
    abstract public function hasTable(string $name): bool;
    abstract public function hasColumn(string $table,string|array $column): bool;

    abstract public function getAutoincrementId() : int;

}