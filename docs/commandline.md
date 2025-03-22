[Home](../README.md)

# Lucent Commandline

Lucent provides a powerful and flexible commandline interface for managing your application, executing tasks, and automating common development workflows. This guide explains how to use the built-in commands and create your own custom commands.

## Table of Contents

- [Overview](#overview)
- [Built-in Commands](#built-in-commands)
- [Creating Custom Commands](#creating-custom-commands)
    - [Command Structure](#command-structure)
    - [Registering Commands](#registering-commands)
    - [Handling Parameters](#handling-parameters)
- [Running Commands](#running-commands)
    - [Using the CLI Script](#using-the-cli-script)
    - [Executing Commands Programmatically](#executing-commands-programmatically)
- [Commandline Components](#commandline-components)
- [Advanced Usage](#advanced-usage)
    - [Command Groups](#command-groups)
    - [Error Handling](#error-handling)
- [Best Practices](#best-practices)

## Overview

The Lucent commandline interface provides a way to execute tasks from the terminal. These tasks can range from database migrations to generating code to running scheduled jobs. The commandline system is designed to be:

- **Simple**: Easy to create and register new commands
- **Flexible**: Support for parameters, options, and command groups
- **Extensible**: Can be customized to suit your application's needs

## Built-in Commands

Lucent comes with several built-in commands:

| Command | Description |
|---------|-------------|
| `migration make {class}` | Creates or updates database tables based on model classes |
| `update check` | Checks for available Lucent framework updates |
| `update install` | Downloads and installs the latest Lucent framework version |
| `update rollback` | Reverts to the previous Lucent framework version |
| `generate api-docs` | Generates API documentation from controller annotations |

To run these commands, use the CLI script in your project root:

```bash
php cli migration make App/Models/User
```

## Creating Custom Commands

### Command Structure

Custom commands in Lucent are simple PHP classes with public methods that represent different command actions. Each method should return a string which will be displayed as the command output.

A basic command class looks like this:

```php
<?php
namespace App\Commands;

class ExampleCommand
{
    public function run(): string
    {
        // Command logic goes here
        return "Command executed successfully";
    }
    
    public function status(): string
    {
        // Check status logic
        return "System status: OK";
    }
}
```

### Registering Commands

To make your commands available to the Lucent commandline system, you need to register them using the `CommandLine` facade. This is typically done in your CLI script or in a command registration file.

```php
<?php
use Lucent\Facades\CommandLine;
use App\Commands\ExampleCommand;

// Register a simple command
CommandLine::register("example run", "run", ExampleCommand::class);

// Register another action for the same command
CommandLine::register("example status", "status", ExampleCommand::class);
```

The `register` method takes three parameters:
1. The command string (including any parameters)
2. The method name to execute in your command class
3. The fully qualified class name of your command

### Handling Parameters

Commands can accept parameters by including parameter placeholders in curly braces when registering the command:

```php
<?php
namespace App\Commands;

class UserCommand
{
    public function create(string $name, string $email): string
    {
        // Create a new user
        return "Created user {$name} with email {$email}";
    }
}
```

Register the command with parameter placeholders:

```php
CommandLine::register("user create {name} {email}", "create", UserCommand::class);
```

When executing the command, provide the parameter values:

```bash
php cli user create JohnDoe john@example.com
```

## Running Commands

### Using the CLI Script

The recommended way to run commands is through the CLI script in your project root:

```bash
php cli command [arguments]
```

For example:

```bash
php cli migration make App/Models/User
php cli update check
php cli generate api-docs
```

The CLI script is automatically generated when you install the Lucent framework, so you don't need to create it manually.

### Executing Commands Programmatically

You can also execute commands programmatically within your application:

```php
use Lucent\Facades\CommandLine;

$result = CommandLine::execute("user create JohnDoe john@example.com");
echo $result; // Outputs: "Created user JohnDoe with email john@example.com"
```

## Commandline Components

Lucent provides several useful components to enhance your command-line applications. These components help improve the user experience and make your CLI tools more interactive and informative.

| Component | Description | Documentation |
|-----------|-------------|---------------|
| ProgressBar | Displays real-time progress updates in the terminal for long-running tasks. Supports customizable formats, appearance, and update frequency. | [ProgressBar Documentation](progress-bar.md) |
| ConsoleColors | Utility for adding colored output to your command-line applications. | *Coming soon* |

These components can be used individually or combined to create rich, interactive command-line experiences for your users.

## Advanced Usage

### Command Groups

You can organize related commands into groups for better structure:

```php
// Database group
CommandLine::register("db:migrate", "migrate", DatabaseCommand::class);
CommandLine::register("db:seed", "seed", DatabaseCommand::class);

// User management group
CommandLine::register("user:create {name}", "create", UserCommand::class);
CommandLine::register("user:delete {id}", "delete", UserCommand::class);
```

### Error Handling

Command methods should handle exceptions internally and return appropriate error messages:

```php
public function riskyOperation(): string
{
    try {
        // Operation that might fail
        return "Operation completed successfully";
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
}
```

## Best Practices

1. **Separation of Concerns**: Keep command classes focused on specific functionality areas.

2. **Command Naming**: Use descriptive names for commands and follow a consistent pattern (e.g., `noun:verb`).

3. **Method Parameters**: Define method parameters with appropriate type hints for better error messages.

4. **Documentation**: Add comments to your command methods explaining their purpose and parameter requirements.

5. **Feedback**: Provide clear success and error messages. For longer operations, use the ProgressBar component.

6. **Testing**: Create tests for your commands to ensure they work as expected.

7. **Organization**: Group related commands in the same class, and organize complex command hierarchies with namespaces.

---

For more information on specific commandline features, see:
- [ProgressBar Component](./commandline/progress-bar.md) - Detailed documentation on the progress bar component