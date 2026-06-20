# Lucent PHP Framework

[![PHP Tests](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/tests.yml/badge.svg?branch=development)](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/tests.yml)
[![Build and Release](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/main.yml/badge.svg)](https://github.com/jackharrispeninsulainteractive/Lucent/actions/workflows/main.yml)
[![Code Coverage](https://img.shields.io/badge/Coverage-Report-brightgreen)](https://blueprintau.github.io/Lucent/)
<a href="https://downgit.github.io/#/home?url=https://github.com/jackharrispeninsulainteractive/Lucent/raw/main/installer.php" target="_blank">
<img src="https://img.shields.io/badge/Download-Installer-blue" alt="Download Installer">
</a>

Lucent is a lightweight PHP framework designed for building APIs with minimal overhead. It offers an elegant, intuitive syntax that will feel familiar to developers with experience in Laravel or Spring Boot.

Lucent provides a streamlined approach to building PHP APIs with:

- Simple routing with REST
- [Database abstraction with support for MySQL and SQLite](./docs/database.md)
- [Model-based ORM with relationships](./docs/orm.md)
- [Route Model Binding](./docs/route-model-binding.md)
- [Rules & Validation](./docs/rules-and-validation.md)
- Middleware support
- Comprehensive logging
- [CLI tools for development and maintenance](./docs/commandline.md)
- [File System](./docs/filesystem/file.md)
- [UUID's](./docs/facades/uuid.md)
- [Exception & Error handling](./docs/error-handling.md)


## Installing and Updating

### Installation

You can install Lucent using our installer script:

1. Download the installer:
   ```bash
   wget https://github.com/blueprintau/Lucent/raw/main/installer.php
   ```

2. Run the installer:
   ```bash
   php installer.php
   ```

3. Start the development server:
   ```bash
   php cli serve
   ```
You can pass an optional port to use by adding --port=9000 to the end.

### Updating Lucent

To update Lucent to the latest version:

```bash
php cli update install
```

If you need to rollback to a previous version:

```bash
php cli update rollback
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
php cli deploy latest
```

This will:
1. Download the zip from `DEPLOY_URL`
2. Back up your current project to `storage/backups/{timestamp}.zip`
3. Extract the new version over your project

The following paths are never touched during a deploy:
- `.env` — your environment config
- `packages/lucent.phar` — your framework
- `storage/` — your logs, uploads, and temp files

### Rolling Back

```bash
php cli deploy rollback
```

This will:
1. Clean the current project (preserving `.env`, `packages/lucent.phar`, `storage/backups`, `storage/temp`, and `logs`)
2. Restore the most recent backup zip
3. Remove the used backup

## PHP Composer Support

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


## Project Structure

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
