<?php

namespace Lucent\Commandline\Components;

/**
 * Command-line progress bar for visualizing task completion
 *
 * This class provides a customizable progress bar for command-line applications
 * to display task progress with features like ETA calculation, custom formatting,
 * and appearance options.
 */
class ProgressBar
{
    /**
     * Total number of steps in the progress
     *
     * @var int
     */
    private int $total;

    /**
     * Current position in the progress
     *
     * @var int
     */
    private int $current = 0;

    /**
     * Width of the progress bar in characters
     *
     * @var int
     */
    private int $barWidth = 50;

    /**
     * Timestamp when the progress bar was started
     *
     * @var float
     */
    private float $startTime;

    /**
     * Format string for the progress bar output
     *
     * Available placeholders:
     * - {bar}: The actual progress bar
     * - {percent}: Percentage complete
     * - {current}: Current step
     * - {total}: Total steps
     * - {elapsed}: Elapsed time
     * - {eta}: Estimated time remaining
     *
     * @var string
     */
    private string $format = '[{bar}] {percent}% ({current}/{total}) - {elapsed} - {eta} remaining';

    /**
     * Characters used for the progress bar
     *
     * [0] => Character for completed portion
     * [1] => Character for incomplete portion
     *
     * @var array
     */
    private array $barCharacters = ['█', '░'];

    /**
     * Timestamp of the last screen update
     *
     * @var float
     */
    private float $lastUpdateTime = 0;

    /**
     * Minimum interval between screen updates in seconds
     *
     * @var float
     */
    private float $updateInterval = 0.1;

    /**
     * Whether to flush output buffer after each update
     *
     * @var bool
     */
    private bool $enableFlush = true;

    /**
     * Initialize the progress bar
     *
     * @param int $total Total number of items to process
     * @param int $barWidth Width of the progress bar in characters
     */
    public function __construct(int $total, int $barWidth = 50)
    {
        $this->total = $total;
        $this->barWidth = $barWidth;
        $this->startTime = microtime(true);
    }

    /**
     * Set custom format for the progress bar
     *
     * Available placeholders:
     * - {bar}: The actual progress bar
     * - {percent}: Percentage complete
     * - {current}: Current step
     * - {total}: Total steps
     * - {elapsed}: Elapsed time
     * - {eta}: Estimated time remaining
     *
     * @param string $format Format string
     * @return self
     */
    public function setFormat(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    /**
     * Set custom characters for the progress bar
     *
     * @param array $chars Array with [complete_char, incomplete_char]
     * @return self
     */
    public function setBarCharacters(array $chars): self
    {
        if (count($chars) === 2) {
            $this->barCharacters = $chars;
        }
        return $this;
    }

    /**
     * Set update interval to avoid too frequent screen updates
     *
     * @param float $seconds Minimum seconds between updates
     * @return self
     */
    public function setUpdateInterval(float $seconds): self
    {
        $this->updateInterval = $seconds;
        return $this;
    }

    /**
     * Enable or disable output buffer flushing
     *
     * @param bool $enable Whether to enable output flushing
     * @return self
     */
    public function enableOutputFlush(bool $enable = true): self
    {
        $this->enableFlush = $enable;
        return $this;
    }

    /**
     * Advance the progress bar by a specific step
     *
     * @param int $step Number of steps to advance
     * @return self
     */
    public function advance(int $step = 1): self
    {
        $this->update($this->current + $step);
        return $this;
    }

    /**
     * Update the progress bar to a specific position
     *
     * @param int $current Current position
     * @return self
     */
    public function update(int $current): self
    {
        $this->current = min(max(0, $current), $this->total);

        $now = microtime(true);
        if (($now - $this->lastUpdateTime) < $this->updateInterval && $this->current < $this->total) {
            return $this;
        }

        $this->lastUpdateTime = $now;
        $this->display();

        return $this;
    }

    /**
     * Finish the progress bar and add a newline
     *
     * @return void
     */
    public function finish(): void
    {
        $this->update($this->total);
        echo PHP_EOL;
    }

    /**
     * Display the progress bar
     *
     * @return void
     */
    protected function display(): void
    {
        // Get terminal width or use a reasonable default
        $termWidth = 80;
        if (function_exists('exec') && false !== @exec('tput cols 2>/dev/null', $output) && !empty($output[0])) {
            $termWidth = (int)$output[0];
        }

        // Create a string to clear the entire line
        $clearLine = "\r" . str_repeat(' ', $termWidth) . "\r";

        // Clear the line before writing new content
        echo $clearLine;

        $percent = $this->current / $this->total;
        $bar = $this->getProgressBar($percent);
        $elapsedTime = $this->getElapsedTime();
        $eta = $this->getEstimatedTimeRemaining($percent, $elapsedTime);

        $output = str_replace(
            ['{bar}', '{percent}', '{current}', '{total}', '{elapsed}', '{eta}'],
            [$bar, round($percent * 100), $this->current, $this->total, $elapsedTime, $eta],
            $this->format
        );

        echo $output;

        // Flush the output buffer to ensure real-time updates (if enabled)
        if ($this->enableFlush) {
            flush();
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
        }
    }

    /**
     * Generate the actual progress bar string
     *
     * @param float $percent Percentage complete (0.0 to 1.0)
     * @return string
     */
    protected function getProgressBar(float $percent): string
    {
        // Ensure percent is within valid range (0.0 to 1.0)
        $percent = max(0.0, min(1.0, $percent));

        $completeBars = (int)floor($percent * $this->barWidth);
        $incompleteBars = max(0, $this->barWidth - $completeBars);

        return str_repeat($this->barCharacters[0], $completeBars) .
            str_repeat($this->barCharacters[1], $incompleteBars);
    }

    /**
     * Format elapsed time
     *
     * @return string
     */
    protected function getElapsedTime(): string
    {
        $elapsed = microtime(true) - $this->startTime;
        return $this->formatTime($elapsed);
    }

    /**
     * Calculate and format estimated time remaining
     *
     * @param float $percent Percentage complete (0.0 to 1.0)
     * @param string $elapsedTime Formatted elapsed time string
     * @return string
     */
    protected function getEstimatedTimeRemaining(float $percent, string $elapsedTime): string
    {
        if ($percent == 0) {
            return 'calculating...';
        }

        $elapsed = microtime(true) - $this->startTime;
        $total = $elapsed / $percent;
        $remaining = $total - $elapsed;

        return $this->formatTime($remaining);
    }

    /**
     * Format time in human-readable format
     *
     * @param float $seconds Time in seconds
     * @return string
     */
    protected function formatTime(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf("%.1fs", $seconds);
        } elseif ($seconds < 3600) {
            $minutes = (int)floor($seconds / 60);
            $remainingSeconds = fmod($seconds, 60.0);
            return sprintf("%dm %.0fs", $minutes, $remainingSeconds);
        } else {
            $hours = (int)floor($seconds / 3600);
            $remainingSeconds = fmod($seconds, 3600.0);
            $minutes = (int)floor($remainingSeconds / 60);
            $finalSeconds = fmod($remainingSeconds, 60.0);

            return sprintf("%dh %02dm %.0fs", $hours, $minutes, $finalSeconds);
        }
    }
}