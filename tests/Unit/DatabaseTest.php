<?php

namespace Unit;

use Exception;
use Lucent\Database;
use Lucent\Database\Schema;
use Lucent\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

// Manually require the DatabaseDriverSetup file
$driverSetupPath = __DIR__ . '/DatabaseDriverSetup.php';

if (file_exists($driverSetupPath)) {
    require_once $driverSetupPath;
} else {
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
            'sqlite' => [
                'sqlite',
                [
                    'DB_DATABASE' => '/storage/database.sqlite'
                ]
            ],
            'mysql' => [
                'mysql',
                [
                    'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
                    'DB_PORT' => getenv('DB_PORT') ?: '3306',
                    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test_database',
                    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''
                ]
            ]
        ];
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_database_connection_explicit($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        // Force a connection and query
        try {
            Database::select("SELECT 1");

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

        $result = Schema::table("test_users", function ($table) {
            $table->int('id')->autoIncrement()->primaryKey();
            $table->text('name');
        })->create();

        $this->assertTrue($result);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_statement_failed_select_all($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_statement_create_table_success($driver, $config);
        $query = 'SELECT * FROM test_users';
        try {
            Database::statement($query);
        } catch (Exception $e) {
            $this->assertEquals("Invalid statement, SELECT * FROM test_users is not allowed to execute.", $e->getMessage());
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_statement_insert_success($driver, $config): void
    {

        $this->test_statement_create_table_success($driver, $config);

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
        $this->assertSame(
            $instance1,
            $instance2,
            "Database connection is not using the singleton pattern - a new connection was created"
        );

        // Run a second query and get the instance again
        Database::select($query);
        $instance3 = $this->getPrivateDatabaseInstance();
        $this->assertNotNull($instance3, "Failed to get third database instance");

        // Third instance should still be the same object
        $this->assertSame(
            $instance1,
            $instance3,
            "Database connection singleton was not maintained after multiple queries"
        );

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
        $this->assertNotSame(
            $instance1,
            $instance4,
            "Database::reset() did not create a new connection instance"
        );

        // Verify the driver type is correct
        $driverClass = get_class($instance4);

        if ($driver === 'sqlite') {
            $this->assertStringContainsString(
                'PDODriver',
                $driverClass,
                "Wrong driver type - expected PDODriver but got {$driverClass}"
            );
        } else if ($driver === 'mysql') {
            $this->assertStringContainsString(
                'PDODriver',
                $driverClass,
                "Wrong driver type - expected PDODriver but got {$driverClass}"
            );
        }

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

        Log::channel("phpunit")->debug("[DatabaseTest] Connection times for {$driver}:"
        ."\n    First connection: ".number_format($firstConnectionTime * 1000, 2) . "ms"
        ."\n    Second connection: ". number_format($secondConnectionTime * 1000, 2) . "ms");
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

        Database::reset();

        if($driver === "mysql") {
            $startTime = microtime(true);
            Database::select("SELECT 1");
            $firstQueryTime = microtime(true) - $startTime;

            $startTime = microtime(true);
            Database::select("SELECT 1");
            $secondQueryTime = microtime(true) - $startTime;

            $startTime = microtime(true);
            Database::select("SELECT 1");
            $thirdQueryTime = microtime(true) - $startTime;

            Log::channel("phpunit")->debug("[DatabaseTest] Performance test for {$driver}:"
                . "\n    First query (includes connection): " . number_format($firstQueryTime * 1000, 2) . "ms"
                . "\n    Second query (reused connection): " . number_format($secondQueryTime * 1000, 2) . "ms"
                . "\n    Third query (reused connection): " . number_format($thirdQueryTime * 1000, 2) . "ms"
                . "\n    Connection overhead: " . number_format(($firstQueryTime - $secondQueryTime) * 1000, 2) . "ms");

            // For very fast operations (< 1ms), use a more lenient threshold
            // For slower operations, use the stricter 50% threshold
            $threshold = $firstQueryTime < 0.001 ? 0.8 : 0.5;

            $this->assertLessThan(
                $firstQueryTime * $threshold,
                $secondQueryTime,
                "Second query took more than " . ($threshold * 100) . "% of first query time - singleton may not be working"
            );
        }

        // ALWAYS verify singleton pattern works (for both SQLite and MySQL)
        // Need to ensure we have an instance first
        Database::select("SELECT 1"); // Create instance if needed

        $instance1 = $this->getPrivateDatabaseInstance();
        $this->assertNotNull($instance1, "Database instance should exist after query");

        Database::select("SELECT 1");
        $instance2 = $this->getPrivateDatabaseInstance();

        $this->assertSame(
            $instance1,
            $instance2,
            "Database instances should be identical - singleton not working for {$driver}"
        );

        $this->assertSame($instance1, $instance2, "Database instances should be identical");
    }
}