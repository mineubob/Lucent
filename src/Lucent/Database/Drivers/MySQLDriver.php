<?php

namespace Lucent\Database\Drivers;

use Lucent\Database\DatabaseInterface;
use Lucent\Facades\App;
use Lucent\Facades\Log;
use mysqli;


class MySQLDriver extends DatabaseInterface
{
    private mysqli $connection;
    private array $typeMap;


    public function __construct()
    {
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
    }

    public function query(string $query): bool
    {
        Log::channel("db")->info($query);
        return (bool)$this->connection->query($query);
    }

    public function fetch(string $query): array
    {
        Log::channel("db")->info($query);
        $results = $this->connection->query($query)->fetch_assoc();
        return $results !== null ? $results : [];
    }

    public function fetchAll(string $query): array
    {
        Log::channel("db")->info($query);
        $query = $this->connection->query($query);
        $results = $query->fetch_all();
        $fields = $query->fetch_fields();

        $output = [];
        foreach ($results as $result) {
            $row = [];
            $columnId = 0;
            foreach ($result as $column) {
                $row[$fields[$columnId]->name] = $column;
                $columnId++;
            }
            $output[] = $row;
        }
        return $output;
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

        // Add primary key
        $primaryKey = array_filter($columns, fn($col) => $col["PRIMARY_KEY"] ?? false);
        if (!empty($primaryKey)) {
            $pkColumn = reset($primaryKey);
            $constraints[] = "PRIMARY KEY (`" . $pkColumn["NAME"] . "`)";
        }

        if (!empty($constraints)) {
            $query .= implode(",", $constraints);
        } else {
            $query = rtrim($query, ",");
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


    public function tableExists(string $tableName): bool
    {
        $dbName = App::env("DB_DATABASE");
        $query = "SELECT 1 FROM information_schema.tables 
              WHERE table_schema = '$dbName' 
              AND table_name = '$tableName'";
        $result = $this->connection->query($query);
        return $result->num_rows > 0;
    }


}

