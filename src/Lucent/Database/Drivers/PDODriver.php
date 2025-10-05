<?php

namespace Lucent\Database\Drivers;

use Lucent\Database\DatabaseInterface;
use Lucent\Database\Schema;
use Lucent\Facades\App;
use Lucent\Facades\Log;
use Lucent\Filesystem\File;
use PDO;

class PDODriver extends DatabaseInterface
{

    private PDO $connection;

    public static array $map = [
        "mysql" => [
            "types" => [
                "binary"=> "binary",
                "tinyint" => "tinyint",
                "decimal" => "decimal",
                "int" => "int",
                "bigint" => "bigint",
                "json"=> "json",
                "timestamp" => "timestamp",
                "enum"=> "enum",
                "date"=> "date",
                "text" => "text",
                "varchar" => "varchar",
                "bool" => "tinyint",
                "float" => "float",
                "double" => "double",
                "char" => "char",
                "longtext"=> "longtext",
                "mediumtext" => "mediumtext"
            ],
            "functions" =>[
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
                "mediumtext" => "TEXT"
            ],
            "functions" =>[
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

    public function __construct(){
        parent::__construct();

        // Check if database name is set
        if (empty(App::env("DB_DRIVER"))) {
            throw new \Exception("DB_DRIVER environment variable is not set or empty");
        }

        if(App::env("DB_DRIVER") === "sqlite"){

            $file = new File(App::env('DB_DATABASE'));

            if(!$file->exists()){
                $file->create();
                $file->setPermissions(0666);
            }

            // Verify file is writable
            if (!is_writable($file->path)) {
                throw new \RuntimeException("SQLite database file is not writable: " . $path);
            }

            $dsn = "sqlite:".$file->path;
            $this->connection = new PDO($dsn);

        }else{
            $host = App::env("DB_HOST");
            $database = App::env("DB_DATABASE");
            $username = App::env("DB_USERNAME");
            $password = App::env("DB_PASSWORD");
            $port = App::env("DB_PORT") ?: "3306";

            $dsn = "mysql:host={$host};port={$port};dbname={$database}";
            $this->connection = new PDO($dsn, $username, $password);

            //SQL only
            $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        Log::channel("db")->debug("PDO created for ".App::env("DB_DRIVER"));
    }

    public function getDriverName(): string
    {
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function createTable(string $name, array $columns): string
    {
        //Dynamically generate our table
        return Schema::table($name,function($table) use ($columns) {

            //Foreach of our column attributes, transform them into table columns
            foreach ($columns as $column){

                $name = $column["NAME"];
                $type = $column["TYPE"];
                $column["nullable"] = $column["ALLOW_NULL"];

                $tableColumn = $table->$type($name);
                $keysToExclude = ["NAME", "UNIQUE_KEY_TO", "ON_UPDATE", "TYPE","ALLOW_NULL"];

                if(!($tableColumn instanceof Schema\NumericColumn)){
                    $keysToExclude[] = "AUTO_INCREMENT";
                    $keysToExclude[] = "UNSIGNED";
                }

                //Remove our excluded keys
                $diff = $this->getValuesNotInArrayAsMap($column,$keysToExclude);

                //For each of our remaining column properties, call the method
                foreach ($diff as $key => $value) {

                    //Methods need to be converted to camel case before being called.
                    $key = lcfirst(str_replace('_', '', ucwords(strtolower($key), '_')));
                    $tableColumn->$key($value);
                }

            }

        })->toSql();
    }

    public function getTypeMap(): array
    {
        return [];
    }

    public function lastInsertId(): string|int
    {
        return $this->connection->lastInsertId();
    }

    public function statement(string $query, array $params = []): bool
    {
        if (count($params) > 0) {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        }

        return $this->connection->exec($query) !== false;
    }

    public function insert(string $query, array $params = []): bool
    {
        return $this->statement($query, $params);
    }

    public function delete(string $query,array $params = []): bool
    {
        return $this->statement($query, $params);
    }

    public function update(string $query,array $params = []): bool
    {
        return $this->statement($query, $params);
    }

    public function select(string $query, bool $fetchAll = true,array $params = []): ?array
    {
        if (count($params) > 0) {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
        }else{
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
            throw $e;
        }
        $result = $this->connection->commit();

        if (!$result) {
            $this->connection->rollBack();
        }
        return $result;
    }

    public function hasTable(string $name): bool
    {
        return Schema::table($name)->exists();
    }

    public function hasColumn(string $table, array|string $column): bool
    {
        if(is_array($column)){
            if (array_any($column, fn($item) => !Schema::table($table)->column($item)->exists())) {
                return false;
            }
        }

       return Schema::table($table)->column($column)->exists();
    }

    function getValuesNotInArrayAsMap(array $sourceMap, array $excludeArray): array
    {
        // Create a temporary array from the excludeArray where values are keys
        // This allows for efficient key comparison with array_diff_key
        $excludeKeys = array_flip($excludeArray);

        // Get the keys from the source map that are NOT present in the excludeKeys
        $diffKeys = array_diff_key($sourceMap, $excludeKeys);

        // Use array_intersect_key to get the original key-value pairs from the source map
        // for only the keys that were not found in the exclude array
        $resultMap = array_intersect_key($sourceMap, $diffKeys);

        return $resultMap;
    }


}