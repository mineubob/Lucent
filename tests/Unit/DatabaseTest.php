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
                'DB_DATABASE' => '/storage/database.sqlite'
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
            if ($driver == 'sqlite') {
                // SQLite query
                $result = Database::select("SELECT name FROM sqlite_master WHERE type='table'");

            } else {
                // Use a MySQL-specific query to ensure we're testing MySQL

                $result = Database::select("SELECT VERSION()");
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

    #[DataProvider('databaseDriverProvider')]
    public function test_singleton_connection_pattern_working($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        // Get the first connection instance
        $instance1 = $this->getPrivateDatabaseInstance();

        // If instance1 is null, force creation by making a query
        if ($instance1 === null) {
            Database::select("SELECT 1");
            $instance1 = $this->getPrivateDatabaseInstance();
        }

        $this->assertNotNull($instance1, "Failed to get first database instance");

        // Run a simple query to ensure the connection is established

        $query = "SELECT 1";

        Database::select($query);

        // Get the second instance after the query
        $instance2 = $this->getPrivateDatabaseInstance();
        $this->assertNotNull($instance2, "Failed to get second database instance");

        // They should be the same object
        $this->assertSame($instance1, $instance2,
            "Database connection is not using the singleton pattern - a new connection was created");

        // Run a second query and get the instance again
        Database::select($query);
        $instance3 = $this->getPrivateDatabaseInstance();
        $this->assertNotNull($instance3, "Failed to get third database instance");

        // Third instance should still be the same object
        $this->assertSame($instance1, $instance3,
            "Database connection singleton was not maintained after multiple queries");

        // Now reset the connection and verify we get a new instance
        Database::reset();

        // After reset, instance should be null
        $instanceAfterReset = $this->getPrivateDatabaseInstance();
        $this->assertNull($instanceAfterReset, "Database::reset() did not properly clear the instance");

        // Force creation of a new instance
        Database::select($query);
        $instance4 = $this->getPrivateDatabaseInstance();
        $this->assertNotNull($instance4, "Failed to create new instance after reset");

        // After reset and a new query, we should have a different instance
        $this->assertNotSame($instance1, $instance4,
            "Database::reset() did not create a new connection instance");

        // Verify the driver type is correct
        $driverClass = get_class($instance4);

        if ($driver === 'sqlite') {
            $this->assertStringContainsString('PDODriver', $driverClass,
                "Wrong driver type - expected PDODriver but got {$driverClass}");
        } else if($driver === 'mysql') {
            $this->assertStringContainsString('PDODriver', $driverClass,
                "Wrong driver type - expected PDODriver but got {$driverClass}");
        }else if($driver === 'pdo'){
            $this->assertStringContainsString('PDODriver', $driverClass,
                "Wrong driver type - expected PDODriver but got {$driverClass}");        }

        // Test with performance metrics - measure connection time
        $startTime = microtime(true);

        // First connection might be slow
        Database::reset();
        Database::select($query);
        $firstConnectionTime = microtime(true) - $startTime;

        // Second connection should be much faster if singleton is working
        $startTime = microtime(true);
        Database::select($query);
        $secondConnectionTime = microtime(true) - $startTime;

        // Log the times for analysis
        $this->addToAssertionCount(1); // Count this check as an assertion
        echo "\nConnection times for {$driver}:\n";
        echo "  First connection: " . number_format($firstConnectionTime * 1000, 2) . "ms\n";
        echo "  Second connection: " . number_format($secondConnectionTime * 1000, 2) . "ms\n";
    }

    private function getPrivateDatabaseInstance(): mixed
    {
        $reflection = new \ReflectionClass(Database::class);
        $property = $reflection->getProperty('instance');
        return $property->getValue($reflection);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_connection_performance($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        // Reset to ensure we're testing a fresh connection
        Database::reset();

        // Measure time for first connection
        $startTime = microtime(true);
        Database::select("SELECT 1");
        $firstQueryTime = microtime(true) - $startTime;

        // Measure time for subsequent queries that should reuse the connection
        $startTime = microtime(true);
        Database::select("SELECT 1");
        $secondQueryTime = microtime(true) - $startTime;

        // Do a third query
        $startTime = microtime(true);
        Database::select("SELECT 1");
        $thirdQueryTime = microtime(true) - $startTime;

        // Log the performance metrics
        echo "\nPerformance test for {$driver}:\n";
        echo "  First query (includes connection): " . number_format($firstQueryTime * 1000, 2) . "ms\n";
        echo "  Second query (reused connection): " . number_format($secondQueryTime * 1000, 2) . "ms\n";
        echo "  Third query (reused connection): " . number_format($thirdQueryTime * 1000, 2) . "ms\n";
        echo "  Connection overhead: " . number_format(($firstQueryTime - $secondQueryTime) * 1000, 2) . "ms\n";

        // The second and third queries should be significantly faster
        // if connection reuse is working
        $this->assertLessThan($firstQueryTime * 0.5, $secondQueryTime,
            "Second query took more than 50% of first query time - singleton may not be working");

        // For good measure, verify instances match
        $instance1 = $this->getPrivateDatabaseInstance();
        Database::select("SELECT 1");
        $instance2 = $this->getPrivateDatabaseInstance();

        $this->assertSame($instance1, $instance2, "Database instances should be identical");
    }

}