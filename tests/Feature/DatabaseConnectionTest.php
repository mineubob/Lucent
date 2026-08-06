<?php

namespace Tests\Feature;

use Exception;
use Lucent\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\TestCase;

class DatabaseConnectionTest extends TestCase
{
    use DatabaseTesting;

    // -------------------------------------------------------------------------
    // Default connection behaviour
    // -------------------------------------------------------------------------

    /**
     * Verify that the active connection name is 'default' out of the box,
     * before anything has been registered or switched.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_default_connection_name_is_default($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->assertEquals(
            'default',
            Database::getActiveConnectionName(),
            "Active connection should be 'default' after setup"
        );
    }

    /**
     * Verify that getInstance() boots and returns a valid connection
     * without any explicit addConnection() call.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_default_connection_boots_from_env($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $instance = Database::getInstance();

        $this->assertNotNull($instance, "Default connection should boot from env vars automatically");
    }

    /**
     * Verify that the default connection is always returned when no
     * switching has occurred — confirms single-DB behaviour is unchanged.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_queries_use_default_connection_without_switching($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = Database::select("SELECT 1");

        $this->assertNotNull($result, "Query on default connection should return a result");
        $this->assertEquals('default', Database::getActiveConnectionName());
    }

    // -------------------------------------------------------------------------
    // addConnection()
    // -------------------------------------------------------------------------

    /**
     * Verify that addConnection() registers a connection without switching
     * the currently active connection.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_add_connection_does_not_switch_active($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        $this->assertEquals(
            'default',
            Database::getActiveConnectionName(),
            "addConnection() should not change the active connection"
        );
    }

    /**
     * Verify that addConnection() makes the connection retrievable via hasConnection().
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_add_connection_registers_in_pool($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        $this->assertFalse(Database::hasConnection('secondary'), "Connection should not exist before registration");

        Database::addConnection('secondary', $secondaryConfig);

        $this->assertTrue(Database::hasConnection('secondary'), "Connection should exist after registration");
    }

    /**
     * Verify that addConnection() with an unknown driver throws an exception.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_add_connection_throws_for_unknown_driver($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->expectException(Exception::class);

        Database::addConnection('bad', ['driver' => 'nonexistent_driver']);
    }

    // -------------------------------------------------------------------------
    // hasConnection()
    // -------------------------------------------------------------------------

    /**
     * Verify hasConnection() returns false for names that have never been registered.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_has_connection_returns_false_for_unknown_name($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->assertFalse(Database::hasConnection('does_not_exist'));
    }

    /**
     * Verify hasConnection() returns true for 'default' after the first query
     * has caused it to be booted.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_has_connection_returns_true_for_default_after_boot($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        // Boot the default connection
        Database::select("SELECT 1");

        $this->assertTrue(Database::hasConnection('default'));
    }

    // -------------------------------------------------------------------------
    // switchTo()
    // -------------------------------------------------------------------------

    /**
     * Verify that switchTo() changes the active connection name.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_switch_to_changes_active_connection($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);
        Database::switchTo('secondary');

        $this->assertEquals('secondary', Database::getActiveConnectionName());
    }

    /**
     * Verify that switchTo() throws when given an unregistered connection name.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_switch_to_throws_for_unregistered_connection($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->expectException(Exception::class);

        Database::switchTo('not_registered');
    }

    /**
     * Verify that after switchTo(), queries run against the new connection
     * and not the default.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_switch_to_routes_queries_to_new_connection($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);
        Database::switchTo('secondary');

        // Should be able to query the secondary connection without error
        $result = Database::select("SELECT 1");

        $this->assertNotNull($result);
        $this->assertEquals('secondary', Database::getActiveConnectionName());
    }

    /**
     * Verify that manually switching back to 'default' works after switchTo().
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_switch_back_to_default_after_switch($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        // Boot the default first
        Database::select("SELECT 1");

        Database::addConnection('secondary', $secondaryConfig);
        Database::switchTo('secondary');

        $this->assertEquals('secondary', Database::getActiveConnectionName());

        Database::switchTo('default');

        $this->assertEquals('default', Database::getActiveConnectionName());
    }

    // -------------------------------------------------------------------------
    // connection()
    // -------------------------------------------------------------------------

    /**
     * Verify that connection() returns the correct named instance without
     * changing the active connection.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_connection_returns_instance_without_switching($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        $instance = Database::connection('secondary');

        $this->assertNotNull($instance);
        $this->assertEquals(
            'default',
            Database::getActiveConnectionName(),
            "connection() should not change the active connection"
        );
    }

    /**
     * Verify that connection() throws for an unregistered name.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_connection_throws_for_unregistered_name($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->expectException(Exception::class);

        Database::connection('not_registered');
    }

    /**
     * Verify that connection() returns the same instance on repeated calls
     * (connections are not re-instantiated on every call).
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_connection_returns_same_instance_on_repeated_calls($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        $instance1 = Database::connection('secondary');
        $instance2 = Database::connection('secondary');

        $this->assertSame($instance1, $instance2, "connection() should return the same cached instance");
    }

    // -------------------------------------------------------------------------
    // usingConnection()
    // -------------------------------------------------------------------------

    /**
     * Verify that usingConnection() executes the callback and returns its value.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_using_connection_executes_callback($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        $result = Database::usingConnection('secondary', fn() => Database::select("SELECT 1"));

        $this->assertNotNull($result, "usingConnection() should return the callback's return value");
    }

    /**
     * Verify that usingConnection() restores the previous active connection
     * after the callback completes successfully.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_using_connection_restores_previous_connection_on_success($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        $this->assertEquals('default', Database::getActiveConnectionName());

        Database::usingConnection('secondary', fn() => Database::select("SELECT 1"));

        $this->assertEquals(
            'default',
            Database::getActiveConnectionName(),
            "usingConnection() should restore 'default' after the callback"
        );
    }

    /**
     * Verify that usingConnection() restores the previous connection even
     * when the callback throws an exception.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_using_connection_restores_previous_connection_on_exception($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        try {
            Database::usingConnection('secondary', function () {
                throw new Exception("Simulated callback failure");
            });
        } catch (Exception) {
            // Expected — we just want to check the connection was restored
        }

        $this->assertEquals(
            'default',
            Database::getActiveConnectionName(),
            "usingConnection() should restore previous connection even after an exception"
        );
    }

    /**
     * Verify that usingConnection() throws for an unregistered connection name.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_using_connection_throws_for_unregistered_name($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->expectException(Exception::class);

        Database::usingConnection('not_registered', fn() => true);
    }

    /**
     * Verify that nested usingConnection() calls each restore correctly,
     * unwinding back to the original connection.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_using_connection_restores_correctly_when_nested($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        // Boot default
        Database::select("SELECT 1");
        Database::addConnection('secondary', $secondaryConfig);

        $connectionsDuringExecution = [];

        Database::usingConnection('secondary', function () use (&$connectionsDuringExecution, $secondaryConfig) {
            $connectionsDuringExecution[] = Database::getActiveConnectionName(); // should be 'secondary'

            // Nest back into default
            Database::usingConnection('default', function () use (&$connectionsDuringExecution) {
                $connectionsDuringExecution[] = Database::getActiveConnectionName(); // should be 'default'
            });

            $connectionsDuringExecution[] = Database::getActiveConnectionName(); // should be 'secondary' again
        });

        $this->assertEquals('secondary', $connectionsDuringExecution[0]);
        $this->assertEquals('default',   $connectionsDuringExecution[1]);
        $this->assertEquals('secondary', $connectionsDuringExecution[2]);
        $this->assertEquals('default',   Database::getActiveConnectionName()); // fully unwound
    }

    // -------------------------------------------------------------------------
    // removeConnection()
    // -------------------------------------------------------------------------

    /**
     * Verify that removeConnection() removes the connection from the pool.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_remove_connection_removes_from_pool($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);
        $this->assertTrue(Database::hasConnection('secondary'));

        Database::removeConnection('secondary');

        $this->assertFalse(Database::hasConnection('secondary'));
    }

    /**
     * Verify that removeConnection() resets the active connection to 'default'
     * if the removed connection was the active one.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_remove_connection_resets_active_if_removed_was_active($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);
        Database::switchTo('secondary');

        $this->assertEquals('secondary', Database::getActiveConnectionName());

        Database::removeConnection('secondary');

        $this->assertEquals(
            'default',
            Database::getActiveConnectionName(),
            "Active connection should reset to 'default' when the active connection is removed"
        );
    }

    /**
     * Verify that removeConnection() on a non-active connection does not
     * change the active connection name.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_remove_connection_does_not_affect_active_if_not_active($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);

        // Active is still 'default'
        Database::removeConnection('secondary');

        $this->assertEquals('default', Database::getActiveConnectionName());
    }

    /**
     * Verify that removing a non-existent connection does not throw.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_remove_connection_is_silent_for_unknown_name($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        // Should not throw
        Database::removeConnection('never_existed');

        $this->assertEquals('default', Database::getActiveConnectionName());
    }

    // -------------------------------------------------------------------------
    // reset()
    // -------------------------------------------------------------------------

    /**
     * Verify that reset() clears all registered connections from the pool.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_reset_clears_all_connections($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        // Boot default and add a secondary
        Database::select("SELECT 1");
        Database::addConnection('secondary', $secondaryConfig);

        $this->assertTrue(Database::hasConnection('default'));
        $this->assertTrue(Database::hasConnection('secondary'));

        Database::reset();

        $this->assertFalse(Database::hasConnection('default'));
        $this->assertFalse(Database::hasConnection('secondary'));
    }

    /**
     * Verify that reset() sets the active connection back to 'default'.
     */
    #[DataProvider('dualDatabaseDriverProvider')]
    public function test_reset_restores_active_connection_to_default($driver, $config, $secondaryConfig): void
    {
        self::setupDatabase($driver, $config, []);

        Database::addConnection('secondary', $secondaryConfig);
        Database::switchTo('secondary');

        $this->assertEquals('secondary', Database::getActiveConnectionName());

        Database::reset();

        $this->assertEquals('default', Database::getActiveConnectionName());
    }

    /**
     * Verify that the default connection re-boots cleanly from env vars
     * after a reset, and queries succeed.
     */
    #[DataProvider('databaseDriverProvider')]
    public function test_reset_allows_default_to_reboot_from_env($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        Database::select("SELECT 1");
        Database::reset();

        // Should re-boot and query successfully
        $result = Database::select("SELECT 1");

        $this->assertNotNull($result, "Default connection should re-boot from env after reset");
    }
}