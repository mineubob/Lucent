<?php

namespace Lucent\Database;


abstract class DatabaseInterface
{

    abstract public function query(string $query): bool|array;
    abstract public function fetch(string $query): array;
    abstract public function fetchAll(string $query): array;
    abstract public function createTable(string $name, array $columns): string;
    abstract public function getTypeMap() :array;
    abstract public function tableExists(string $dbName): bool;
    abstract public function lastInsertId(): string|int;
}