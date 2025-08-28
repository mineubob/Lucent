<?php

namespace Lucent\Database\Drivers;

use Lucent\Database\DatabaseInterface;
use Lucent\Facades\App;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use PDO;

class SQLiteDriver extends DatabaseInterface
{
    private PDO $connection;
    public private(set) array $typeMap;

    public function __construct()
    {
        parent::__construct();

        $this->connection = $this->createConnection();

        $this->typeMap = [
            LUCENT_DB_BINARY => "BLOB",
            LUCENT_DB_TINYINT => "INTEGER",
            LUCENT_DB_DECIMAL => "REAL",
            LUCENT_DB_INT => "INTEGER",
            LUCENT_DB_BIGINT => "INTEGER",
            LUCENT_DB_JSON => "TEXT",
            LUCENT_DB_TIMESTAMP => "DATETIME",
            LUCENT_DB_ENUM => "TEXT",
            LUCENT_DB_DATE => "DATE",
            LUCENT_DB_TEXT => "TEXT",
            LUCENT_DB_VARCHAR => "TEXT",
            LUCENT_DB_BOOLEAN => "INTEGER",
            LUCENT_DB_FLOAT => "REAL",
            LUCENT_DB_DOUBLE => "REAL",
            LUCENT_DB_CHAR => "TEXT",
            LUCENT_DB_LONGTEXT => "TEXT",
            LUCENT_DB_MEDIUMTEXT => "TEXT"
        ];

        $this->allowed_statement_prefix = [
            'CREATE TABLE',
            'DROP TABLE',
            'ALTER TABLE',
            'CREATE INDEX',
            'DROP INDEX',
            'VACUUM',
            'ANALYZE',
            'PRAGMA',
            'ATTACH DATABASE',
            'DETACH DATABASE'
        ];

        $this->allowed_insert_prefix = [
            "INSERT INTO",
        ];

        $this->allowed_delete_prefix = [
            "DELETE",
        ];

        $this->allowed_update_prefix = [
            "UPDATE",
        ];

        $this->allowed_select_prefix = [
            "SELECT",
        ];
    }

    private function createConnection(): PDO
    {
        $database = App::env("DB_DATABASE");
        $fullPath = FileSystem::rootPath().DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . $database;
        $this->ensureSQLiteFileExists($fullPath);
        return new PDO("sqlite:" . $fullPath);
    }

    private function ensureSQLiteFileExists(string $path): void
    {

        if (!is_dir(FileSystem::rootPath().DIRECTORY_SEPARATOR . "storage")) {
            var_dump("STORAGE NOT CREATED");
        }

        if (!file_exists($path)) {
            $directory = dirname($path);

            Log::channel("db")->info($directory);

            // Create directory if it doesn't exist
            if (!is_dir($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    throw new \RuntimeException("Failed to create SQLite directory: " . $directory);
                }
            }

            // Create empty SQLite file
            if (!touch($path)) {
                throw new \RuntimeException("Failed to create SQLite database file: " . $path);
            }

            // Set proper permissions
            if (!chmod($path, 0666)) {
                throw new \RuntimeException("Failed to set permissions on SQLite database file: " . $path);
            }
        }

        // Verify file is writable
        if (!is_writable($path)) {
            throw new \RuntimeException("SQLite database file is not writable: " . $path);
        }
    }


    public function buildColumnString(array $column): string
    {
        // Special case for auto-incrementing primary keys
        if ($column["PRIMARY_KEY"] && $column["AUTO_INCREMENT"] && $column["TYPE"] === LUCENT_DB_INT) {
            return "`" . $column["NAME"] . "` INTEGER PRIMARY KEY AUTOINCREMENT";
        }

        // Get the base type (SQLite doesn't use length for INTEGER types)
        $sqliteType = $this->typeMap[$column["TYPE"]];

        // Build the column definition
        $string = "`" . $column["NAME"] . "` " . $sqliteType;

        // Only add length for non-INTEGER types that support it
        if (
            isset($column["LENGTH"]) &&
            $sqliteType !== "INTEGER" &&
            !in_array($sqliteType, ["TEXT", "BLOB", "REAL", "DATETIME", "DATE"])
        ) {
            $string .= "(" . $column["LENGTH"] . ")";
        }

        // Add NULL constraint
        if (!$column["ALLOW_NULL"]) {
            $string .= " NOT NULL";
        }

        if (isset($column["UNSIGNED"]) && $column["UNSIGNED"] &&
            in_array($column["TYPE"], [LUCENT_DB_TINYINT, LUCENT_DB_INT, LUCENT_DB_BIGINT, LUCENT_DB_FLOAT, LUCENT_DB_DOUBLE])) {
            // SQLite doesn't have UNSIGNED, but we can add a CHECK constraint for positive values
            $string .= " CHECK(" . $column["NAME"] . " >= 0)";
        }

        // Add default value
        if ($column["DEFAULT"] !== null) {
            if ($column["DEFAULT"] === LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP) {
                $string .= " DEFAULT CURRENT_TIMESTAMP";
            } else {
                // Handle empty string defaults for INTEGER columns
                if ($sqliteType === "INTEGER" && $column["DEFAULT"] === '') {
                    $string .= " DEFAULT 0";
                } else {
                    $string .= " DEFAULT '" . $column["DEFAULT"] . "'";
                }
            }
        }

        // Add unique constraint
        if ($column["UNIQUE"] !== null) {
            $string .= " UNIQUE";
        }

        return $string;
    }
    public function createTable(string $name, array $columns): string
    {
        $query = "CREATE TABLE `{$name}` (";
        $constraints = [];

        foreach ($columns as $column) {
            $query .= $this->buildColumnString($column) . ",";

            if ($column["REFERENCES"] !== null) {
                $constraints[] = "FOREIGN KEY (`" . $column["NAME"] . "`) REFERENCES " . $column["REFERENCES"];
            }
        }

        // Remove trailing comma
        $query = rtrim($query, ",");

        if (!empty($constraints)) {
            $query .= ", " . implode(", ", $constraints);
        }

        $query .= ");";
        return $query;
    }

    public function getTypeMap(): array
    {
        return $this->typeMap;
    }

    public function hasTable(string $name): bool
    {
        $query = "SELECT 1 FROM sqlite_master 
              WHERE type='table' AND name = '$name'";
        $statement = $this->connection->query($query);
        return $statement && $statement->fetchColumn() !== false;
    }

    public function hasColumn(string $table, array|string $column): bool
    {
        try {
            // Verify the table exists first
            if (!$this->hasTable($table)) {
                return false;
            }

            // Get all columns from the table
            $stmt = $this->connection->prepare("PRAGMA table_info(:table)");
            $stmt->execute(['table' => $table]);
            $tableColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Extract just the column names
            $existingColumns = array_column($tableColumns, 'name');

            // Handle single column (string) check
            if (is_string($column)) {
                return in_array($column, $existingColumns);
            }

            // Handle multiple columns (array) check
            if (is_array($column)) {
                // Check if every requested column exists
                foreach ($column as $col) {
                    if (!in_array($col, $existingColumns)) {
                        return false;
                    }
                }
                return true;
            }

            return false;
        } catch (\PDOException $e) {
            Log::channel("db")->error("Error checking column existence: " . $e->getMessage());
            return false;
        }
    }

    public function lastInsertId(): string|int
    {
        return $this->connection->lastInsertId();
    }

    public function statement(string $query): bool
    {
        if (!$this->validator->statementIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        try {
            $result = $this->connection->exec($query);

            // Only false indicates failure, 0 is valid for successful DDL
            if ($result === false) {
                $errorInfo = $this->connection->errorInfo();
                throw new \Exception($errorInfo[2] ?? 'Unknown database error');
            }

            return true;
        } catch (\PDOException $e) {
            throw new \Exception("Database error: " . $e->getMessage());
        }
    }

    public function insert(string $query): bool
    {

        if (!$this->validator->insertIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        return $this->connection->exec($query) > 0;
    }

    public function delete($query): bool
    {
        if (!$this->validator->deleteIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        return $this->connection->exec($query) > 0;
    }

    public function update($query): bool
    {
        try {
            // THIS IS THE CRITICAL BUG - Using deleteIsAllowed instead of updateIsAllowed
            if (!$this->validator->updateIsAllowed($query)) {
                Log::channel("db")->error("Update query not allowed: " . $query);
                throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
            }

            $result = $this->connection->exec($query);
            // Don't require affected rows > 0, just require no error
            return $result !== false;
        } catch (\Exception $e) {
            Log::channel("db")->error("Exception in SQLiteDriver::update: " . $e->getMessage());
            throw $e; // Rethrow so Database::update can catch it
        }
    }
    public function select(string $query, bool $fetchAll = true): ?array
    {
        Log::channel("db")->info($query);
        $statement = $this->connection->query($query);

        if (!$statement) {
            return null;
        }

        if ($fetchAll) {
            $results = $statement->fetchAll(PDO::FETCH_ASSOC);
            return empty($results) ? null : $results;
        } else {
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        }
    }

    public function transaction(callable $callback, ...$args): bool
    {
        if (!$this->connection->beginTransaction()) {
            return false;
        }

        try {
            $result = call_user_func_array($callback, $args);
            if ($result === false) {
                $this->connection->rollback();
                return $result;
            }
        } catch (\Exception $e) {
            $this->connection->rollback();

            throw $e;
        }

        $result = $this->connection->commit();
        if (!$result) {
            $this->connection->rollBack();
        }

        return $result;
    }

    public function getAutoincrementId(): int
    {
        return $this->connection->lastInsertId();
    }


}
