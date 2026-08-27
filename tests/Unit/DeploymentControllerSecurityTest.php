<?php

namespace Tests\Unit;

use Lucent\Commandline\DeploymentController;
use Lucent\Facades\FileSystem;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZipArchive;

/**
 * Regression tests for zip-slip path traversal in the deployment extract
 * path.
 */
class DeploymentControllerSecurityTest extends TestCase
{
    private string $root;
    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = FileSystem::rootPath();
        $this->zipPath = $this->root . '/storage/temp/test-deploy.zip';

        if (!is_dir(dirname($this->zipPath))) {
            mkdir(dirname($this->zipPath), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->zipPath)) {
            unlink($this->zipPath);
        }
        parent::tearDown();
    }

    /**
     * Invoke the private extract() method via reflection.
     */
    private function extract(string $zipPath, array $exclude): true|string
    {
        $method = new ReflectionMethod(DeploymentController::class, 'extract');
        return $method->invoke(new DeploymentController(), $zipPath, $exclude);
    }

    private function makeZip(array $entries): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    public function test_rejects_traversal_entry(): void
    {
        // A zip entry named ../escape.txt must not be written outside the root.
        $this->makeZip(['../escape.txt' => 'pwned']);

        $result = $this->extract($this->zipPath, []);

        $this->assertIsString($result);
        $this->assertStringContainsString('unsafe path', $result);
        $this->assertFileDoesNotExist(dirname($this->root) . '/escape.txt');
    }

    public function test_rejects_absolute_path_entry(): void
    {
        $this->makeZip(['/etc/evil.php' => 'pwned']);

        $result = $this->extract($this->zipPath, []);

        $this->assertIsString($result);
        $this->assertStringContainsString('unsafe path', $result);
    }

    public function test_extracts_normal_entries_within_root(): void
    {
        $this->makeZip(['public/ok.txt' => 'fine']);

        $result = $this->extract($this->zipPath, []);

        $this->assertTrue($result);
        $this->assertFileExists($this->root . '/public/ok.txt');
        $this->assertSame('fine', file_get_contents($this->root . '/public/ok.txt'));

        // Cleanup
        unlink($this->root . '/public/ok.txt');
        rmdir($this->root . '/public');
    }
}
