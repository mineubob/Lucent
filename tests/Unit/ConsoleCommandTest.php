<?php

namespace Unit;

use App\Commands\TestCommand;
use Lucent\Application;
use Lucent\Facades\CommandLine;
use Lucent\Facades\FileSystem;
use PHPUnit\Framework\TestCase;

class ConsoleCommandTest extends TestCase
{

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::generateTestConsoleCommand();
        self::generateTestCliFile();

    }

    protected function setUp(): void
    {
        parent::setUp();
        Application::getInstance()->consoleRouter->reset();
        CommandLine::captureOutput();
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

        $this->assertEquals('Class "Unit\TestTwoCommand" does not exist', $result);
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
        $output = shell_exec("cd " . escapeshellarg(dirname(__DIR__, 2)) . " && php bin/lucent help");
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

    public static function generateTestConsoleCommand(): void
    {
        $commandContent = <<<'PHP'
        <?php
        namespace App\Commands;
        
        class TestCommand
        {
           
            public function run() : string
            {
                 return "Test command successfully run";
            }
            
                
            public function var($var) : string
            {
                 return $var;
            }
            
             public function var2() : string
            {
                 return "var2";
            }

        }
        PHP;


        $appPath = TEMP_ROOT . "App";
        $commandsPath = $appPath . DIRECTORY_SEPARATOR . "Commands";

        if (!is_dir($commandsPath)) {
            mkdir($commandsPath, 0755, true);
        }

        file_put_contents(
            $commandsPath . DIRECTORY_SEPARATOR . 'TestCommand.php',
            $commandContent
        );
    }

    public static function generateTestCliFile(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $commandContent = <<<'PHP'
        #!/usr/bin/env php
        <?php
        use Lucent\Application;
        use Lucent\Facades\CommandLine;
        use App\Commands\TestCommand;

        $_SERVER["REQUEST_METHOD"] = "CLI";

        // Load the test bootstrap so RUNNING_LOCATION/FileSystem/App autoloader
        // are configured the same way as the parent test process.
        require_once 'REPO_ROOT/tests/bootstrap.php';

        $app = Application::getInstance();

        CommandLine::captureOutput();
        CommandLine::register("test run", "run", TestCommand::class);

        echo $app->executeConsoleCommand();
        PHP;

        $commandContent = str_replace('REPO_ROOT', $repoRoot, $commandContent);

        file_put_contents(
            TEMP_ROOT . DIRECTORY_SEPARATOR . 'cli',
            $commandContent
        );
    }

}