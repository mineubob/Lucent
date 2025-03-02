<?php

namespace Lucent\Database;

class DatabaseValidator
{

    private DatabaseInterface $driver;

    public function __construct(DatabaseInterface $driver)
    {
        $this->driver = $driver;
    }

    public function statementIsAllowed(string $sql): bool
    {
        return array_any($this->driver->allowed_statement_prefix, fn($prefix) => str_starts_with(strtoupper(trim($sql)), $prefix));
    }

    public function insertIsAllowed(string $sql): bool
    {
        return array_any($this->driver->allowed_insert_prefix, fn($prefix) => str_starts_with(strtoupper(trim($sql)), $prefix));
    }

    public function deleteIsAllowed(string $sql): bool
    {
        return array_any($this->driver->allowed_delete_prefix, fn($prefix) => str_starts_with(strtoupper(trim($sql)), $prefix));
    }

    public function updateIsAllowed(string $sql): bool
    {
        return array_any($this->driver->allowed_update_prefix, fn($prefix) => str_starts_with(strtoupper(trim($sql)), $prefix));
    }

    public function selectIsAllowed(string $sql): bool
    {
        return array_any($this->driver->allowed_select_prefix, fn($prefix) => str_starts_with(strtoupper(trim($sql)), $prefix));
    }

}