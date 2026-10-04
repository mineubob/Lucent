<?php

namespace Tests\Feature;

use App\Models\SluggedModel;
use App\Models\TestUser;
use App\Models\TestUserTwo;
use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesCommandOutput;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;
use Lucent\Console\Support\ModelDiscovery;
use Lucent\Console\SyncCommand;
use Lucent\Facades\CommandLine;

class SyncCommandTest extends TestCase
{
    use CapturesCommandOutput;
    use CopiesFixtures;
    use DatabaseTesting;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Model' => ['TestUser.php', 'TestUserTwo.php', 'SluggedModel.php'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureCommandOutput();
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_creates_tables_for_discovered_models($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        $this->assertStringContainsString("Planned schema changes", $result);

        // The tables now exist — a second sync reports nothing to do.
        $second = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");
        $this->assertStringContainsString("Schema is in sync", $second);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_filter_narrows_to_matching_models($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/TestUser$/'"
        );

        $this->assertStringContainsString("Planned schema changes", $result);

        // Only test_users was created — the slugged_models table does not exist.
        $connection = Database::sqlConnection();
        $tables = $connection->schemaInspector->tables();

        $this->assertContains('test_users', $tables);
        $this->assertNotContains('slugged_models', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_exclude_filter_protects_excluded_tables($driver, $config): void
    {
        self::setupDatabase($driver, $config, [TestUser::class, TestUserTwo::class]);

        // Drop test_user_twos from the desired set via exclude-filter; its
        // table must NOT be offered for drop (protected-list suppression).
        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --exclude-filter='/TestUserTwo/'"
        );

        $this->assertStringNotContainsString("drop table [test_user_twos]", $result);

        // The protected table must also be invisible to the rename pairing:
        // test_users and test_user_twos share 100% of their columns, so the
        // differ would pair them as a POSSIBLE RENAME — renaming the
        // protected table would move its data into the wrong table.
        $this->assertStringNotContainsString("POSSIBLE RENAME of [test_user_twos]", $result);
        $this->assertStringNotContainsString("rename table [test_user_twos]", $result);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_no_drop_tables_leaves_orphan_tables_untouched($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        // An orphan table no model declares — e.g. left by a removed model
        // or another tool sharing the database.
        self::createLegacyTable('orphan_table', ['id' => ColumnType::BigInt]);
        // Default: the orphan is offered for drop.
        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");
        $this->assertStringContainsString("drop table [orphan_table]", $result);

        // --no-drop-tables: additive-only — the orphan is left untouched.
        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --no-drop-tables"
        );
        $this->assertStringNotContainsString("drop table [orphan_table]", $result);

        // The orphan still exists after the additive-only run.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertContains('orphan_table', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_declining_destructive_prompt_applies_nothing($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyTable('orphan_table', ['id' => ColumnType::BigInt]);

        // Answer "no" to the destructive confirm (the prompts were fired
        // in the first place only because an injected input stream makes
        // the run interactive).
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, "no\n");
        rewind($stream);
        SyncCommand::useInputStream($stream);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        fclose($stream);
        SyncCommand::useInputStream(null);

        // The decline is honored — the orphan still exists.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertContains('orphan_table', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_accepting_destructive_prompt_drops_the_table($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyTable('orphan_table', ['id' => ColumnType::BigInt]);

        // Answer "yes" to the destructive confirm.
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, "yes\n");
        rewind($stream);
        SyncCommand::useInputStream($stream);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        fclose($stream);
        SyncCommand::useInputStream(null);

        $this->assertStringContainsString("planned change(s) applied", $result);

        // The acceptance is honored — the orphan is gone.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertNotContains('orphan_table', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_reports_nothing_to_do_when_in_sync($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        // First run creates everything.
        CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        // Second run: schema is in sync.
        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        $this->assertStringContainsString("Schema is in sync", $result);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_replans_after_applying_renames($driver, $config): void
    {
        // A legacy table whose columns drift from the model (missing
        // password_hash) — the declared rename and the column drift must
        // appear in ONE plan, and the schema must be fully in sync after
        // the single apply.
        self::setupDatabase($driver, $config, []);
        self::createLegacyTable('TestUser', [
            'id' => ColumnType::BigInt,
            'email' => ColumnType::Text,
            'full_name' => ColumnType::Text,
        ]);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models --force");

        // One plan: the rename AND the drift together. Discovery order is
        // filesystem-dependent, so EITHER model may claim the legacy
        // TestUser drop (both share its columns) — assert the rename shape,
        // not a specific target.
        $this->assertMatchesRegularExpression(
            '/rename table \[TestUser\] to \[test_(users|user_twos)\]/',
            $result,
        );
        $this->assertStringContainsString("password_hash", $result);
        $this->assertStringContainsString("planned change(s) applied", $result);

        // Fully in sync after the single apply.
        $second = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");
        $this->assertStringContainsString("Schema is in sync", $second);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_accepting_rename_prompt_declares_the_rename($driver, $config): void
    {
        // The interactive path (no --force): a scripted "yes" must reach
        // the rename prompt via the injected input stream, be honored as
        // an acceptance, and claim the old table — the re-plan emits the
        // real RenameTable change and no further prompt for the same old
        // table fires.
        self::setupDatabase($driver, $config, []);
        self::createLegacyTable('TestUser', [
            'id' => ColumnType::BigInt,
            'email' => ColumnType::Text,
            'full_name' => ColumnType::Text,
        ]);

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, "yes\n");
        rewind($stream);
        SyncCommand::useInputStream($stream);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        fclose($stream);
        SyncCommand::useInputStream(null);

        // The acceptance was honored — the rename was declared and applied.
        $this->assertMatchesRegularExpression(
            '/rename table \[TestUser\] to \[test_(users|user_twos)\]/',
            $result,
        );
        $this->assertStringContainsString("planned change(s) applied", $result);

        // The old table is gone (renamed away, not dropped) and the schema
        // is fully in sync afterwards.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertNotContains('TestUser', $tables);
        $second = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");
        $this->assertStringContainsString("Schema is in sync", $second);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_declining_rename_prompt_keeps_create_and_drop($driver, $config): void
    {
        // A scripted "no" must keep the flagged pair as-is: the plan shows
        // the create + drop (with the POSSIBLE RENAME annotation) and the
        // destructive gate handles the drop. Both creates pair with the
        // same legacy drop, so BOTH rename prompts fire (declining the
        // first frees the claim); the third answer confirms the drop.
        self::setupDatabase($driver, $config, []);
        self::createLegacyTable('TestUser', [
            'id' => ColumnType::BigInt,
            'email' => ColumnType::Text,
            'full_name' => ColumnType::Text,
        ]);

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, "no\nno\nyes\n");
        rewind($stream);
        SyncCommand::useInputStream($stream);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        fclose($stream);
        SyncCommand::useInputStream(null);

        // The decline was honored — no rename was declared.
        $this->assertStringNotContainsString("rename table [TestUser]", $result);
        $this->assertStringContainsString("planned change(s) applied", $result);

        // The old table was dropped and the new tables created.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertNotContains('TestUser', $tables);
        $this->assertContains('test_users', $tables);
        $this->assertContains('test_user_twos', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_with_no_matching_models_reports_empty($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/DoesNotExist/'"
        );

        $this->assertStringContainsString("No models matched", $result);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_dry_run_displays_plan_without_applying($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models --dry-run");

        $this->assertStringContainsString("Planned schema changes", $result);
        $this->assertStringContainsString("Dry run — no changes applied", $result);

        // Nothing was applied — the tables do not exist.
        $tables = Database::sqlConnection()->schemaInspector->tables();
        $this->assertNotContains('test_users', $tables);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_runs_transactionally_when_supported($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = CommandLine::execute("sync --dir=" . TEMP_ROOT . "App/Models");

        $this->assertStringContainsString("planned change(s) applied", $result);

        // SQLite supports transactional DDL; MySQL does not and is warned.
        if (Database::sqlConnection()->supportsTransactionalDdl()) {
            $this->assertStringNotContainsString("not be atomic", $result);
        } else {
            $this->assertStringContainsString("not be atomic", $result);
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_sync_no_transactional_flag_disables_warning($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --no-transactional"
        );

        $this->assertStringContainsString("planned change(s) applied", $result);
        $this->assertStringNotContainsString("not be atomic", $result);
    }

    public function test_sync_command_is_registered(): void
    {
        // Registration happens in Application::executeConsoleCommand(); boot
        // + a help listing proves the command is in the CLI route table.
        $result = CommandLine::execute("");

        $this->assertStringContainsString("sync", $result);
        $this->assertStringContainsString(
            "Sync the discovered models' schema",
            $result,
        );

        // The help listing is the only place options are visible — the
        // description must carry them.
        $this->assertStringContainsString("--dry-run", $result);
        $this->assertStringContainsString("--no-drop-tables", $result);
    }
}
