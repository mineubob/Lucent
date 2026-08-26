<?php

namespace Tests\Support;

use Lucent\Filesystem\File;

/**
 * Copies fixture files from tests/Fixtures/ into the disposable test
 * working directory (temp_install/).
 *
 * Fixtures are authored as real files under tests/Fixtures/ (so they get
 * syntax highlighting and IDE support) but the framework under test reads
 * them from the temp root — e.g. the migration command parses model files
 * from disk, and the App\ PSR-4 autoloader maps to temp_install/App/.
 *
 * Each copy method returns a File instance so callers can assert
 * ->exists() exactly as they did with the old heredoc generators.
 */
class FixtureLoader
{
    /**
     * Absolute path to the fixtures directory.
     */
    private static function fixturesDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR;
    }

    /**
     * Copy a fixture file into the temp root and return a File for it.
     *
     * Always overwrites the destination so the copied fixture exactly matches
     * the source in tests/Fixtures/ (a stale copy from a previous run or a
     * test that modified the file must not be reused). Throws if the source
     * is missing or the write fails, so callers can rely on the returned
     * File existing without asserting it themselves.
     *
     * @param string $section  e.g. 'Models', 'Controllers', 'Routes'
     * @param string $relative Relative path within the section, e.g. 'TestUser.php'
     * @param string $targetRoot Temp-root subdirectory the section maps to,
     *                           e.g. 'App/Models' or 'routes'.
     * @return File
     * @throws \RuntimeException If the source is missing or the write fails.
     */
    public static function copy(string $section, string $relative, string $targetRoot): File
    {
        $source = self::fixturesDir() . $section . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($source)) {
            throw new \RuntimeException("Fixture not found: {$source}");
        }

        $target = '/' . $targetRoot . '/' . $relative;
        $file = new File($target);

        // Ensure the destination directory exists (write() does not create it).
        $directory = dirname($file->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (!$file->write(file_get_contents($source))) {
            throw new \RuntimeException("Failed to write fixture to: {$file->path}");
        }

        return $file;
    }

    /**
     * Copy a model fixture into temp_install/App/Models/.
     *
     * @param string $name Filename, e.g. 'TestUser.php'
     * @return File
     */
    public static function copyModel(string $name): File
    {
        return self::copy('Models', $name, 'App/Models');
    }

    /**
     * Copy a controller fixture into temp_install/App/Controllers/.
     *
     * @param string $name Filename, e.g. 'RouteGroupTestingController.php'
     * @return File
     */
    public static function copyController(string $name): File
    {
        return self::copy('Controllers', $name, 'App/Controllers');
    }

    /**
     * Copy a command fixture into temp_install/App/Commands/.
     *
     * @param string $name Filename, e.g. 'TestCommand.php'
     * @return File
     */
    public static function copyCommand(string $name): File
    {
        return self::copy('Commands', $name, 'App/Commands');
    }

    /**
     * Copy a middleware fixture into temp_install/App/Middleware/.
     *
     * @param string $name Filename, e.g. 'AuthMiddleware.php'
     * @return File
     */
    public static function copyMiddleware(string $name): File
    {
        return self::copy('Middleware', $name, 'App/Middleware');
    }

    /**
     * Copy a service fixture into temp_install/App/Services/.
     *
     * @param string $name Filename, e.g. 'InjectionGreeter.php'
     * @return File
     */
    public static function copyService(string $name): File
    {
        return self::copy('Services', $name, 'App/Services');
    }

    /**
     * Copy a route fixture into temp_install/routes/.
     *
     * @param string $name Filename, e.g. 'web.php'
     * @return File
     */
    public static function copyRoutes(string $name): File
    {
        return self::copy('Routes', $name, 'routes');
    }

    /**
     * Copy a view fixture into temp_install/App/Views/.
     *
     * @param string $name Filename, e.g. '404.html'
     * @return File
     */
    public static function copyView(string $name): File
    {
        return self::copy('Views', $name, 'App/Views');
    }

    /**
     * Copy the CLI entrypoint template into temp_install/cli.
     *
     * The template contains a REPO_ROOT placeholder that is replaced with the
     * absolute path to the repository root (so the spawned CLI process can
     * require the test bootstrap). The file is written to the temp root (no
     * App/ prefix, no .php extension).
     *
     * @return File
     */
    public static function copyCliTemplate(): File
    {
        $source = self::fixturesDir() . 'Cli' . DIRECTORY_SEPARATOR . 'cli.template';
        if (!is_file($source)) {
            throw new \RuntimeException("CLI template not found: {$source}");
        }

        $repoRoot = dirname(__DIR__, 2);

        $content = str_replace('REPO_ROOT', $repoRoot, file_get_contents($source));

        $file = new File('/cli');
        if (!$file->write($content)) {
            throw new \RuntimeException("Failed to write CLI template to: {$file->path}");
        }

        return $file;
    }
}