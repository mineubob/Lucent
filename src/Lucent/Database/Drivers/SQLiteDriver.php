<?php

namespace Lucent\Database\Drivers;

use Lucent\Database\DatabaseInterface;
use Lucent\Facades\App;
use Lucent\Facades\Log;
use PDO;

class SQLiteDriver extends DatabaseInterface
{
    private PDO $connection;
    public private(set) array $typeMap;


    public function __construct()
    {
        $this->connection = $this->createConnection();

        $this->typeMap = [
            LUCENT_DB_BINARY => "BLOB",
            LUCENT_DB_TINYINT => "INTEGER",
            LUCENT_DB_DECIMAL => "DECIMAL",
            LUCENT_DB_INT => "INTEGER",
            LUCENT_DB_JSON => "TEXT",
            LUCENT_DB_TIMESTAMP => "DATETIME",
            LUCENT_DB_ENUM => "TEXT",
            LUCENT_DB_DATE => "DATE",
            LUCENT_DB_TEXT => "TEXT",
            LUCENT_DB_VARCHAR => "TEXT"
        ];
    }

    public function query(string $query): bool
    {
        Log::channel("db")->info($query);
        $statement = $this->connection->query($query);
        return $statement !== false;
    }

    public function fetch(string $query): array
    {
        Log::channel("db")->info($query);
        $statement = $this->connection->query($query);
        $results = $statement ? $statement->fetch(PDO::FETCH_ASSOC) : null;
        return $results !== null ? $results : [];
    }

    public function fetchAll(string $query): array
    {
        Log::channel("db")->info($query);
        $statement = $this->connection->query($query);
        return $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    private function createConnection(): PDO
    {
        $database = App::env("DB_DATABASE");
        $fullPath = TEMP_ROOT . "storage" . DIRECTORY_SEPARATOR . $database;
        $this->ensureSQLiteFileExists($fullPath);
        return new PDO("sqlite:" . $fullPath);
    }


    private function ensureSQLiteFileExists(string $path): void
    {

        if(!is_dir(TEMP_ROOT . "storage")) {
            var_dump("STORAGE NOT CREATED");
        }

        if (!file_exists($path)) {
            $directory = dirname($path);

            Log::channel("phpunit")->info($directory);

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

        $string = match ($column["TYPE"]) {
            LUCENT_DB_DECIMAL => "`" . $column["NAME"] . "` DECIMAL(20,2)",
            LUCENT_DB_JSON, LUCENT_DB_TIMESTAMP, LUCENT_DB_DATE => "`" . $column["NAME"] . "` " . $this->typeMap[$column["TYPE"]],
            default => "`" . $column["NAME"] . "` " . $this->typeMap[$column["TYPE"]] .
                (isset($column["LENGTH"]) ? "(" . $column["LENGTH"] . ")" : ""),
        };

        if (!$column["ALLOW_NULL"]) {
            $string .= " NOT NULL";
        }

        if ($column["DEFAULT"] !== null) {
            if ($column["DEFAULT"] !== LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP) {
                $string .= " DEFAULT '" . $column["DEFAULT"] . "'";
            } else {
                $string .= " DEFAULT CURRENT_TIMESTAMP";
            }
        }

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

}
