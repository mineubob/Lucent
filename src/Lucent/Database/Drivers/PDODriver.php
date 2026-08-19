<?php

namespace Lucent\Database\Drivers;

use Lucent\Database;
use Lucent\Database\DatabaseInterface;
use Lucent\Facades\FileSystem;
use PDO;

class PDODriver extends DatabaseInterface
{

    private ?PDO $connection;

    public static array $map = [
        "mysql" => [
            "types" => [
                "binary" => "binary",
                "tinyint" => "tinyint",
                "decimal" => "decimal",
                "int" => "int",
                "bigint" => "bigint",
                "json" => "json",
                "timestamp" => "timestamp",
                "enum" => "enum",
                "date" => "date",
                "text" => "text",
                "varchar" => "varchar",
                "bool" => "tinyint",
                "float" => "float",
                "double" => "double",
                "char" => "char",
                "longtext" => "longtext",
                "mediumtext" => "mediumtext",
                "uuid" => "char"
            ],
            "functions" => [
                "foreign_key_checks" => [
                    "disable" => "SET FOREIGN_KEY_CHECKS=0",
                    "enable" => "SET FOREIGN_KEY_CHECKS=1"
                ],
                "column_exists" => "SELECT COUNT(*) > 0 FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = `{table}` 
                AND COLUMN_NAME = ?",
                "list_tables" => "SHOW TABLES",
                "drop_table" => "DROP TABLE IF EXISTS `{table}`",
                "table_exists" => "SELECT COUNT(*) > 0 FROM information_schema.TABLES 
                          WHERE TABLE_SCHEMA = DATABASE() 
                          AND TABLE_NAME = ?"
            ]
        ],
        "sqlite" => [
            "types" => [
                "binary" => "BLOB",
                "tinyint" => "INTEGER",
                "decimal" => "REAL",
                "int" => "INTEGER",
                "bigint" => "INTEGER",
                "json" => "TEXT",
                "timestamp" => "DATETIME",
                "enum" => "TEXT",
                "date" => "DATE",
                "text" => "TEXT",
                "varchar" => "TEXT",
                "bool" => "INTEGER",
                "float" => "REAL",
                "double" => "REAL",
                "char" => "TEXT",
                "longtext" => "TEXT",
                "mediumtext" => "TEXT",
                "uuid" => "TEXT"
            ],
            "functions" => [
                "foreign_key_checks" => [
                    "disable" => "PRAGMA foreign_keys = OFF",
                    "enable" => "PRAGMA foreign_keys = ON"
                ],
                "column_exists" => "SELECT COUNT(*) > 0 FROM pragma_table_info('{table}') WHERE name = ?",
                "list_tables" => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';",
                "drop_table" => "DROP TABLE IF EXISTS `{table}`",
                "table_exists" => "SELECT name FROM sqlite_master WHERE type='table' AND name=?"
            ]
        ]
    ];

    public function __construct()
    {
        // Check if database name is set
        if (empty(Database::env("DB_DRIVER"))) {
            Database::log("critical","[PDODriver] DB_DRIVER environment variable is not set or empty");
            throw new \Exception("[PDODriver] DB_DRIVER environment variable is not set or empty");
        }

        $driver_name = Database::env("DB_DRIVER");

        switch ($driver_name) {
            case "sqlite":
                $path = Database::env('DB_DATABASE');

                // In-memory SQLite database: no file I/O, isolated per connection.
                if ($path === ':memory:') {
                    $this->connection = new PDO('sqlite::memory:');
                    break;
                }

                if(str_starts_with($path, DIRECTORY_SEPARATOR)) {
                    $path = ltrim($path, DIRECTORY_SEPARATOR);
                }

                $path = rtrim(FileSystem::rootPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;

                if (!file_exists($path)) {
                    touch($path);
                    // Restrict to owner read/write — a world-writable (0666)
                    // DB file lets any local user read or corrupt it on a
                    // shared host.
                    chmod($path, 0600);
                }

                // Verify file is writable
                if (!is_writable($path)) {
                    Database::log("critical","[PDODriver] SQLite database file is not writable: $path");
                    throw new \RuntimeException("[PDODriver] SQLite database file is not writable: $path");
                }

                $this->connection = new PDO("sqlite:$path");
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                break;
            case "mysql":
                $host = Database::env("DB_HOST");
                $database = Database::env("DB_DATABASE");
                $username = Database::env("DB_USERNAME");
                $password = Database::env("DB_PASSWORD");
                $port = Database::env("DB_PORT") ?: "3306";

                $dsn = "mysql:host={$host};port={$port};dbname={$database}";
                $this->connection = new PDO($dsn, $username, $password);
                // Use native prepared statements (not client-side emulation)
                // and throw on errors. Emulated prepares interpolate bound
                // values client-side, weakening injection protection and
                // permitting stacked-query edge cases.
                $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                break;
            default:
                Database::log("critical","[PDODriver] Unknown driver type provided: $driver_name");
                throw new \RuntimeException("[PDODriver] Unknown driver type provided: $driver_name");
        }

    }

    public function getDriverName(): string
    {
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function lastInsertId(): string|int
    {
        return $this->connection->lastInsertId();
    }

    public function statement(string $query, array $params = []): bool
    {
        Database::log("info","[PDODriver] Executing statement: $query");

        try {
            if (count($params) > 0) {
                $stmt = $this->connection->prepare($query);

                if (!$stmt) {
                    Database::log("critical","[PDODriver] Failed to prepare statement: $query");
                    return false;
                }

                $result = $stmt->execute($params);

                if (!$result) {
                    Database::log("critical","[PDODriver] Failed to execute statement: \n$query\nError:".print_r($stmt->errorInfo(),true)."\nParams:".print_r($params,true));
                    return false;
                }

                // For INSERT/UPDATE/DELETE, we want to know if it succeeded
                // rowCount() can be 0 for successful insert in some cases
                return true;
            }

            $result = $this->connection->exec($query);


            if ($result === false) {
                Database::log("info","[PDODriver] Failed to execute statement: \n$query\nParams:".print_r($params,true));
            }

            return $result !== false;
        } catch (\PDOException $e) {

            Database::log("info","[PDODriver] Failed to execute statement: \n$query\nError:".print_r($e->getMessage(),true)."\nParams:".print_r($params,true));
            return false;
        }
    }

    public function insert(string $query, array $params = []): bool
    {
        return $this->statement($query, $params);
    }

    public function delete(string $query, array $params = []): bool
    {
        return $this->statement($query, $params);
    }

    public function update(string $query, array $params = []): bool
    {
        return $this->statement($query, $params);
    }

    public function select(string $query, bool $fetchAll = true, array $params = []): ?array
    {
        Database::log("info","[PDODriver] Executing select: $query");

        if (count($params) > 0) {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
        } else {
            $stmt = $this->connection->query($query);
        }


        if ($fetchAll) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $results = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $stmt->closeCursor();
        return $results;
    }

    public function transaction(callable $callback, ...$args): bool
    {
        if (!$this->connection->beginTransaction()) {
            return false;
        }
        try {
            $result = call_user_func_array($callback, $args);
            if ($result === false) {
                $this->connection->rollBack();
                return false;
            }
        } catch (\Exception $e) {
            $this->connection->rollBack();
            Database::log("error","[PDODriver] Failed to execute transaction\n"."Error:".print_r($e->getMessage(),true));
            throw $e;
        }
        $result = $this->connection->commit();

        if (!$result) {
            $this->connection->rollBack();
        }
        return $result;
    }

    public function closeDriver(): bool
    {
        $this->connection = null;
        return true;
    }
}