# Lucent PHP Framework

[![PHP Tests](https://github.com/blueprintau/Lucent/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/blueprintau/Lucent/actions/workflows/tests.yml)
[![Code Coverage](https://img.shields.io/badge/Coverage-Report-brightgreen)](https://blueprintau.github.io/Lucent/)
[![Packagist](https://img.shields.io/packagist/v/blueprintau/lucent.svg)](https://packagist.org/packages/blueprintau/lucent)

Lucent is a lightweight PHP framework designed for building APIs with minimal overhead. It offers an elegant, intuitive syntax that will feel familiar to developers with experience in Laravel or Spring Boot.

Lucent provides a streamlined approach to building PHP APIs with:

- Simple routing with REST
- [Database abstraction with support for MySQL and SQLite](./docs/database.md)
- [Model-based ORM with relationships](./docs/orm.md)
- [Route Model Binding](./docs/route-model-binding.md)
- [Rules & Validation](./docs/rules-and-validation.md)
- Middleware support
- [Comprehensive PSR-3 compliant logging](./docs/logging.md)
- [PSR-7/15/17 HTTP messages](./docs/http-psr7.md)
- [PSR-18 HTTP client](./docs/http-client.md)
- [CLI tools for development and maintenance](./docs/commandline.md)
- [File System](./docs/filesystem/file.md)
- [UUID's](./docs/facades/uuid.md)
- [Exception & Error handling](./docs/error-handling.md)


## Installing and Updating

### Installation

Create a new Lucent project using Composer:

```bash
composer create-project blueprintau/lucent myapp
```

Then start the development server:

```bash
cd myapp
vendor/bin/lucent serve
```

By default the server runs on port `8080`. Pass `--port=9000` to use a different port:

```bash
vendor/bin/lucent serve --port=9000
```

Alternatively, add Lucent to an existing project:

```bash
composer require blueprintau/lucent
```

### Updating Lucent

To update Lucent to the latest version:

```bash
composer update blueprintau/lucent
```

## Deploying Your Project

Lucent includes a built-in deployment system that allows you to deploy and rollback your project directly from the CLI. It works with any zip-based source — GitHub, Gitea, S3, or any URL that returns a zip file.

### Configuration

Add the following to your `.env` file:

```env
DEPLOY_URL=https://api.github.com/repos/your-org/your-repo/zipball/master
DEPLOY_TOKEN=your_personal_access_token
```

For GitHub private repositories, generate a Access Token with `repo` scope at https://github.com/settings/tokens, then use the API URL format above with the following headers automatically applied by Lucent:

```
Authorization: Bearer {token}

Accept: application/vnd.github+json

X-GitHub-Api-Version: 2022-11-28
````

### Deploying the Latest Version

```bash
vendor/bin/lucent deploy latest
```

This will:
1. Download the zip from `DEPLOY_URL`
2. Back up your current project to `storage/backups/{timestamp}.zip`
3. Extract the new version over your project

The following paths are never touched during a deploy:
- `.env` — your environment config
- `vendor/` — your Composer dependencies
- `storage/` — your logs, uploads, and temp files

### Rolling Back

```bash
vendor/bin/lucent deploy rollback
```

This will:
1. Clean the current project (preserving `.env`, `vendor/`, `storage/backups`, `storage/temp`, and `logs`)
2. Restore the most recent backup zip
3. Remove the used backup

## Project Structure

After installation, your project will have the following structure:

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
│   └── index.php
├── storage/
├── logs/
├── vendor/
│   └── blueprintau/
│       └── lucent/        # The framework
├── .env
└── composer.json
```

### Configuration

Configure your database connection and other settings in the `.env` file:

```env
DB_USERNAME=root
DB_PASSWORD=
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=lucent
DB_DRIVER=mysql
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

Lucent is open-sourced software licensed under the MIT license.
