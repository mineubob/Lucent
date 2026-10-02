<?php

namespace Tests\Feature;

use App\Models\TestUser;
use BlueprintAU\Radiant\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesCommandOutput;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\TestCase;
use Lucent\Console\SyncLegacyCommand;
use Lucent\Facades\CommandLine;

class SyncLegacyCommandTest extends TestCase
{
    use CapturesCommandOutput;
    use CopiesFixtures;
    use DatabaseTesting;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Model' => ['TestUser.php'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureCommandOutput();
    }

    /**
     * Create a legacy-named table (short class name) with one row.
     */
    private function createLegacyTable(string $driver, array $config): void
    {
        self::setupDatabase($driver, $config, []);

        $connection = Database::sqlConnection();
        $connection->statement(
            'CREATE TABLE "TestUser" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "email" TEXT, '
            . '"password_hash" TEXT, "full_name" TEXT)'
        );
        $connection->statement(
            'INSERT INTO "TestUser" ("email", "password_hash", "full_name") VALUES (?, ?, ?)',
            ['john@doe.com', 'password', 'John Doe']
        );
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_legacy_renames_old_table($driver, $config): void
    {
        $this->createLegacyTable($driver, $config);

        $result = CommandLine::execute("sync:legacy --dir=" . TEMP_ROOT . "App/Models");

        $this->assertStringContainsString("Renamed [TestUser] → [test_users]", $result);

        // The data travelled with the rename.
        $connection = Database::sqlConnection();
        $rows = $connection->table('test_users')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('John Doe', $rows[0]->full_name);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_legacy_supports_filters($driver, $config): void
    {
        $this->createLegacyTable($driver, $config);

        // A filter that matches nothing leaves the legacy table untouched.
        $result = CommandLine::execute(
            "sync:legacy --dir=" . TEMP_ROOT . "App/Models --filter=DoesNotExist"
        );

        $this->assertStringContainsString("No models found", $result);

        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertContains('TestUser', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_legacy_is_idempotent($driver, $config): void
    {
        $this->createLegacyTable($driver, $config);

        CommandLine::execute("sync:legacy --dir=" . TEMP_ROOT . "App/Models");

        // A second run finds nothing to rename.
        $second = CommandLine::execute("sync:legacy --dir=" . TEMP_ROOT . "App/Models");

        $this->assertStringContainsString("Nothing to rename", $second);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_legacy_dry_run_displays_plan_without_applying($driver, $config): void
    {
        $this->createLegacyTable($driver, $config);

        $result = CommandLine::execute(
            "sync:legacy --dir=" . TEMP_ROOT . "App/Models --dry-run"
        );

        // The plan is displayed...
        $this->assertStringContainsString("Would rename [TestUser] → [test_users]", $result);
        $this->assertStringContainsString("Dry run — no changes applied.", $result);

        // ...but nothing was applied — the legacy table is untouched.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertContains('TestUser', $tables);
        $this->assertNotContains('test_users', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_legacy_marks_deprecated($driver, $config): void
    {
        $this->createLegacyTable($driver, $config);

        $result = CommandLine::execute("sync:legacy --dir=" . TEMP_ROOT . "App/Models");

        $this->assertStringContainsString("DEPRECATED", $result);
    }

    public function test_sync_legacy_command_is_registered(): void
    {
        // Registration happens in Application::executeConsoleCommand(); boot
        // + a help listing proves the command is in the CLI route table.
        // The CLI router splits colons in command names, so the help
        // listing shows "sync legacy" for the "sync:legacy" command.
        $result = CommandLine::execute("");

        $this->assertStringContainsString("sync legacy", $result);
        $this->assertStringContainsString(
            "rename legacy table names",
            $result,
        );

        // The help listing is the only place options are visible — the
        // description must carry them.
        $this->assertStringContainsString("--dry-run", $result);
    }
}
