<?php

namespace Tests\Feature;

use App\Models\Medias;
use App\Models\RebuildTarget;
use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CapturesCommandOutput;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\TestCase;
use Lucent\Console\SyncCommand;
use Lucent\Facades\CommandLine;

/**
 * `sync --step` — hand-authored data steps spliced between the plan's
 * ADD-phase changes and its destructive drops.
 *
 * Each test writes its step files at runtime under TEMP_ROOT (wiped every
 * run — no committed .sql fixtures). The scenario model is the motivating
 * case: a live `medias` table with legacy nullable FK columns
 * (post_id/event_id/profile_id) whose model declares the morph pair
 * (attachable_type/attachable_id) instead — the plan is add ×2 + drop ×3
 * (plus any live-shape drift), and the step backfills the morph pair
 * between the adds and the drops.
 */
class SyncCommandStepTest extends TestCase
{
    use CapturesCommandOutput;
    use CopiesFixtures;
    use DatabaseTesting;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Model' => ['Medias.php', 'RebuildTarget.php'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureCommandOutput();
    }

    // ---- Scenario builders -------------------------------------------------

    /**
     * Create the live `medias` table in the legacy shape (morph columns
     * absent, legacy FK-shaped columns present) and populate it.
     */
    private static function createLegacyMediasTable(): void
    {
        $connection = Database::sqlConnection();

        $connection->statement('CREATE TABLE medias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER,
            event_id INTEGER,
            profile_id INTEGER
        )');

        $connection->statement(
            "INSERT INTO medias (post_id, event_id, profile_id) VALUES (11, NULL, NULL)"
        );
        $connection->statement(
            "INSERT INTO medias (post_id, event_id, profile_id) VALUES (NULL, 22, NULL)"
        );
        $connection->statement(
            "INSERT INTO medias (post_id, event_id, profile_id) VALUES (NULL, NULL, 33)"
        );
    }

    /**
     * Create a minimal `medias` table matching the model's id column, so
     * the plan is purely additive (add the morph pair) — the shape the
     * non-destructive marker test needs (nothing else may trip the gate).
     */
    private static function createMinimalMediasTable(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true);

        Database::sqlConnection()->create($blueprint);
    }

    /**
     * Write a step file under TEMP_ROOT/steps and return its absolute path.
     */
    private static function writeStepFile(string $filename, string $contents): string
    {
        $dir = TEMP_ROOT . 'steps';

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $contents);

        return $path;
    }

    private const BACKFILL_STEP = <<<'SQL'
        UPDATE medias SET attachable_type = 'post', attachable_id = post_id WHERE post_id IS NOT NULL;
        UPDATE medias SET attachable_type = 'event', attachable_id = event_id WHERE event_id IS NOT NULL;
        UPDATE medias SET attachable_type = 'profile', attachable_id = profile_id WHERE profile_id IS NOT NULL;
        SQL;

    // ---- Tests -------------------------------------------------------------

    #[DataProvider('databaseDriverProvider')]
    public function test_step_splices_between_adds_and_drops_and_backfills($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::writeStepFile('morph-backfill.sql', self::BACKFILL_STEP);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // The step renders with its distinct marker and statement count.
        $this->assertStringContainsString(
            'data step [morph-backfill.sql]: 3 statement(s)',
            $result
        );

        // Splice position: the step appears AFTER the morph-pair adds and
        // BEFORE the legacy-FK drops.
        $addPos = strpos($result, 'attachable_type');
        $stepPos = strpos($result, 'data step [morph-backfill.sql]');
        $dropPos = strpos($result, 'drop column(s) [post_id');

        $this->assertNotFalse($addPos, 'Plan must list the add of attachable_type');
        $this->assertNotFalse($stepPos, 'Plan must list the data step');
        $this->assertNotFalse($dropPos, 'Plan must list the drop of post_id');
        $this->assertLessThan($stepPos, $addPos, 'Step must run after the adds');
        $this->assertLessThan($dropPos, $stepPos, 'Step must run before the drops');

        // The backfill actually ran: the morph pair is populated.
        $connection = Database::sqlConnection();
        $rows = $connection->selectSql(
            'SELECT attachable_type, attachable_id FROM medias ORDER BY id',
            []
        );

        $this->assertCount(3, $rows);
        $this->assertSame('post', $rows[0]->attachable_type);
        $this->assertSame(11, (int) $rows[0]->attachable_id);
        $this->assertSame('event', $rows[1]->attachable_type);
        $this->assertSame(22, (int) $rows[1]->attachable_id);
        $this->assertSame('profile', $rows[2]->attachable_type);
        $this->assertSame(33, (int) $rows[2]->attachable_id);

        // The drops ran after the step: legacy FK columns are gone.
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertNotContains('post_id', $columns);
        $this->assertNotContains('event_id', $columns);
        $this->assertNotContains('profile_id', $columns);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_multiple_steps_keep_command_line_order($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $first = self::writeStepFile('a-first.sql', self::BACKFILL_STEP);
        $second = self::writeStepFile('b-second.sql', self::BACKFILL_STEP);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$first} --step={$second} --force"
        );

        $firstPos = strpos($result, 'data step [a-first.sql]');
        $secondPos = strpos($result, 'data step [b-second.sql]');

        $this->assertNotFalse($firstPos);
        $this->assertNotFalse($secondPos);
        $this->assertLessThan($secondPos, $firstPos, 'Steps must run in command-line order');
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_unterminated_step_file_aborts_before_any_ddl($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        // No `;\n` anywhere — no statement is terminated under the
        // documented restriction.
        $stepPath = self::writeStepFile(
            'broken.sql',
            "UPDATE medias SET attachable_type = 'post'\n"
        );

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // Clear error naming the file and the offending line.
        $this->assertStringContainsString('broken.sql', $result);
        $this->assertStringContainsString("not terminated", $result);

        // Nothing applied — the plan never ran: morph columns absent,
        // legacy columns intact, data untouched.
        $connection = Database::sqlConnection();
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);

        $rows = $connection->selectSql('SELECT COUNT(*) AS c FROM medias', []);
        $this->assertSame(3, (int) $rows[0]->c);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bare_step_flag_is_a_usage_error($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step --force"
        );

        $this->assertStringContainsString('--step option expects a file path', $result);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_unreadable_step_file_aborts_before_any_ddl($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' "
            . "--step=" . TEMP_ROOT . 'steps/does-not-exist.sql --force'
        );

        $this->assertStringContainsString('not readable', $result);
        $this->assertStringContainsString('does-not-exist.sql', $result);

        // Nothing applied.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
    }

    public function test_non_destructive_marker_skips_the_gate(): void
    {
        // SQLite-only: the marker's gate behavior is dialect-independent,
        // and this test needs a purely additive plan (nothing else may trip
        // the gate).
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createMinimalMediasTable();

        // An INSERT-only step: non-destructive by classification. Marked,
        // it must run WITHOUT --force and WITHOUT an input stream — the
        // LUCENT_NON_INTERACTIVE fail-fast gate ("no TTY available") must
        // NOT fire for it.
        $stepPath = self::writeStepFile(
            'audit-insert.sql',
            "-- non-destructive\n"
            . "INSERT INTO medias (attachable_type, attachable_id) VALUES ('post', 99);\n"
        );

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath}"
        );

        // No TTY gate error, and the step ran.
        $this->assertStringNotContainsString('no TTY available', $result);
        $this->assertStringContainsString('data step [audit-insert.sql]: 1 statement(s)', $result);
        $this->assertStringContainsString('planned change(s) applied', $result);

        $rows = Database::sqlConnection()->selectSql(
            "SELECT COUNT(*) AS c FROM medias WHERE attachable_type = 'post' AND attachable_id = 99",
            []
        );
        $this->assertSame(1, (int) $rows[0]->c);
    }

    public function test_unmarked_step_hits_the_no_tty_gate(): void
    {
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createLegacyMediasTable();

        // The SAME file without the marker is destructive — with no TTY
        // and no input stream, the run must fail fast before applying
        // anything.
        $stepPath = self::writeStepFile('gate-check.sql', self::BACKFILL_STEP);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath}"
        );

        $this->assertStringContainsString('no TTY available', $result);

        // Nothing applied — morph columns absent.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertNotContains('attachable_type', $columns);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_dry_run_prints_step_contents_and_applies_nothing($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        self::createLegacyMediasTable();

        $stepPath = self::writeStepFile('morph-backfill.sql', self::BACKFILL_STEP);

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --dry-run"
        );

        $this->assertStringContainsString('Planned schema changes', $result);
        $this->assertStringContainsString('data step [morph-backfill.sql]: 3 statement(s)', $result);

        // The FULL step file contents are printed under the Data steps
        // header (first statement verbatim).
        $this->assertStringContainsString('Data steps:', $result);
        $this->assertStringContainsString(
            "UPDATE medias SET attachable_type = 'post', attachable_id = post_id WHERE post_id IS NOT NULL;",
            $result
        );

        $this->assertStringContainsString('Dry run — no changes applied', $result);

        // Nothing applied — morph columns absent, legacy columns intact.
        $columns = array_column(
            Database::sqlConnection()->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns);
        $this->assertNotContains('attachable_type', $columns);
    }

    public function test_failing_statement_rolls_the_plan_back(): void
    {
        // SQLite only: the rollback guarantee (transactional DDL) is the
        // assertion's subject, and MySQL's auto-committing DDL has no
        // equivalent behavior to assert.
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);
        self::createLegacyMediasTable();

        // First statement is valid SQL but references a column that will
        // NOT exist in the plan's order of operations at step time — a
        // data-step syntax/runtime error. The whole plan fails; the
        // transaction rolls back: no morph columns, legacy columns intact.
        $stepPath = self::writeStepFile(
            'explodes.sql',
            "UPDATE medias SET attachable_type = 'post';\n"
            . "UPDATE medias SET no_such_column = 1;\n"
        );

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/Medias$/' --step={$stepPath} --force"
        );

        // The error surfaced (column not found).
        $this->assertStringContainsString('no_such_column', $result);

        // The transaction rolled back: the add before the step is undone.
        $connection = Database::sqlConnection();
        $columns = array_column(
            $connection->schemaInspector->table('medias')->columns,
            'name',
        );
        $this->assertContains('post_id', $columns, 'Legacy column must survive the rollback');
        $this->assertNotContains('attachable_type', $columns, 'Add before the failed step must roll back');
    }

    public function test_step_keeps_its_position_when_the_plan_defers(): void
    {
        // SQLite only: the deferral (FK-involved rebuild needing the
        // foreign_keys PRAGMA toggle outside any transaction) is a SQLite
        // behavior.
        self::setupDatabase('sqlite', ['driver' => 'sqlite', 'database' => ':memory:'], []);

        // A populated table the model will modify, REFERENCED by another
        // table: the NOT NULL-no-default add routes through the SQLite
        // rebuild, and the referencing table makes the rebuild FK-involved
        // → the whole plan (with the steps spliced into it) defers past
        // the lock.
        $connection = Database::sqlConnection();
        $connection->statement('CREATE TABLE rebuild_targets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL
        )');
        $connection->statement('CREATE TABLE watchers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_id INTEGER NOT NULL,
            FOREIGN KEY (target_id) REFERENCES rebuild_targets(id)
        )');
        $connection->statement(
            "INSERT INTO rebuild_targets (label) VALUES ('one'), ('two')"
        );

        $stepPath = self::writeStepFile(
            'touch.sql',
            "UPDATE rebuild_targets SET status = 'migrated' WHERE label = 'one';\n"
        );

        $result = CommandLine::execute(
            "sync --dir=" . TEMP_ROOT . "App/Models --filter='/RebuildTarget$/' --step={$stepPath} --force"
        );

        // The deferred leg applied everything — including the step, in
        // its plan position (the step ran; its effect is visible).
        $this->assertStringContainsString('data step [touch.sql]: 1 statement(s)', $result);
        $this->assertStringContainsString('planned change(s) applied', $result);

        // The rebuild realized the desired shape AND the step ran after it.
        $columns = array_column(
            $connection->schemaInspector->table('rebuild_targets')->columns,
            'name',
        );
        $this->assertContains('status', $columns);

        $rows = $connection->selectSql(
            "SELECT status FROM rebuild_targets WHERE label = 'one'",
            []
        );
        $this->assertSame('migrated', $rows[0]->status, 'Step must have run in the deferred leg');
    }

    public function test_sync_command_help_lists_step_option(): void
    {
        $result = CommandLine::execute('');

        $this->assertStringContainsString('--step=', $result);
        $this->assertStringContainsString('repeatable', $result);
    }
}
