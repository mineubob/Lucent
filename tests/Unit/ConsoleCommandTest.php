<?php

namespace Unit;

use App\Commands\TestCommand;
use Lucent\Application;
use Lucent\Facades\CommandLine;
use Lucent\Logging\Channel;
use PHPUnit\Framework\TestCase;

class ConsoleCommandTest extends TestCase
{

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::generateTestConsoleCommand();
        self::generateTestCliFile();

        $channel = new Channel("phpunit","local_file","phpunit.log");
        Application::getInstance()->addLoggingChannel("phpunit", $channel);
    }


    public function test_basic_console_command() :void
    {
        CommandLine::register("test run","run",TestCommand::class);


        $result = CommandLine::execute("test run");

        $this->assertEquals("Test command successfully run", $result);
    }

    public function test_variable_console_command() :void
    {
        CommandLine::register("test var {var}","var",TestCommand::class);

        $result = CommandLine::execute("test var ABC");

        $this->assertEquals("ABC", $result);
    }

    public function test_commandline_from_cli() :void
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

    public function test_command_with_invalid_method() : void
    {
        CommandLine::register("test run","run2",TestCommand::class);

        $result = CommandLine::execute("test run");

        $this->assertEquals("Ops! We cant seem to find the method 'run2' inside 'App\Commands\TestCommand' please recheck your command registration.", $result);
    }

    public function test_command_with_invalid_controller() : void
    {
        CommandLine::register("test run","run",TestTwoCommand::class);

        $result = CommandLine::execute("test run");

        $this->assertEquals("Ops! We can seem to find the class 'Unit\TestTwoCommand' please recheck your command registration.", $result);
    }

    public function test_command_with_invalid_arguments() : void
    {
        CommandLine::register("test var {var}","var2",TestCommand::class);

        $result = CommandLine::execute("test var ABC");

        $this->assertEquals("Ops! App\Commands\TestCommand@var2 requires 1 parameters and 0 were provided.", $result);
    }

    public static function generateTestConsoleCommand() : void
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


        $appPath = TEMP_ROOT. "App";
        $commandsPath = $appPath . DIRECTORY_SEPARATOR . "Commands";

        if (!is_dir($commandsPath)) {
            mkdir($commandsPath, 0755, true);
        }

        file_put_contents(
            $commandsPath.DIRECTORY_SEPARATOR.'TestCommand.php',
            $commandContent
        );
    }

    public static function generateTestCliFile() : void
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
            TEMP_ROOT.DIRECTORY_SEPARATOR.'cli',
            $commandContent
        );
    }

}