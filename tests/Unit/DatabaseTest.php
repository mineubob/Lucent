<?php

namespace Unit;

use Exception;
use Lucent\Database;
use PHPUnit\Framework\Attributes\DataProvider;

// Manually require the DatabaseDriverSetup file
$driverSetupPath = __DIR__ . '/DatabaseDriverSetup.php';

if (file_exists($driverSetupPath)) {
    require_once $driverSetupPath;
}else{
    echo "Unable to locate $driverSetupPath\n";
    die;
}

class DatabaseTest extends DatabaseDriverSetup
{

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function databaseDriverProvider(): array
    {
        return [
            'sqlite' => ['sqlite', [
                'DB_DATABASE' => '/database.sqlite'
            ]],
            'mysql' => ['mysql', [
                'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
                'DB_PORT' => getenv('DB_PORT') ?: '3306',
                'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test_database',
                'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
                'DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''
            ]]
        ];
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_database_connection_explicit($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        // Force a connection and query
        try {
            if ($driver == 'mysql') {
                // Use a MySQL-specific query to ensure we're testing MySQL
                $result = Database::select("SELECT VERSION()");
            } else {
                // SQLite query
                $result = Database::select("SELECT name FROM sqlite_master WHERE type='table'");
            }

            // If we get here, the connection succeeded
            $this->assertTrue(true, "Connection to $driver successful");
        } catch (Exception $e) {
            // This is what should happen with invalid credentials
            $this->fail("Database connection failed: " . $e->getMessage());
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_statement_create_table_success($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        // Use the appropriate syntax based on the driver
        if ($driver === 'sqlite') {
            $query = 'CREATE TABLE IF NOT EXISTS test_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL
        )';
        } else {
            $query = 'CREATE TABLE IF NOT EXISTS test_users (
            id INT NOT NULL AUTO_INCREMENT,
            name TEXT NOT NULL,
            PRIMARY KEY (id)
        )';
        }

        $this->assertTrue(Database::statement($query));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_statement_failed_select_all($driver,$config) : void
    {
        self::setupDatabase($driver,$config);

        $this->test_statement_create_table_success($driver,$config);
        $query = 'SELECT * FROM test_users';
        try {
            Database::statement($query);
        }catch (Exception $e){
            $this->assertEquals("Invalid statement, SELECT * FROM test_users is not allowed to execute.",$e->getMessage());
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_statement_insert_success($driver,$config) : void
    {

        $this->test_statement_create_table_success($driver,$config);

        $query = 'INSERT INTO test_users (name) VALUES ("Homer Simpson")';

        $this->assertTrue(Database::insert($query));
    }



}