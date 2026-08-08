<?php

namespace Tests\Feature;

use Lucent\Commandline\ClearCacheCommand;
use Lucent\Facades\Cache;
use Lucent\Facades\CommandLine;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\CapturesCommandOutput;
use Tests\Support\Concerns\RefreshApplication;

class ClearCacheCommandTest extends TestCase
{
    use CapturesCommandOutput;
    use RefreshApplication;

    protected function setUp(): void
    {
        parent::setUp();
        self::refreshApplication();
        $this->captureCommandOutput();
    }

    public function test_command_is_registered(): void
    {
        $this->assertSame('cache:clear', ClearCacheCommand::$command);
    }

    public function test_cache_clear_command_clears_the_cache(): void
    {
        Cache::set('key', 'value');
        $this->assertTrue(Cache::has('key'));

        CommandLine::register(ClearCacheCommand::$command, 'clear', ClearCacheCommand::class);
        $result = CommandLine::execute('cache:clear');

        $this->assertStringContainsString('Cache cleared successfully', $result);
        $this->assertFalse(Cache::has('key'));
    }
}