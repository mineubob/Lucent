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
| `generate api-docs` | Generates API documentation based on your controller attributes |
| `serve` | Starts the built-in PHP development server |
| `deploy latest` | Downloads and deploys the latest project release |
| `deploy rollback` | Rolls back to the most recent backup |

> **Note:** Lucent is now managed via Composer. To update the framework, run `composer update blueprintau/lucent` instead of using CLI update commands.

## Running Commands

Lucent commands are run via the `vendor/bin/lucent` binary:

```bash
vendor/bin/lucent migration make App/Models/User
```

To run these commands, use the `vendor/bin/lucent` binary in your project root:

```bash
vendor/bin/lucent migration make App/Models/User
```

### The `serve` Command

`serve` starts the PHP built-in development server. It uses a **router script** so every request is forwarded through Lucent — meaning routes like `/users` work instead of returning a 404 — while existing static files are still served directly.

```bash
vendor/bin/lucent serve
```

All values are configurable. Precedence is **CLI option > env var > default**:

| CLI option | Env var | Default | Description |
|------------|---------|---------|-------------|
| `--port` | `SERVER_PORT` | `8080` | Port to bind |
| `--host` | `SERVER_HOST` | `127.0.0.1` | Host/interface to bind |
| `--docroot` | `SERVER_DOCROOT` | `public` | Document root (relative to project root) |
| `--router` | `SERVER_ROUTER` | *(bundled)* | Router script path (relative to project root) |
| `--tries` | `SERVER_TRIES` | `10` | Max ports to try if the requested one is busy |
| `--no-restart` | `SERVER_NO_RESTART` | `false` | Disable auto-restart when `.env` changes |

```bash
# CLI options
vendor/bin/lucent serve --port=9000 --host=127.0.0.1
vendor/bin/lucent serve --no-restart

# Or via .env
# SERVER_PORT=9000
# SERVER_HOST=127.0.0.1
# SERVER_NO_RESTART=true
```

### The router script

`serve` uses a router script to decide how each request is handled. The router is selected in this order:

1. **A `server.php` in your project root**, if present — this is the recommended way to customise routing (e.g. SPA-shell fallback).
2. **The `--router` option / `SERVER_ROUTER` env var**, if set.
3. **The bundled router shipped with Lucent** — the default.

The bundled router serves existing static files directly (returning `false` hands control back to the server) and forwards everything else to your `public/index.php`:

```php
<?php
$publicPath = getcwd(); // the document root (serve runs with CWD = docroot)
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ($uri !== '/' && file_exists($publicPath . $uri)) {
    return false; // serve the static file directly
}

require_once $publicPath . '/index.php'; // forward everything else to Lucent
```

### Customising the router (e.g. SPA-shell fallback)

If your app needs custom routing — such as serving an SPA shell for non-API routes — you have two options:

- **Publish a `server.php`** to your project root. It runs with the document root as its working directory, so use `getcwd()` to resolve paths. For example, an SPA shell:

  ```php
  <?php
  $publicPath = getcwd();
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

  if (!str_starts_with($path, '/api')) {
      $file = $publicPath . $path;
      if (is_file($file)) return false; // serve the real file

      // No file → serve the SPA shell so client-side routing works
      header('Content-Type: text/html');
      readfile($publicPath . '/index.html');
      return true; // tell cli-server to use our output
  }

  require_once $publicPath . '/index.php';
  ```

- **Or pass `--router=public/index.php`** (or set `SERVER_ROUTER`) to use your front controller as the router, with your SPA logic at the top of `index.php`.

### Auto-restart on `.env` changes

By default, `serve` watches `.env` and **restarts the server automatically** when it changes, so new environment values take effect without you manually restarting. This mirrors Laravel's behavior. Disable it with `--no-restart` (or `SERVER_NO_RESTART=true`).

### Port auto-increment

If the requested port is already in use, `serve` automatically tries the next port up to `--tries` times (default 10), mirroring Laravel. This only applies when the port wasn't explicitly set.

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

The `register` method takes four parameters:
1. The command string (including any parameter placeholders)
2. The method name to execute in your command class
3. The fully qualified class name of your command
4. (Optional) A short description shown in the `vendor/bin/lucent` help output

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
vendor/bin/lucent user create JohnDoe john@example.com
```

## Running Commands

### Using the CLI Binary

The recommended way to run commands is through the `vendor/bin/lucent` binary in your project root:

```bash
vendor/bin/lucent command [arguments]
```

For example:

```bash
vendor/bin/lucent migration make App/Models/User
vendor/bin/lucent generate api-docs
```

The binary is installed automatically by Composer when you require `blueprintau/lucent`, so you don't need to create it manually.

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
| ProgressBar | Displays real-time progress updates in the terminal for long-running tasks. Supports customizable formats, appearance, and update frequency. | [ProgressBar Documentation](./commandline/progress-bar.md) |
| ConsoleColors | Utility for adding colored output to your command-line applications. Located in `Lucent\Logging\ConsoleColors`. | *Documented inline in the class* |

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
