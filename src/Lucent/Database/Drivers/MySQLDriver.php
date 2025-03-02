<?php

namespace Lucent\Database\Drivers;

use Lucent\Database\DatabaseInterface;
use Lucent\Facades\App;
use Lucent\Facades\Log;
use mysqli;
use mysqli_sql_exception;


class MySQLDriver extends DatabaseInterface
{
    private mysqli $connection;
    private array $typeMap;


    public function __construct()
    {
        parent::__construct();

        $this->connection = $this->createConnection();

        $this->typeMap = [
            LUCENT_DB_BINARY => "binary",
            LUCENT_DB_TINYINT => "tinyint",
            LUCENT_DB_DECIMAL => "decimal",
            LUCENT_DB_INT => "int",
            LUCENT_DB_JSON => "json",
            LUCENT_DB_TIMESTAMP => "timestamp",
            LUCENT_DB_ENUM => "enum",
            LUCENT_DB_DATE => "date",
            LUCENT_DB_TEXT => "text",
            LUCENT_DB_VARCHAR => "varchar",
            LUCENT_DB_BOOLEAN => "tinyint",
            LUCENT_DB_FLOAT => "float",
            LUCENT_DB_DOUBLE => "double",
            LUCENT_DB_CHAR => "char",
            LUCENT_DB_LONGTEXT => "longtext",
            LUCENT_DB_MEDIUMTEXT => "mediumtext"
        ];

        $this->allowed_statement_prefix = [
            'CREATE TABLE', 'DROP TABLE', 'ALTER TABLE',
            'CREATE INDEX', 'DROP INDEX',
            'TRUNCATE TABLE', 'RENAME TABLE',
            'CREATE VIEW', 'DROP VIEW',
            'CREATE PROCEDURE', 'DROP PROCEDURE',
            'CREATE TRIGGER', 'DROP TRIGGER',
            'OPTIMIZE TABLE', 'SET', 'FLUSH'
        ];

        $this->allowed_insert_prefix = [
            "INSERT INTO",
        ];

        $this->allowed_delete_prefix = [
            "DELETE FROM",
        ];

        $this->allowed_update_prefix = [
            "UPDATE",
        ];

        $this->allowed_select_prefix = [
            "SELECT",
        ];
    }

    private function createConnection(): mysqli
    {
        $username = App::env("DB_USERNAME");
        $password = App::env("DB_PASSWORD");
        $host = App::env("DB_HOST");
        $port = App::env("DB_PORT");
        $database = App::env("DB_DATABASE");

        return new mysqli($host, $username, $password, $database, $port);
    }

    public function buildColumnString(array $column): string
    {
        if (in_array($column['TYPE'], [LUCENT_DB_TINYINT, LUCENT_DB_INT, LUCENT_DB_FLOAT, LUCENT_DB_DOUBLE, LUCENT_DB_DECIMAL,LUCENT_DB_BOOLEAN]) &&
            isset($column['DEFAULT']) && $column['DEFAULT'] == '') {
            // Replace empty string default with 0 for numeric types
            $column['DEFAULT'] = 0;
        }

        $string = match ($column["TYPE"]) {
            LUCENT_DB_DECIMAL => "`" . $column["NAME"] . "` " . $this->typeMap[$column["TYPE"]] . "(20,2)",
            LUCENT_DB_JSON, LUCENT_DB_TIMESTAMP, LUCENT_DB_DATE => "`" . $column["NAME"] . "` " . $this->typeMap[$column["TYPE"]],
            LUCENT_DB_ENUM => "`" . $column["NAME"] . "` " . $this->typeMap[$column["TYPE"]] . $this->buildValues($column["VALUES"]),
            default => "`" . $column["NAME"] . "` " . $this->typeMap[$column["TYPE"]] . "(" . $column["LENGTH"] . ")",
        };


        if (!$column["ALLOW_NULL"]) {
            $string .= " NOT NULL";
        }

        if ($column["AUTO_INCREMENT"]) {
            $string .= " AUTO_INCREMENT";
        }

        if ($column["DEFAULT"] !== null) {
            if ($column["DEFAULT"] !== LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP) {
                $string .= " DEFAULT '" . $column["DEFAULT"] . "'";
            } else {
                $string .= " DEFAULT " . $column["DEFAULT"];
            }
        }

        return $string;
    }

    public function createTable(string $name, array $columns): string
    {
        $query = "CREATE TABLE `{$name}` (";
        $constraints = [];

        foreach ($columns as $column) {
            $query .= $this->buildColumnString($column);

            if ($column["UNIQUE"] !== null) {
                $constraints[] = "UNIQUE (`" . $column["NAME"] . "`)";
            }

            if ($column["REFERENCES"] !== null) {
                $constraints[] = "FOREIGN KEY (`" . $column["NAME"] . "`) REFERENCES " . $column["REFERENCES"];
            }

            $query .= ",";
        }

        // Always remove the trailing comma from column definitions
        $query = rtrim($query, ",");

        // Add primary key
        $primaryKey = array_filter($columns, fn($col) => $col["PRIMARY_KEY"] ?? false);
        if (!empty($primaryKey)) {
            $pkColumn = reset($primaryKey);
            $constraints[] = "PRIMARY KEY (`" . $pkColumn["NAME"] . "`)";
        }

        // Add constraints if we have any
        if (!empty($constraints)) {
            $query .= "," . implode(",", $constraints);
        }

        $query .= ");";
        return $query;
    }
    public function getTypeMap(): array
    {
        return $this->typeMap;
    }

    private function buildValues(array $values): string
    {
        return "('" . implode("','", $values) . "')";
    }

    public function lastInsertId(): string|int
    {
        return $this->connection->insert_id;
    }

    //Query Execution functions
    public function statement(string $query): bool
    {
        if (!$this->validator->statementIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        try {
            $result = $this->connection->query($query);

            // Only false indicates failure, 0 is valid for successful DDL
            if ($result === false) {
                $errorInfo = $this->connection->error;
                throw new \Exception($errorInfo[2] ?? 'Unknown database error');
            }

            return true;
        } catch (mysqli_sql_exception $e) {
            throw new \Exception("Database error: " . $e->getMessage());
        }
    }

    public function insert(string $query): bool
    {

        if(!$this->validator->insertIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        $result = $this->connection->query($query);
        return $result !== false && $this->connection->affected_rows > 0;
    }

    public function delete($query): bool
    {
        if(!$this->validator->deleteIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        return $this->connection->query($query)->num_rows > 0;
    }

    public function update($query): bool
    {
        if(!$this->validator->updateIsAllowed($query)) {
            throw new \Exception("Invalid statement, {$query} is not allowed to execute.");
        }

        $result = $this->connection->query($query);
        return $result !== false && $this->connection->affected_rows > 0;    }

    public function select(string $query, bool $fetchAll = false): null|array
    {
        Log::channel("db")->info($query);
        $result = $this->connection->query($query);

        if (!$result) {
            return $fetchAll ? [] : null;
        }

        if ($fetchAll) {
            // Process multiple rows with column names
            $results = $result->fetch_all();
            $fields = $result->fetch_fields();

            $output = [];
            foreach ($results as $row) {
                $processedRow = [];
                $columnId = 0;
                foreach ($row as $column) {
                    $processedRow[$fields[$columnId]->name] = $column;
                    $columnId++;
                }
                $output[] = $processedRow;
            }
            return $output;
        } else {
            // Single row
            $row = $result->fetch_assoc();
            return $row !== null ? $row : [];
        }
    }

    //Table functions
    public function hasTable(string $name): bool
    {
        $dbName = App::env("DB_DATABASE");
        $query = "SELECT 1 FROM information_schema.tables 
              WHERE table_schema = '$dbName' 
              AND table_name = '$name'";
        $result = $this->connection->query($query);
        return $result->num_rows > 0;
    }
    public function hasColumn(string $table, array|string $column): bool
    {
        try {
            // Verify the table exists first
            if (!$this->hasTable($table)) {
                return false;
            }

            $dbName = App::env("DB_DATABASE");

            // Handle single column (string) check
            if (is_string($column)) {
                $query = "SELECT 1 FROM information_schema.columns 
                     WHERE table_schema = '$dbName' 
                     AND table_name = '$table'
                     AND column_name = '$column'";
                $result = $this->connection->query($query);
                return $result && $result->num_rows > 0;
            }

            // Handle multiple columns (array) check
            if (is_array($column)) {
                // Get all columns for this table
                $query = "SELECT column_name FROM information_schema.columns 
                     WHERE table_schema = '$dbName' 
                     AND table_name = '$table'";
                $result = $this->connection->query($query);

                if (!$result) {
                    return false;
                }

                // Convert result to a simple array of column names
                $existingColumns = [];
                while ($row = $result->fetch_assoc()) {
                    $existingColumns[] = $row['column_name'];
                }

                // Check if every requested column exists
                foreach ($column as $col) {
                    if (!in_array($col, $existingColumns)) {
                        return false;
                    }
                }
                return true;
            }

            return false;
        } catch (mysqli_sql_exception $e) {
            Log::channel("db")->error("Error checking column existence: " . $e->getMessage());
            return false;
        }
    }

    public function getAutoincrementId(): int
    {
        return $this->connection->insert_id;
    }

    public function transaction(callable $callback): bool
    {
        $this->connection->begin_transaction();
        call_user_func($callback);
        $result = $this->connection->commit();
        if(!$result){
            $this->connection->rollBack();
        }
        return $result;
    }
}

