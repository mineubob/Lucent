[Home](../README.md)

# Installation

Lucent is distributed as a Composer package. There are two ways to get started:

## Create a New Project

The recommended way to start a new Lucent application is via `composer create-project`:

```bash
composer create-project blueprintau/lucent myapp
cd myapp
vendor/bin/lucent serve
```

This scaffolds a complete project with the following structure:

```
myapp/
├── App/
│   ├── Commands/
│   ├── Controllers/
│   ├── Models/
│   └── Rules/
├── commands/              # CLI command files (auto-loaded)
├── routes/                # Route files (auto-loaded)
├── public/
│   └── index.php          # HTTP entry point
├── storage/
├── logs/
├── vendor/
├── .env
└── composer.json
```

## Add to an Existing Project

To add Lucent to an existing Composer project:

```bash
composer require blueprintau/lucent
```

Then create the following entry points:

### HTTP Entry Point (`public/index.php`)

```php
<?php
require_once '../vendor/autoload.php';
echo Lucent\Facades\App::Execute();
```

### CLI Entry Point

Lucent installs a `vendor/bin/lucent` binary automatically. Run commands with:

```bash
vendor/bin/lucent serve
vendor/bin/lucent migration make App/Models/User
vendor/bin/lucent generate api-docs
```

## Auto-Loaded Directories

Lucent automatically discovers and loads files from two directories in your project root:

- **`routes/`** — Drop `.php` route files here; they are loaded during `boot()`.
- **`commands/`** — Drop `.php` command files here; they are loaded during `boot()`.

Both are non-recursive (top-level `*.php` files only). To disable auto-loading, call `Application::boot(false, false)` before `App::Execute()` and register routes/commands explicitly.

## Updating

```bash
composer update blueprintau/lucent
```

## Requirements

- PHP >= 8.4
- ext-curl, ext-mysqli, ext-pdo, ext-fileinfo, ext-zip
