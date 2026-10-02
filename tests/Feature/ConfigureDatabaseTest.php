<?php

namespace Tests\Feature;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\DatabaseManager;
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
        $second->statement('CREATE TABLE "marker" ("id" INTEGER PRIMARY KEY)');
        $firstTables = array_map(
            fn($row) => $row->name,
            $first->select($first->table('sqlite_master')->select('name')->where('type', '=', 'table'))->all(),
        );
        $this->assertNotContains('marker', $firstTables);
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
