<?php

namespace Tests\Feature;

use App\Commands\TestCommand;
use Lucent\Facades\CommandLine;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\CapturesCommandOutput;
use Tests\Support\FixtureLoader;

class ConsoleCommandTest extends TestCase
{
    use CapturesCommandOutput;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        FixtureLoader::copyCommand('TestCommand.php');
        FixtureLoader::copyCliTemplate();

    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureCommandOutput();
    }


    public function test_basic_console_command(): void
    {
        CommandLine::register("test run", "run", TestCommand::class);

        $result = CommandLine::execute("test run");

        $this->assertEquals("Test command successfully run", $result);
    }

    public function test_variable_console_command(): void
    {
        CommandLine::register("test var {var}", "var", \App\Commands\TestCommand::class);

        $result = CommandLine::execute("test var ABC");

        $this->assertEquals("ABC", $result);
    }

    public function test_commandline_from_cli(): void
    {
        //As we are executing a new php process, we need to register our command in the cli script file, not here.
        //CommandLine::register("test run", "run", TestCommand::class);

        $tempInstallPath = realpath(__DIR__ . '/../../temp_install/');
        if (!$tempInstallPath) {
            $this->fail('temp_install directory not found');
        }

        chdir($tempInstallPath);
        $output = shell_exec('php ' . $tempInstallPath . DIRECTORY_SEPARATOR . 'cli test run');

        $this->assertEquals("Test command successfully run", $output);
    }

    public function test_command_with_invalid_method(): void
    {
        CommandLine::register("test run", "run2", TestCommand::class);

        $result = CommandLine::execute("test run");

        $this->assertEquals("Method App\Commands\TestCommand::run2() does not exist", $result);
    }

    public function test_command_with_invalid_controller(): void
    {
        CommandLine::register("test run", "run", TestTwoCommand::class);

        $result = CommandLine::execute("test run");

        $this->assertEquals('Class "Tests\Feature\TestTwoCommand" does not exist', $result);
    }

    public function test_command_with_invalid_arguments(): void
    {
        CommandLine::register("test var {var}", "var2", TestCommand::class);

        $result = CommandLine::execute("test var ABC");

        $this->assertEquals("Insufficient arguments! The command requires at least 1 parameters.\nUsage: test var {var} ", $result);
    }

    public function test_command_call_on_binary_directly(): void
    {
        // The self-update commands were removed in the Composer migration.
        // This test asserts the CLI binary is dispatchable and shows help.
        // Note: bin/lucent runs from the repo root (where vendor/ is), so
        // FileSystem::rootPath() resolves to the repo root, not temp_install/.
        // The .env warning is expected — the help command doesn't need env vars.
        $repoRoot = dirname(__DIR__, 2);
        $output = shell_exec("cd " . escapeshellarg($repoRoot) . " && php bin/lucent help");
        $this->assertStringContainsString("Available commands:", (string) $output);
    }

    public function test_command_call_with_semi_column() : void
    {
        CommandLine::register("test:run", "run", TestCommand::class);

        $result = CommandLine::execute("test:run");

        $this->assertEquals("Test command successfully run", $result);

    }

    public function test_command_help_page() : void
    {
        $result = CommandLine::execute("");

        $this->assertStringContainsString("Available commands:", $result);
        $this->assertStringContainsString("migration make {class}", $result);
        $this->assertStringContainsString("generate api-docs", $result);
        $this->assertStringContainsString("serve", $result);
    }

    public function test_command_disabled_all() : void
    {
        CommandLine::register("test run", "run", TestCommand::class);
        CommandLine::disableCommand("*");
        $result = CommandLine::execute("test run");

        $this->assertStringStartsWith("Unrecognized command.", $result);
    }

    public function test_command_disabled_single() : void
    {
        CommandLine::register("test run", "run", TestCommand::class);
        CommandLine::disableCommand("test run");

        $result = CommandLine::execute("test run");

        $this->assertStringStartsWith("Unrecognized command.", $result);

    }

    public function test_command_disabled_multiple() : void
    {
        CommandLine::disableCommand(["deploy latest", "deploy rollback"]);

        $result = CommandLine::execute("help");

        $this->assertStringContainsString("Available commands:", $result);
        $this->assertStringContainsString("migration make {class}", $result);
        $this->assertStringNotContainsString("deploy latest", $result);
        $this->assertStringNotContainsString("deploy rollback", $result);
        $this->assertStringContainsString("generate api-docs", $result);
        $this->assertStringContainsString("serve", $result);
    }


}