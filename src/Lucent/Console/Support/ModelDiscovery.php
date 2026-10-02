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
     * @return list<string> Absolute directory paths
     */
    private function psr4Directories(): array
    {
        $dirs = [];

        foreach ($this->psr4Map() as $paths) {
            foreach ($paths as $path) {
                if (is_dir($path)) {
                    $dirs[] = $path;
                }
            }
        }

        return array_values(array_unique($dirs));
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

                    $absolute = FileSystem::absolutePath(rtrim($path, '/\\'));

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
        $class = $this->classFromFile($path);

        if ($class !== null && class_exists($class)) {
            // Already loaded (or autoloadable) — inspect it directly. A
            // declared-classes diff would MISS it: the class was loaded
            // before this call, so it never appears in the diff.
            $this->collectIfModel($class);
            return;
        }

        $classesBefore = get_declared_classes();

        require_once $path;

        // Prefer the derived class name when it now exists — a diff can miss
        // classes that were already loaded by another file's require.
        if ($class !== null && class_exists($class)) {
            $this->collectIfModel($class);
            return;
        }

        foreach (array_diff(get_declared_classes(), $classesBefore) as $declared) {
            $this->collectIfModel($declared);
        }
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
