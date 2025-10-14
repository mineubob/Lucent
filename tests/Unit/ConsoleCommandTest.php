<?php

namespace Unit;

use App\Commands\TestCommand;
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

        $this->assertEquals("Invalid command: The method 'run2' is not defined in the 'App\Commands\TestCommand' class.\nPlease verify the command registration and the controller's method.", $result);
    }

    public function test_command_with_invalid_controller(): void
    {
        CommandLine::register("test run", "run", TestTwoCommand::class);

        $result = CommandLine::execute("test run");

        $this->assertEquals("Command registration error: The controller class 'Unit\TestTwoCommand' could not be found.\nPlease check your command registration and ensure the class exists.", $result);
    }

    public function test_command_with_invalid_arguments(): void
    {
        CommandLine::register("test var {var}", "var2", TestCommand::class);

        $result = CommandLine::execute("test var ABC");

        $this->assertEquals("Insufficient arguments! The command requires at least 1 parameters.\nUsage: test var {var} ", $result);
    }

    public function test_command_call_on_phar_directly(): void
    {
        $this->assertTrue(file_exists(FileSystem::rootPath() . DIRECTORY_SEPARATOR . "packages" . DIRECTORY_SEPARATOR . "lucent.phar"));
        $output = exec("cd " . FileSystem::rootPath() . DIRECTORY_SEPARATOR . "packages" . " && php lucent.phar update rollback");

        $this->assertEquals("No backup versions found to roll back to.", $output);
    }

    public function test_command_call_with_semi_column() : void
    {
        CommandLine::register("test:run", "run", TestCommand::class);

        $result = CommandLine::execute("test:run");

        $this->assertEquals("Test command successfully run", $result);

    }

    public function test_command_help_page() : void
    {
        ob_start();
        CommandLine::execute("");
        $result = ob_get_clean();

        $this->assertStringContainsString("Available commands:", $result);
        $this->assertStringContainsString("Migration make {class}", $result);
        $this->assertStringContainsString("update check", $result);
        $this->assertStringContainsString("update rollback", $result);
        $this->assertStringContainsString("update install", $result);
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
        $commandContent = <<<'PHP'
        #!/usr/bin/env php
        <?php
        use Lucent\Application;
        use Lucent\Facades\CommandLine;
        use App\Commands\TestCommand;
        
        $_SERVER["REQUEST_METHOD"] = "CLI";
        
        require_once 'packages/lucent.phar';
        
        $app = Application::getInstance();
        
        CommandLine::register("test run", "run", TestCommand::class);
        
        echo $app->executeConsoleCommand();
        PHP;

        file_put_contents(
            TEMP_ROOT . DIRECTORY_SEPARATOR . 'cli',
            $commandContent
        );
    }

}