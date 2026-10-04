<?php

namespace Tests\Feature;

use App\Models\SluggedModel;
use App\Models\TestUser;
use App\Models\TestUserTwo;
use BlueprintAU\Radiant\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\TestCase;
use Lucent\Console\Support\ModelDiscovery;

class ModelDiscoveryTest extends TestCase
{
    use CopiesFixtures;
    use DatabaseTesting;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Model' => ['TestUser.php', 'TestUserTwo.php', 'SluggedModel.php'],
        ]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_discover_scans_explicit_dir($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $discovery = new ModelDiscovery();
        $models = $discovery->discover([TEMP_ROOT . 'App/Models']);

        $this->assertContains(TestUser::class, $models);
        $this->assertContains(TestUserTwo::class, $models);
        $this->assertContains(SluggedModel::class, $models);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_discover_returns_fqcn_strings($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $models = (new ModelDiscovery())->discover([TEMP_ROOT . 'App/Models']);

        $this->assertNotEmpty($models);

        foreach ($models as $model) {
            $this->assertIsString($model);
            $this->assertTrue(class_exists($model));
            $this->assertTrue(is_subclass_of($model, Model::class));
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_discover_deduplicates($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        // The same dir twice must not produce duplicate entries.
        $models = (new ModelDiscovery())->discover([
            TEMP_ROOT . 'App/Models',
            TEMP_ROOT . 'App/Models',
        ]);

        $this->assertCount(count(array_unique($models)), $models);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_discover_with_nonexistent_dir_returns_empty($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $models = (new ModelDiscovery())->discover([TEMP_ROOT . 'Does/Not/Exist']);

        $this->assertSame([], $models);
    }

    /**
     * The default PSR-4 scan must survive Composer's `..`-laden path strings.
     *
     * Composer's static autoloader stores PSR-4 dirs as strings like
     * "vendor/composer/../../src/Lucent". The vendor filter compares paths
     * by string prefix, so the `..` segments must be resolved lexically —
     * previously the filter matched "vendor/" inside the literal string and
     * filtered out EVERY directory, app dirs included, leaving the default
     * scan (no --dir) empty.
     */
    public function test_default_psr4_dirs_exclude_vendor_but_keep_app_dirs(): void
    {
        $method = (new \ReflectionClass(ModelDiscovery::class))->getMethod('psr4Directories');

        $dirs = $method->invoke(new ModelDiscovery());

        $this->assertNotEmpty(
            $dirs,
            'Default scan dirs must not be empty — `..` segments in Composer path strings must not swallow app dirs.',
        );

        $vendorDir = realpath(dirname(__DIR__, 2) . '/vendor');
        $this->assertNotFalse($vendorDir);

        foreach ($dirs as $dir) {
            $this->assertStringNotContainsString('..', $dir);
            $this->assertStringStartsNotWith($vendorDir . DIRECTORY_SEPARATOR, $dir);
        }

        // The framework's own source dir (Lucent\ → src/Lucent) must survive
        // the filter; realpath() both sides so symlinked checkouts compare equal.
        $this->assertContains(
            realpath(dirname(__DIR__, 2) . '/src/Lucent'),
            array_map(realpath(...), $dirs),
        );
    }
}
