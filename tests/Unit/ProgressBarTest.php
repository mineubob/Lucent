<?php

namespace Unit;

use Lucent\Commandline\Components\ProgressBar;
use PHPUnit\Framework\TestCase;

class ProgressBarTest extends TestCase
{
    /**
     * @var ProgressBar
     */
    private $progressBar;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a new progress bar for each test with a standard total of 100
        $this->progressBar = new ProgressBar(100);
        $this->progressBar->enableOutputFlush(false); // Disable output buffer flushing for tests
    }


    /**
     * Test custom bar format
     */
    public function testCustomBarFormat(): void
    {
        // Set custom format
        $customFormat = 'Progress: {bar} {percent}% - {current}/{total}';
        $this->progressBar->setFormat($customFormat);

        // Use PHPUnit's built-in output testing
        $this->expectOutputRegex('/Progress:.*25%.*25\/100/');

        // Update progress
        $this->progressBar->update(25);
    }

    /**
     * Test custom bar characters
     */
    public function testCustomBarCharacters(): void
    {
        // Set custom characters
        $this->progressBar->setBarCharacters(['#', '-']);

        // Use PHPUnit's built-in output testing
        $this->expectOutputRegex('/[#-]+.*50%/');

        // Update to 50%
        $this->progressBar->update(50);
    }

    /**
     * Test time formatting for different durations
     */
    public function testTimeFormatting(): void
    {
        // Create reflection to access private method
        $reflection = new \ReflectionClass($this->progressBar);
        $formatTimeMethod = $reflection->getMethod('formatTime');
        $formatTimeMethod->setAccessible(true);

        // Test seconds formatting
        $secondsOutput = $formatTimeMethod->invoke($this->progressBar, 45.5);
        $this->assertEquals('45.5s', $secondsOutput);

        // Test minutes formatting
        $minutesOutput = $formatTimeMethod->invoke($this->progressBar, 125.3);
        $this->assertEquals('2m 5s', $minutesOutput);

        // Test hours formatting
        $hoursOutput = $formatTimeMethod->invoke($this->progressBar, 7325.7);
        $this->assertEquals('2h 02m 6s', $hoursOutput);
    }

    /**
     * Test estimating remaining time
     */
    public function testEstimatedTimeRemaining(): void
    {
        // Create reflection to access private properties and methods
        $reflection = new \ReflectionClass($this->progressBar);

        // Set start time to a fixed value for testing
        $startTimeProperty = $reflection->getProperty('startTime');
        $startTimeProperty->setAccessible(true);
        $startTimeProperty->setValue($this->progressBar, microtime(true) - 10); // Pretend we started 10 seconds ago

        // Access the getEstimatedTimeRemaining method
        $estimateMethod = $reflection->getMethod('getEstimatedTimeRemaining');
        $estimateMethod->setAccessible(true);

        // Access the getElapsedTime method
        $elapsedMethod = $reflection->getMethod('getElapsedTime');
        $elapsedMethod->setAccessible(true);
        $elapsedTime = $elapsedMethod->invoke($this->progressBar);

        // Test with 25% progress
        $remainingTime = $estimateMethod->invoke($this->progressBar, 0.25, $elapsedTime);

        // Basic verification that it returns a string and not 'calculating...'
        $this->assertIsString($remainingTime);
        $this->assertNotEquals('calculating...', $remainingTime);
    }

    /**
     * Test that progress bar handles completion correctly
     */
    public function testProgressBarCompletion(): void
    {
        // Use PHPUnit's built-in output testing
        $this->expectOutputRegex('/\[.*\].*100%.*100\/100/');

        // Jump straight to 100%
        $this->progressBar->update(100);

        // Check internal state with reflection
        $reflection = new \ReflectionClass($this->progressBar);
        $currentProperty = $reflection->getProperty('current');
        $currentProperty->setAccessible(true);

        $this->assertEquals(100, $currentProperty->getValue($this->progressBar));
    }

    /**
     * Test that update interval prevents too frequent updates
     */
    public function testUpdateInterval(): void
    {
        // Set a longer update interval
        $this->progressBar->setUpdateInterval(1.0);

        // Use reflection to get the lastUpdateTime
        $reflection = new \ReflectionClass($this->progressBar);
        $lastUpdateProperty = $reflection->getProperty('lastUpdateTime');
        $lastUpdateProperty->setAccessible(true);

        // We expect output only from the first update
        $this->expectOutputRegex('/25%/');

        // Update and record the time
        $this->progressBar->update(25);
        $firstUpdateTime = $lastUpdateProperty->getValue($this->progressBar);

        // Update again immediately - should be ignored due to interval
        $this->progressBar->update(30);
        $secondUpdateTime = $lastUpdateProperty->getValue($this->progressBar);

        // The times should be the same if the second update was ignored
        $this->assertEquals($firstUpdateTime, $secondUpdateTime);
    }

    /**
     * Test that progress bar handles out of bounds values correctly
     */
    public function testOutOfBoundsValues(): void
    {
        // Test with a negative value should show as 0%
        $this->expectOutputRegex('/0%/');

        // Try updating with a negative value (should be clamped to 0)
        $this->progressBar->update(-10);

        // Use reflection to check the current value
        $reflection = new \ReflectionClass($this->progressBar);
        $currentProperty = $reflection->getProperty('current');
        $currentProperty->setAccessible(true);

        // Should be set to 0
        $this->assertEquals(0, $currentProperty->getValue($this->progressBar));
    }

    /**
     * Test that progress bar handles over-maximum values correctly
     */
    public function testOverMaximumValues(): void
    {
        // Test with a value over maximum should show as 100%
        $this->expectOutputRegex('/100%/');

        // Try updating with a value greater than total
        $this->progressBar->update(150);

        // Use reflection to check the current value
        $reflection = new \ReflectionClass($this->progressBar);
        $currentProperty = $reflection->getProperty('current');
        $currentProperty->setAccessible(true);

        // Should be capped at total (100)
        $this->assertEquals(100, $currentProperty->getValue($this->progressBar));
    }

    /**
     * Test progress bar rendering with edge cases
     */
    public function testProgressBarEdgeCases(): void
    {
        // Create reflection to access protected method
        $reflection = new \ReflectionClass($this->progressBar);
        $getProgressBarMethod = $reflection->getMethod('getProgressBar');
        $getProgressBarMethod->setAccessible(true);

        // Test with normal percentage
        $normalBar = $getProgressBarMethod->invoke($this->progressBar, 0.5);
        $this->assertIsString($normalBar);
        $this->assertGreaterThan(0, strlen($normalBar));

        // Test with negative percentage (should be clamped to 0)
        $negativeBar = $getProgressBarMethod->invoke($this->progressBar, -0.1);
        $this->assertIsString($negativeBar);
        $this->assertGreaterThan(0, strlen($negativeBar));

        // Test with percentage > 1 (should be clamped to 1)
        $overBar = $getProgressBarMethod->invoke($this->progressBar, 1.5);
        $this->assertIsString($overBar);
        $this->assertGreaterThan(0, strlen($overBar));
    }
}