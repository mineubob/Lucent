<?php

namespace Tests\Unit;

use Lucent\Database;
use Lucent\Logging\Channel;
use Lucent\Logging\Channels\NullChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Tests\Support\Logging\SpyDriver;

class LoggingTest extends TestCase
{
    private SpyDriver $driver;
    private Channel $channel;

    protected function setUp(): void
    {
        $this->driver = new SpyDriver();
        $this->channel = new Channel('test', $this->driver, false);
    }

    public function test_channel_implements_logger_interface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, $this->channel);
    }

    public function test_null_channel_implements_logger_interface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, new NullChannel());
    }

    public function test_each_level_writes_to_driver(): void
    {
        $levels = [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        ];

        foreach ($levels as $level) {
            $this->channel->{$level}("message {$level}");
        }

        $this->assertCount(8, $this->driver->lines);

        foreach ($levels as $index => $level) {
            $this->assertStringContainsString(
                strtoupper($level),
                $this->driver->lines[$index],
                "Expected line {$index} to contain level {$level}"
            );
            $this->assertStringContainsString(
                "message {$level}",
                $this->driver->lines[$index],
                "Expected line {$index} to contain the message"
            );
        }
    }

    public function test_log_dispatches_to_level_method(): void
    {
        $this->channel->log(LogLevel::INFO, 'dispatched message');

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('INFO', $this->driver->lines[0]);
        $this->assertStringContainsString('dispatched message', $this->driver->lines[0]);
    }

    public function test_log_with_unknown_level_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log level: bogus');

        $this->channel->log('bogus', 'should throw');
    }

    public function test_context_placeholder_interpolation(): void
    {
        $this->channel->info('Hello {name}, you have {count} messages', [
            'name' => 'World',
            'count' => 3,
        ]);

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('Hello World, you have 3 messages', $this->driver->lines[0]);
    }

    public function test_missing_context_key_leaves_placeholder(): void
    {
        $this->channel->info('Hello {name}');

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('Hello {name}', $this->driver->lines[0]);
    }

    public function test_non_stringable_context_value_is_skipped(): void
    {
        $this->channel->info('Value: {value}', [
            'value' => ['nested', 'array'],
        ]);

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('Value: {value}', $this->driver->lines[0]);
    }

    public function test_stringable_message_is_supported(): void
    {
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable message';
            }
        };

        $this->channel->info($message);

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('stringable message', $this->driver->lines[0]);
    }

    public function test_exception_context_is_not_interpolated(): void
    {
        $exception = new \Exception('boom');

        $this->channel->error('Failed: {exception}', ['exception' => $exception]);

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('Failed: {exception}', $this->driver->lines[0]);
    }

    public function test_null_channel_is_noop(): void
    {
        $channel = new NullChannel();

        // Should not throw and should not write anywhere.
        $channel->emergency('test');
        $channel->log(LogLevel::INFO, 'test');
        $this->assertTrue(true);
    }

    public function test_database_set_logger_accepts_psr3_logger(): void
    {
        $logger = new Channel('db-test', $this->driver, false);

        Database::setLogger($logger);
        Database::log(LogLevel::INFO, 'database message {id}', ['id' => 42]);

        $this->assertCount(1, $this->driver->lines);
        $this->assertStringContainsString('database message 42', $this->driver->lines[0]);
    }

    public function test_database_log_with_null_logger_is_noop(): void
    {
        Database::setLogger(new NullChannel());

        // Should not throw.
        Database::log(LogLevel::CRITICAL, 'dropped message');
        $this->assertTrue(true);
    }
}