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
 * which are inspected for `Model` subclasses. A `--dir=` option
 * (comma-separated) overrides the PSR-4 default; explicit dirs are scanned
 * AS GIVEN — including vendor/ paths — so a package that ships models can
 * be synced with `sync --dir=vendor/<package>/Models`.
 *
 * Declared class names are read from each file's tokens (its `namespace`
 * plus the name following every class/interface/trait/enum keyword) —
 * never derived from the file path and never autoloaded — so a file whose
 * namespace does not match its location is still discovered correctly.
 * The include happens only to run the subclass check (parents may live in
 * other files), and only when a declared name is not already loaded:
 * scripts (route files, CLI entrypoints) that declare nothing are never
 * executed, and a file whose class is already declared — e.g. by a
 * duplicate FQCN elsewhere — is never re-included (that fatals with
 * "Cannot redeclare class", which is not catchable).
 *
 * Directory resolution still uses the live Composer ClassLoader's PSR-4
 * prefix map (via {@see ClassLoader::getRegisteredLoaders()}), which is
 * correct when Lucent is a dependency of a consumer project and for
 * multi-dir prefixes.
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
     * thousands of dependency files pointlessly (each include is also a
     * side-effect risk). Explicit --dir paths bypass this filter — the
     * deliberate escape hatch for packages that ship models.
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
     * Resolved lazily on first use; {@see psr4Directories()} reads the
     * snapshot instead of re-querying the ClassLoader.
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

            $this->processFile($fileInfo->getPathname());
        }
    }

    /**
     * Inspect a PHP file and collect its Model subclasses.
     *
     * The declared class-like names are read from tokens first; the file is
     * included only when a declared name is not already loaded, so the
     * subclass check can resolve parents declared in other files.
     *
     * @param string $path Absolute file path
     */
    private function processFile(string $path): void
    {
        $candidates = $this->declaredClassLikes($path);

        // Files that declare nothing are scripts (route files, test-server
        // routers, CLI entrypoints) — they execute side effects when
        // included and must never run during discovery.
        if ($candidates === []) {
            return;
        }

        $declared = [];
        $toLoad = [];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate, false)) {
                $declared[] = $candidate;
            } else {
                $toLoad[] = $candidate;
            }
        }

        foreach ($declared as $candidate) {
            $this->collectIfModel($candidate);
        }

        // A name the file declares that is ALREADY declared means another
        // file owns it (a duplicate FQCN) — including this file would fatal
        // with "Cannot redeclare class", which is not catchable. The already
        // declared names were collected above; skip the include entirely.
        if ($toLoad === [] || $declared !== []) {
            return;
        }

        try {
            require_once $path;
        } catch (\Throwable) {
            // Include-time failure — a reference that does not resolve (a
            // missing parent/interface) or a parse error. Not discoverable
            // as a model; skip the file.
            return;
        }

        foreach ($toLoad as $candidate) {
            if (class_exists($candidate, false)) {
                $this->collectIfModel($candidate);
            }
        }
    }

    /**
     * The class-like names (class / interface / trait / enum) a file
     * declares, fully qualified.
     *
     * A single token pass over the source — no include, no autoloading.
     * The `namespace` statement sets the qualifying prefix; the name
     * following each declaration keyword is collected (an anonymous class
     * has no name and collects nothing). `::class` constant references are
     * skipped — they are preceded by a double-colon. Comments, docblocks
     * and heredoc bodies are separate tokens, so prose mentioning "class"
     * never false-positives.
     *
     * @param string $path Absolute file path
     * @return list<class-string>
     */
    private function declaredClassLikes(string $path): array
    {
        $source = file_get_contents($path);

        if ($source === false || $source === '') {
            return [];
        }

        $names = [];
        $namespace = '';
        $pending = null; // 'namespace' | 'classlike' | null
        $previous = null; // id of the previous significant code token

        foreach (\token_get_all($source) as $token) {
            if (!is_array($token)) {
                // A single-char token can never start a declared name —
                // e.g. the '(' of an anonymous `new class(...)` — so it
                // cancels any pending declaration.
                $pending = null;
                $previous = $token;
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue; // never separates a keyword from its name
            }

            if ($pending !== null) {
                if (
                    $id === T_STRING || $id === T_NAME_QUALIFIED
                    || $id === T_NAME_FULLY_QUALIFIED
                ) {
                    if ($pending === 'namespace') {
                        $namespace = ltrim($text, '\\');
                    } else {
                        $names[] = $namespace === ''
                            ? $text
                            : $namespace . '\\' . $text;
                    }

                    $pending = null;
                    $previous = $id;
                    continue;
                }

                $pending = null; // not a name — cancel and process normally
            }

            if (
                $id === T_CLASS || $id === T_INTERFACE
                || $id === T_TRAIT || $id === T_ENUM
            ) {
                // "::class" is two tokens: T_DOUBLE_COLON then T_CLASS —
                // a constant reference, not a declaration.
                if ($previous !== T_DOUBLE_COLON) {
                    $pending = 'classlike';
                }
            } elseif ($id === T_NAMESPACE) {
                $pending = 'namespace';
            }

            $previous = $id;
        }

        return $names;
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
}
