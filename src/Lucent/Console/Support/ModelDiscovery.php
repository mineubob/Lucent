<?php

declare(strict_types=1);

namespace Lucent\Console\Support;

use Composer\Autoload\ClassLoader;
use BlueprintAU\Radiant\Model;
use Lucent\Facades\FileSystem;
use ReflectionClass;

/**
 * Discovers Radiant model classes in the application.
 *
 * By default the app's Composer PSR-4 directories are scanned for PHP files,
 * which are loaded and inspected for `Model` subclasses. A `--dir=` option
 * (comma-separated) overrides the PSR-4 default.
 *
 * Class names are derived from the file path using the live Composer
 * ClassLoader's PSR-4 prefix map (via {@see ClassLoader::getRegisteredLoaders()}),
 * which is correct even when the scanned directory is a sub-namespace of a
 * prefix or the package is installed as a dependency. When no prefix maps
 * the file, the class is discovered by diffing declared classes around a
 * require.
 *
 * Discovery is app glue — Radiant deliberately does not ship it.
 */
final class ModelDiscovery
{
    /**
     * The Composer PSR-4 prefix map, resolved lazily on first use.
     *
     * @var array<string, list<string>>|null prefix => absolute directory paths
     */
    private array|null $psr4Map = null;

    /**
     * The discovered model classes.
     *
     * @var list<class-string<Model>>
     */
    private array $models = [];

    /**
     * Discover model classes.
     *
     * @param list<string>|null $dirs Explicit directories (absolute or
     *        root-relative). Null scans the app composer.json's PSR-4 dirs.
     *        Explicit dirs are scanned AS GIVEN — including vendor/ paths —
     *        so a package that ships models can be synced with
     *        `sync --dir=vendor/<package>/Models`.
     * @return list<class-string<Model>>
     */
    public function discover(?array $dirs = null): array
    {
        $this->models = [];

        foreach ($this->directories($dirs) as $dir) {
            $this->scanDirectory($dir);
        }

        return array_values(array_unique($this->models));
    }

    /**
     * Resolve the directories to scan.
     *
     * @param list<string>|null $dirs Explicit override
     * @return list<string> Absolute directory paths
     */
    private function directories(?array $dirs): array
    {
        if ($dirs !== null && $dirs !== []) {
            return array_map(
                fn(string $dir): string => FileSystem::absolutePath(trim($dir)),
                $dirs,
            );
        }

        return $this->psr4Directories();
    }

    /**
     * Read the live Composer ClassLoader's PSR-4 directories.
     *
     * Uses the registered loaders rather than parsing composer.json, so the
     * result reflects the actual autoloader state (correct when Lucent is a
     * dependency of a consumer project, and for multi-dir prefixes).
     *
     * Dependency (vendor/) directories are EXCLUDED from the default scan:
     * they never hold the app's models, and a vendor-wide scan would include
     * dependency files whose references may not resolve (e.g. an optional
     * symfony/finder), which fatals at include time and cannot be caught.
     * Explicit --dir paths bypass this filter — the deliberate escape hatch
     * for packages that ship models.
     *
     * @return list<string> Absolute directory paths
     */
    private function psr4Directories(): array
    {
        $dirs = [];

        foreach ($this->psr4Map() as $paths) {
            foreach ($paths as $path) {
                if (is_dir($path) && !$this->isVendorPath($path)) {
                    $dirs[] = $path;
                }
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * Whether the path lives inside a Composer vendor directory.
     *
     * @param string $path Absolute directory path
     */
    private function isVendorPath(string $path): bool
    {
        // The vendor dir is derivable from the registered loaders' keys
        // (ClassLoader::getRegisteredLoaders() is keyed by vendor dir).
        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            $vendorDir = rtrim((string) realpath($vendorDir), '/\\');

            if ($vendorDir !== '' && str_starts_with($path, $vendorDir . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the Composer PSR-4 prefix map once — prefix => absolute dirs.
     *
     * Resolved lazily on first use; both {@see psr4Directories()} and
     * {@see classFromFile()} read the snapshot instead of re-querying the
     * ClassLoader for every file.
     *
     * @return array<string, list<string>>
     */
    private function psr4Map(): array
    {
        if ($this->psr4Map !== null) {
            return $this->psr4Map;
        }

        $map = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $paths) {
                foreach ((array) $paths as $path) {
                    if (!is_string($path) || $path === '') {
                        continue;
                    }

                    // Composer's generated static autoloader stores path
                    // strings with embedded `..` segments (e.g.
                    // "vendor/composer/../../src/Lucent"). Consumers of the
                    // map compare by string prefix, so the segments must be
                    // resolved lexically first — otherwise isVendorPath()
                    // matches the "vendor/" inside the literal string and
                    // filters out the app's own directories too.
                    $absolute = FileSystem::normalizePath(
                        FileSystem::absolutePath(rtrim($path, '/\\')),
                    );

                    if (is_dir($absolute)) {
                        $map[$prefix][] = $absolute;
                    }
                }
            }
        }

        return $this->psr4Map = $map;
    }

    /**
     * Recursively scan a directory for PHP files and collect Model subclasses.
     *
     * @param string $dir Absolute directory path
     */
    private function scanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                continue;
            }

            $this->loadAndInspect($fileInfo->getPathname());
        }
    }

    /**
     * Load a PHP file (when its class is not already autoloadable) and
     * collect any Model subclasses it declares.
     *
     * @param string $path Absolute file path
     */
    private function loadAndInspect(string $path): void
    {
        // Only files that DECLARE a class-like can hold a Model. Scripts
        // (route files, test-server routers, CLI entrypoints) execute
        // side effects when included — echoing output, reading $_SERVER,
        // registering routes — and must never run during discovery.
        if (!$this->declaresClassLike($path)) {
            return;
        }

        $class = $this->classFromFile($path);

        // No autoload: class_exists($class, false) only reports classes that
        // are ALREADY declared. Triggering the autoloader here would include
        // the file through Composer's plain `include` — and a second lookup
        // for a name the file doesn't actually declare (a fixture whose
        // namespace doesn't match its location) would include it AGAIN and
        // fatal with "Cannot redeclare class".
        if ($class !== null && class_exists($class, false)) {
            $this->collectIfModel($class);
            return;
        }

        $classesBefore = get_declared_classes();

        require_once $path;

        // The file may declare a DIFFERENT class than the path derives (a
        // fixture whose namespace doesn't match its location, or a file
        // declaring several classes). Read the declared-classes diff — it
        // covers both that case and a derived class loaded by another file's
        // require. Never re-ask the autoloader for the derived name: if the
        // file didn't declare it, a lookup would include the file a second
        // time (Composer's autoloader uses plain `include`).
        foreach (array_diff(get_declared_classes(), $classesBefore) as $declared) {
            $this->collectIfModel($declared);
        }
    }

    /**
     * Whether the file declares a class, interface, trait or enum.
     *
     * A cheap token scan: stop at the first T_CLASS / T_INTERFACE /
     * T_TRAIT / T_ENUM token that is not a ::class constant reference
     * (those are preceded by a double-colon). Comments and strings are
     * skipped by the tokenizer, so prose mentioning "class" never
     * false-positives.
     *
     * @param string $path Absolute file path
     */
    private function declaresClassLike(string $path): bool
    {
        $source = file_get_contents($path);

        if ($source === false || $source === '') {
            return false;
        }

        $previousCode = null;

        foreach (\token_get_all($source) as $token) {
            if (!is_array($token)) {
                $previousCode = $token; // single-char (e.g. ':', ';')
                continue;
            }

            [$id, $text] = $token;

            if (
                $id === T_CLASS || $id === T_INTERFACE
                || $id === T_TRAIT || $id === T_ENUM
            ) {
                // "::class" is two tokens: T_DOUBLE_COLON then T_CLASS —
                // skip it so a file that only REFERENCES a class still
                // counts as script-only.
                if ($previousCode === ':' || $previousCode === T_DOUBLE_COLON) {
                    $previousCode = $id;
                    continue;
                }

                return true;
            }

            if ($text !== '' && trim($text) !== '') {
                $previousCode = $id;
            }
        }

        return false;
    }

    /**
     * Collect a class when it is a concrete Model subclass.
     *
     * @param class-string $class
     */
    private function collectIfModel(string $class): void
    {
        if (is_subclass_of($class, Model::class) && !(new ReflectionClass($class))->isAbstract()) {
            $this->models[] = $class;
        }
    }

    /**
     * Derive a class name from a file path.
     *
     * Primary: the Composer ClassLoader's PSR-4 prefix map — a file under a
     * PSR-4 directory maps to `<prefix><relative-path-as-namespace>`. This
     * is correct even when the scanned directory is a sub-namespace of the
     * prefix (e.g. scanning `app/Models` under the `App\` → `app/` mapping).
     *
     * Fallback: root-relative path derivation — covers apps whose autoloader
     * is not a Composer ClassLoader (e.g. a custom spl_autoload_register
     * closure mapping the app namespace to the root).
     *
     * @param string $path Absolute file path
     * @return string|null The derived class name, or null when not derivable
     */
    private function classFromFile(string $path): ?string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return null;
        }

        foreach ($this->psr4Map() as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                $dir = rtrim((string) realpath($dir), '/\\');

                if ($dir === '' || !str_starts_with($realPath, $dir . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $relative = substr($realPath, strlen($dir) + 1, -4); // strip dir + ".php"

                return $prefix . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            }
        }

        // Fallback: root-relative derivation.
        $root = rtrim(FileSystem::rootPath(), '/\\');

        if (str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)) {
            $relative = substr($realPath, strlen($root) + 1, -4);

            return str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        return null;
    }
}
