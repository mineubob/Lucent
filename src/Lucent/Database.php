<?php

namespace Lucent;

use Exception;
use Lucent\Database\DatabaseInterface;
use Lucent\Database\DatabaseLogger;
use Lucent\Database\Drivers\PDODriver;

class Database
{
    /**
     * Pool of registered named database connections.
     *
     * Keyed by connection name (e.g. 'default', 'tenant').
     * Connections are lazily initialised — the default connection is only
     * booted from env vars on first use.
     *
     * @var DatabaseInterface[]
     */
    private static array $connections = [];

    /**
     * The name of the currently active connection.
     *
     * All static query methods (select, insert, update, etc.) operate against
     * this connection. Defaults to 'default', which is booted automatically
     * from environment variables the first time it is needed.
     *
     * @var string
     */
    private static string $activeConnection = 'default';

    /**
     * Our array of database drivers we can load.
     */
    private static array $databaseDrivers = [
        'mysql'  => PDODriver::class,
        'sqlite' => PDODriver::class,
    ];

    /**
     * Environment variables used to boot the default connection.
     * Populated either by Application::loadEnv() or a standalone configure() call.
     */
    private static array $env = [];


    private static ?DatabaseLogger $logger = null;


    /**
     * Configure the database layer with environment variables.
     *
     * Called either by Application after loading .env, or directly by a
     * standalone bootstrap (e.g. WordPress) without booting the full app.
     *
     * @param array $env Key-value pairs — expects at minimum DB_DRIVER, plus
     *                   driver-specific keys (DB_HOST, DB_DATABASE, etc.)
     */
    public static function configure(array $env): void
    {
        self::$env = $env;
    }

    public static function env(string $key, mixed $default = null): mixed
    {
        return isset(self::$env[$key]) ? trim(self::$env[$key]) : $default;
    }


    /**
     * Get the currently active connection instance.
     *
     * If the active connection has not yet been initialised, it is booted
     * automatically from environment variables (DB_DRIVER, DB_HOST, etc.).
     * If switchTo() has never been called, this always returns the default
     * connection, preserving existing single-database behaviour.
     *
     * @return DatabaseInterface The active database connection instance.
     * @throws Exception If the driver specified in DB_DRIVER is not registered.
     */
    public static function getInstance(): DatabaseInterface
    {
        $name = self::$activeConnection;

        if (!isset(self::$connections[$name])) {
            self::$connections[$name] = self::bootFromEnv();
        }

        return self::$connections[$name];
    }

    /**
     * Boot the default database connection from environment variables.
     *
     * Reads DB_DRIVER from the environment and instantiates the corresponding
     * driver class registered on the Application instance. This is only called
     * once per connection name — subsequent calls to getInstance() return the
     * cached instance.
     *
     * @return DatabaseInterface A freshly instantiated database driver.
     * @throws Exception If DB_DRIVER is not set or maps to an unregistered driver class.
     */
    private static function bootFromEnv(): DatabaseInterface
    {
        $driverKey = self::env('DB_DRIVER');
        $driverClass = self::$databaseDrivers[$driverKey] ?? null;

        if (!$driverClass) {
            throw new Exception("Unknown database driver provided: '$driverKey'");
        }

        return new $driverClass();
    }

    /**
     * Register a named connection from a configuration array.
     *
     * Instantiates the appropriate driver for the given config and stores it
     * in the connection pool under the given name. Does not switch the active
     * connection — use switchTo() or usingConnection() for that.
     *
     * Supported config keys:
     *   - driver   (required) — 'mysql' or 'sqlite'
     *   - host     — database host (mysql only)
     *   - database — database name or SQLite file path
     *   - username — database username (mysql only)
     *   - password — database password (mysql only)
     *   - port     — database port, defaults to 3306 (mysql only)
     *
     * @param string $name   A unique name for this connection (e.g. 'tenant', 'reporting').
     * @param array  $config Connection configuration array.
     *
     * @return void
     * @throws Exception If the driver key is missing or maps to an unregistered driver class.
     *
     * @example
     *   Database::addConnection('tenant', [
     *       'driver'   => 'mysql',
     *       'host'     => 'tenant.db.host',
     *       'database' => 'tenant_db',
     *       'username' => 'user',
     *       'password' => 'secret',
     *   ]);
     */
    public static function addConnection(string $name, array $config): void
    {
        $driverClass = self::$databaseDrivers[$config['driver']] ?? null;

        if (!$driverClass) {
            throw new Exception("[Database] Unknown database driver: {$config['driver']}");
        }

        self::$connections[$name] = new $driverClass($config);
    }

    /**
     * Switch the active connection globally for all subsequent queries.
     *
     * All calls to getInstance() (and therefore all static query methods) will
     * use this connection until switchTo() is called again. If you only need to
     * run a scoped block of queries on a different connection, prefer
     * usingConnection() which restores the previous connection automatically.
     *
     * @param string $name The name of a previously registered connection.
     *
     * @return void
     * @throws Exception If no connection with the given name has been registered.
     *
     * @see Database::usingConnection() For scoped, automatically-restored connection switching.
     */
    public static function switchTo(string $name): void
    {
        if (!isset(self::$connections[$name])) {
            throw new Exception("[Database] No connection registered with name: '$name'");
        }

        self::$activeConnection = $name;
    }

    /**
     * Get a specific named connection without changing the active connection.
     *
     * Useful when you need to run a one-off query on a specific connection
     * without affecting the globally active connection for the rest of the request.
     *
     * @param string $name The name of a previously registered connection.
     *
     * @return DatabaseInterface The named connection instance.
     * @throws Exception If no connection with the given name has been registered.
     */
    public static function connection(string $name): DatabaseInterface
    {
        if (!isset(self::$connections[$name])) {
            throw new Exception("[Database] No connection registered with name: '$name'");
        }

        return self::$connections[$name];
    }

    /**
     * Run a callback on a specific named connection, then restore the previous active connection.
     *
     * This is the safest way to query a secondary database (e.g. a tenant DB) without
     * risking connection bleed into subsequent queries in the same request. The previous
     * active connection is always restored in a finally block, even if the callback throws.
     *
     * @param string   $name     The name of a previously registered connection.
     * @param callable $callback The code to execute against the named connection.
     *
     * @return mixed The return value of the callback.
     * @throws Exception If no connection with the given name has been registered.
     *
     * @example
     *   $leads = Database::usingConnection('tenant', fn() => Lead::all());
     */
    public static function usingConnection(string $name, callable $callback): mixed
    {
        if (!isset(self::$connections[$name])) {
            throw new Exception("[Database] No connection registered with name: '$name'");
        }

        $previous = self::$activeConnection;
        self::$activeConnection = $name;

        try {
            return $callback();
        } finally {
            self::$activeConnection = $previous;
        }
    }

    /**
     * Check whether a named connection has been registered.
     *
     * Does not indicate whether the connection is open or healthy — only that
     * addConnection() has been called with this name.
     *
     * @param string $name The connection name to check.
     *
     * @return bool True if the connection exists in the pool, false otherwise.
     */
    public static function hasConnection(string $name): bool
    {
        return isset(self::$connections[$name]);
    }

    /**
     * Get the name of the currently active connection.
     *
     * Returns 'default' unless switchTo() or usingConnection() has changed it.
     *
     * @return string The active connection name.
     */
    public static function getActiveConnectionName(): string
    {
        return self::$activeConnection;
    }

    /**
     * Execute a raw SQL statement (DDL or other non-query SQL).
     *
     * Use this for statements that do not return rows, such as ALTER TABLE,
     * SET, PRAGMA, or raw DML where you don't need the result set.
     *
     * @param string $query The SQL statement to execute.
     * @param array  $args  Optional bound parameter values for prepared statements.
     *
     * @return bool True on success, false on failure.
     */
    public static function statement(string $query, array $args = []): bool
    {
        return self::getInstance()->statement($query, $args);
    }

    /**
     * Execute an INSERT statement against the active connection.
     *
     * @param string $query The INSERT SQL statement.
     * @param array  $args  Bound parameter values for the prepared statement.
     *
     * @return bool True on success, false on failure.
     */
    public static function insert(string $query, array $args = []): bool
    {
        return self::getInstance()->insert($query, $args);
    }

    /**
     * Execute an UPDATE statement against the active connection.
     *
     * @param string $query The UPDATE SQL statement.
     * @param array  $args  Bound parameter values for the prepared statement.
     *
     * @return bool True on success, false on failure.
     */
    public static function update(string $query, array $args = []): bool
    {
        return self::getInstance()->update($query, $args);
    }

    /**
     * Execute a DELETE statement against the active connection.
     *
     * @param string $query The DELETE SQL statement.
     * @param array  $args  Bound parameter values for the prepared statement.
     *
     * @return bool True on success, false on failure.
     */
    public static function delete(string $query, array $args = []): bool
    {
        return self::getInstance()->delete($query, $args);
    }

    /**
     * Execute a SELECT query and return the result set.
     *
     * @param string $query    The SELECT SQL statement.
     * @param bool   $fetchAll If true, returns all matching rows as an array of associative arrays.
     *                         If false, returns only the first matching row as an associative array.
     * @param array  $args     Bound parameter values for the prepared statement.
     *
     * @return array|null All rows (fetchAll=true) or a single row (fetchAll=false),
     *                    or null if no results were found.
     */
    public static function select(string $query, bool $fetchAll = true, array $args = []): ?array
    {
        return self::getInstance()->select($query, $fetchAll, $args);
    }

    /**
     * Execute a callable within a database transaction on the active connection.
     *
     * Automatically commits if the callback returns a truthy value, or rolls back
     * if it returns false or throws an exception.
     *
     * @param callable $callback The operations to run inside the transaction.
     *
     * @return bool True if the transaction was committed, false if it was rolled back.
     */
    public static function transaction(callable $callback): bool
    {
        return self::getInstance()->transaction($callback);
    }

    /**
     * Temporarily disable a named database feature, run a callback, then re-enable it.
     *
     * Used for operations that require bypassing certain database constraints or checks,
     * such as foreign key enforcement during bulk data manipulation or table drops.
     * The feature is always re-enabled in a finally block, even if the callback throws.
     *
     * If the feature is not supported by the active driver, the callback is executed
     * as-is and a warning is logged — no exception is thrown.
     *
     * Supported features (driver-dependent):
     *   - 'foreign_key_checks' — disables FK constraint enforcement during the callback
     *
     * @param string   $feature  The feature name to disable. Must be a key under
     *                           PDODriver::$map[$driver]['functions'].
     * @param callable $callback The operations to run while the feature is disabled.
     *
     * @return mixed The return value of the callback.
     *
     * @example
     *   Database::disabling('foreign_key_checks', function() {
     *       Schema::dropTable('orders');
     *       Schema::dropTable('customers');
     *   });
     */
    public static function disabling(string $feature, callable $callback): mixed
    {
        $driver = self::getInstance()->getDriverName();
        $featureCommands = PDODriver::$map[$driver]["functions"][$feature] ?? null;
        $disableFeatureSql = $featureCommands["disable"] ?? null;
        $enableFeatureSql = $featureCommands["enable"] ?? null;

        // If feature not supported for this driver, just run callback
        if ($disableFeatureSql === null || $enableFeatureSql === null) {
            Database::log("warning","Feature '{$feature}' not supported for driver '{$driver}'");
            return $callback();
        }

        try {
            self::getInstance()->statement($disableFeatureSql);
            return $callback();
        } finally {
            self::getInstance()->statement($enableFeatureSql);
        }
    }

    /**
     * Get the underlying driver instance for the active connection.
     *
     * Provides direct access to the DatabaseInterface implementation when you
     * need to call driver-specific methods not exposed on the Database facade.
     *
     * @return DatabaseInterface The active connection's driver instance.
     */
    public static function getDriver(): DatabaseInterface
    {
        return self::getInstance();
    }

    /**
     * Close and remove a specific named connection from the pool.
     *
     * If the removed connection is currently the active connection, the active
     * connection name is automatically reset to 'default'. If 'default' itself
     * is removed, it will be re-booted from environment variables on next use.
     *
     * @param string $name The name of the connection to remove.
     *
     * @return void
     */
    public static function removeConnection(string $name): void
    {
        if (isset(self::$connections[$name])) {
            self::$connections[$name]->closeDriver();
            unset(self::$connections[$name]);
        }

        if (self::$activeConnection === $name) {
            self::$activeConnection = 'default';
        }
    }

    /**
     * Close all connections and reset the database layer to its initial state.
     *
     * Closes every connection in the pool and clears the registry. The active
     * connection name is reset to 'default'. The next call to getInstance() will
     * re-boot the default connection from environment variables.
     *
     * Primarily used in testing to ensure a clean slate between test cases.
     *
     * @return void
     */
    public static function reset(): void
    {
        foreach (self::$connections as $connection) {
            $connection->closeDriver();
        }

        self::$connections = [];
        self::$activeConnection = 'default';
    }

    public static function registerDatabaseDriver(string $key, string $driverClass): void
    {
        self::$databaseDrivers[$key] = $driverClass;
    }

    public static function setLogger(DatabaseLogger $logger): void
    {
        self::$logger = $logger;
    }

    public static function log(string $level, string $message): void
    {
        if (self::$logger !== null) {
            self::$logger->$level($message);
        }
        // No logger configured: silently drop the message.
        //
        // This mirrors the PSR-3 NullLogger convention (and Lucent's own
        // "blank" channel) — a missing logger is a no-op, not a reason to
        // write to PHP's error_log. If you need pre-boot visibility, install
        // a logger explicitly via Database::setLogger().
    }
}