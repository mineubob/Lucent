<?php

namespace Unit;

use Lucent\Commandline\UpdateController;
use Phar;
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase
{

    public function test_update_install(): void
    {
        $updater = new UpdateController();
        $buildDir = EXTERNAL_ROOT."/packages/";
        $pharPath = $buildDir.'lucent.phar';

        echo $updater->install();

        $fileContents = file_get_contents($pharPath);

        preg_match('/s:7:"version";s:\d+:"([^"]+)"/', $fileContents, $matches);

        if (!empty($matches[1])) {
            $version = $matches[1];
            self::assertFalse(
                str_contains($version, 'local'),
                "Version still contains 'local': " . $version
            );
        } else {
            $this->fail("Could not extract version from Phar file");
        }
    }
    public function test_update_rollback(): void
    {
        $updater = new UpdateController();
        $buildDir = dirname(__DIR__,2).'/temp_install/packages/';

        echo $updater->rollback();

        $new_version = new Phar($buildDir.'lucent.phar');
        $new_version = $new_version->getMetadata()["version"];

        self::assertTrue(str_contains($new_version, 'local'));
    }

}