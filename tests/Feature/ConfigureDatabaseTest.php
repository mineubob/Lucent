<?php

namespace Tests\Feature;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\DatabaseManager;
use Lucent\Facades\FileSystem;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\TestCase;

class ConfigureDatabaseTest extends TestCase
{
    use DatabaseTesting;

    #[\PHPUnit\Framework\Attributes\DataProvider('databaseDriverProvider')]
    public function test_set_env_alters_existing_manager_config($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $manager = Database::manager();

        // A runtime customization that a fresh manager would wipe.
        $manager->addConnection('reporting', ['driver' => 'sqlite', 'database' => ':memory:']);

        // Re-configure via setEnv — the SAME manager must survive.
        Application_setEnv_reconfigure($driver, $config);

        $this->assertSame($manager, Database::manager());
        $this->assertTrue($manager->hasConnection('reporting'));

        // The default connection was rebuilt from the new config (the old
        // instance was evicted).
        $this->assertNotSame(
            $manager->connection('default'),
            $manager->connection('reporting'),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('databaseDriverProvider')]
    public function test_set_env_rebuilds_default_connection_from_new_config($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $manager = Database::manager();
        $first = $manager->connection('default');

        // Point the default connection at a DIFFERENT sqlite database.
        $other = tempnam(sys_get_temp_dir(), 'alt') . '.sqlite';
        Application_setEnv_reconfigure('sqlite', ['driver' => 'sqlite', 'database' => $other]);

        $second = Database::manager()->sqlConnection('default');

        $this->assertNotSame($first, $second);

        // The new connection actually targets the new database: create a
        // table there and confirm the first connection cannot see it.
        // (The first connection is still on the ORIGINAL database — the
        // schema inspector reads the live schema of whichever database the
        // connection points at, so dialect-agnostically.)
        $second->statement('CREATE TABLE marker (id INT PRIMARY KEY)');
        $this->assertNotContains('marker', $first->schemaInspector->tables());
    }

    public function test_set_env_adopts_foreign_manager_without_replacing_it(): void
    {
        // A manager NOT built by Lucent — its own connection is active, and
        // it carries a runtime customization.
        $foreign = new DatabaseManager(
            ['foreign' => ['driver' => 'sqlite', 'database' => ':memory:']],
            'foreign',
        );
        $foreign->addConnection('extra', ['driver' => 'sqlite', 'database' => ':memory:']);
        Database::setManager($foreign);

        // Lucent's env config must be registered AND made active — without
        // replacing the manager (the customization must survive).
        Application_setEnv_reconfigure('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->assertSame($foreign, Database::manager());
        $this->assertTrue($foreign->hasConnection('default'));
        $this->assertTrue($foreign->hasConnection('extra'));

        // Lucent's default is now the ACTIVE connection.
        $this->assertSame('default', $foreign->currentConnection());
        $this->assertSame($foreign->sqlConnection('default'), Database::sqlConnection());
    }

    public function test_has_manager_reflects_facade_state(): void
    {
        Database::clearManager();
        $this->assertFalse(Database::hasManager());

        Application_setEnv_reconfigure('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->assertTrue(Database::hasManager());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     * @phpcsSuppress SlevomatCodingStandard.TypeHints
     */
    public static function sqlitePathProvider(): array
    {
        return [
            'relative path resolved against root' => [
                'storage/db.sqlite',
                'storage/db.sqlite',
                FileSystem::rootPath() . '/storage/db.sqlite',
            ],
            'dot segments collapsed' => [
                'storage/./nested/../db.sqlite',
                'storage/./nested/../db.sqlite',
                FileSystem::rootPath() . '/storage/db.sqlite',
            ],
            'absolute path passes through' => [
                '/tmp/lucent-test-absolute.sqlite',
                '/tmp/lucent-test-absolute.sqlite',
                '/tmp/lucent-test-absolute.sqlite',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sqlitePathProvider')]
    public function test_sqlite_database_path_resolved_against_root(
        string $envValue,
        string $_input,
        string $expected,
    ): void {
        // A relative sqlite path is resolved against FileSystem::rootPath()
        // before it reaches Radiant's connector — the connector consumes the
        // `database` value verbatim as a DSN path.
        Application_setEnv_reconfigure('sqlite', ['driver' => 'sqlite', 'database' => $envValue]);

        $this->assertSame($expected, self::defaultDatabaseConfig()['database']);
    }

    public function test_sqlite_memory_sentinel_not_resolved_as_path(): void
    {
        Application_setEnv_reconfigure('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);

        $this->assertSame(':memory:', self::defaultDatabaseConfig()['database']);
    }

    public function test_non_sqlite_driver_database_value_untouched(): void
    {
        // A mysql database NAME must not be mangled by path resolution.
        Application_setEnv_reconfigure('mysql', [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'port'     => 3306,
            'database' => 'my_database',
            'username' => 'root',
            'password' => '',
        ]);

        $this->assertSame('my_database', self::defaultDatabaseConfig()['database']);
    }

    /**
     * Read the `default` connection's stored config out of the manager.
     *
     * @return array<string, mixed>
     */
    private static function defaultDatabaseConfig(): array
    {
        $prop = new \ReflectionProperty(
            \BlueprintAU\Radiant\Database\DatabaseManager::class,
            'connections',
        );

        /** @var array<string, array<string, mixed>> $connections */
        $connections = $prop->getValue(Database::manager());

        return $connections['default'];
    }

    protected function tearDown(): void
    {
        // Tests here inject foreign managers into the static facade — clear
        // it so later tests rebuild from their own env.
        Database::clearManager();

        parent::tearDown();
    }
}

/**
 * Re-run Application's configureDatabase() path with the given config —
 * the same wiring production uses.
 */
function Application_setEnv_reconfigure(string $driver, array $config): void
{
    $env = ['DB_DRIVER' => $driver];

    foreach ($config as $key => $value) {
        if ($key === 'driver') {
            continue;
        }
        $env['DB_' . strtoupper((string) $key)] = (string) $value;
    }

    \Lucent\Application::getInstance()->setEnv($env, false);
}
