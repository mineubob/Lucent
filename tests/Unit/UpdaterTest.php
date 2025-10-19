<?php

namespace Unit;

use Exception;
use Lucent\Commandline\UpdateController;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Phar;
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase
{

    public function test_update_install(): void
    {
        $updater = new UpdateController();
        $buildDir = FileSystem::rootPath() . "/packages/";
        $pharPath = $buildDir . 'lucent.phar';

        // Install update
        $output = $updater->install();
        $this->assertStringContainsString('Successfully updated', $output);

        try {
            $phar = new Phar($pharPath);
            $metadata = $phar->getMetadata();

            if (isset($metadata['version'])) {
                self::assertFalse(
                    str_contains($metadata['version'], 'local'),
                    "Version still contains 'local': " . $metadata['version']
                );
                return;
            }
        } catch (Exception $e) {
            // Fall back to regex pattern if Phar metadata access fails
        }

        // Fall back to original pattern if needed
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
        $pharPath = RUNNING_LOCATION . 'packages' . DIRECTORY_SEPARATOR . 'lucent.phar';

        // Get original file hash
        $originalHash = md5_file($pharPath);

        $updater = new UpdateController();

        // Install update
        $output = $updater->install();
        $this->assertStringContainsString('Successfully updated', $output);

        // Rollback
        $output = $updater->rollback();
        $this->assertStringContainsString('Rolled back successfully', $output);

        // Verify file was restored
        clearstatcache(true);
        $rolledBackHash = md5_file($pharPath);
        $this->assertEquals($originalHash, $rolledBackHash, "File should be restored after rollback");
    }

    public function test_update_check(): void
    {
        $updater = new UpdateController();

        $output = $updater->check();
        $this->assertStringStartsWith("Running update dependency check:", $output);
        echo $output;
    }

}