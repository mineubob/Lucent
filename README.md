# Lucent PHP Framework

[![PHP Tests](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/tests.yml)
[![Build and Release](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/main.yml/badge.svg)](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/main.yml)
<a href="https://downgit.github.io/#/home?url=https://github.com/jackharrispeninsulainteractive/Lucent/raw/main/installer.php" target="_blank">
    <img src="https://img.shields.io/badge/Download-Installer-blue" alt="Download Installer">
</a>

Lucent is a lightweight PHP framework designed for building APIs with minimal overhead. It offers an elegant, intuitive syntax that will feel familiar to developers with experience in Laravel or Spring Boot.

## Overview

Lucent provides a streamlined approach to building PHP APIs with:

- Simple routing with REST and RPC support
- Database abstraction with support for MySQL and SQLite
- Model-based ORM with relationships
- [Route Model Binding](/docs/RouteModelBinding.md)
- Request validation
- Middleware support
- Comprehensive logging
- CLI tools for development and maintenance

## Getting Started

### Installation

You can install Lucent using our installer script. The installer will set up the basic directory structure and download the latest version of the framework.

1. Download the installer:
   ```bash
   wget https://github.com/jackharrispeninsulainteractive/Lucent/raw/main/installer.php
   ```

2. Run the installer:
   ```bash
   php installer.php
   ```

3. Start the development server:
   ```bash
   cd public
   php -S localhost:8080 index.php
   ```

### Project Structure

After installation, your project will have the following structure:

```
myapp/
├── App/
│   ├── Commands/
│   ├── Controllers/
│   ├── Models/
│   └── Rules/
├── packages/
│   └── lucent.phar
├── public/
│   └── index.php
├── routes/
│   └── api.php
├── storage/
├── logs/
├── .env
└── cli
```

### Composer Support

Lucent provides seamless integration with Composer, PHP's dependency manager. While Lucent itself is packaged as a PHAR archive for simplicity and performance, your project can utilize any Composer packages alongside it.

To use Composer in your Lucent project:

1. Navigate to the packages directory:
   ```bash
   cd packages
   ```

2. Initialize Composer in this directory:
   ```bash
   composer init
   ```

3. Install any dependencies you need:
   ```bash
   composer require package/name
   ```

4. Lucent will automatically detect and use Composer's autoloader if it exists in the `packages/vendor/autoload.php` path.

This setup allows you to leverage the entire PHP ecosystem while maintaining Lucent's lightweight architecture.

### Basic Configuration

Configure your database connection and other settings in the `.env` file:

```env
DB_USERNAME=root
DB_PASSWORD=
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=lucent
DB_DRIVER=mysql
```

## Creating APIs

### Routes

Define your API routes in `routes/api.php`:

```php
<?php

use Lucent\Facades\Route;
use App\Controllers\UserController;

// Simple REST API group
Route::rest()->group('users')
    ->prefix('api/users')
    ->defaultController(UserController::class)
    ->middleware([AuthMiddleware::class]);
```

For more detailed information about controllers and models, please see the dedicated documentation pages.

## Database Migrations

Create database migrations for your models:

```bash
php cli Migration make App/Models/User
```

## CLI Commands

Lucent includes several CLI commands to help with development:

```bash
# Generate API documentation
php cli generate api-docs

# Check for updates
php cli update check

# Install the latest version
php cli update install

# Rollback to previous version
php cli update rollback
```

## Updating Lucent

To update Lucent to the latest version, use the update CLI command:

```bash
php cli update install
```

If you need to rollback to a previous version:

```bash
php cli update rollback
```

## Documentation

You can generate API documentation for your project:

```bash
php cli generate api-docs
```

This will create HTML documentation in the `storage/documentation` directory.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

Lucent is open-sourced software licensed under the MIT license.
