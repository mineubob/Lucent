<?php

namespace Tests\Unit\Validation\Constraints;

use Carbon\Carbon;
use Lucent\Validation\Constraints\Date;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class DateTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, Date $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('date', $body, $constraint);
    }

    // ─── valid dates ───────────────────────────────────────────────────────

    public function test_valid_date_passes(): void
    {
        $result = $this->validate(['date' => '2026-01-15'], new Date());

        $this->assertFalse($result->hasErrors());
    }

    public function test_valid_date_normalizes_to_carbon(): void
    {
        $result = $this->validate(['date' => '2026-01-15'], new Date());

        $this->assertInstanceOf(Carbon::class, $result->value('date'));
        $this->assertSame('2026-01-15', $result->value('date')->format('Y-m-d'));
    }

    public function test_custom_format_passes(): void
    {
        $result = $this->validate(['date' => '15/01/2026'], new Date('d/m/Y'));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('15/01/2026', $result->value('date')->format('d/m/Y'));
    }

    public function test_datetime_format_passes(): void
    {
        $result = $this->validate(['date' => '2026-01-15 14:30:00'], new Date('Y-m-d H:i:s'));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('2026-01-15 14:30:00', $result->value('date')->format('Y-m-d H:i:s'));
    }

    // ─── invalid dates ─────────────────────────────────────────────────────

    /**
     * BUG-REVEALING TEST: Date with a completely invalid string. Carbon's
     * createFromFormat() throws InvalidFormatException instead of returning
     * false, and the Date constraint does not catch it, so validation throws
     * rather than recording an error.
     */
    public function test_invalid_date_fails(): void
    {
        $result = $this->validate(['date' => 'not-a-date'], new Date());

        $this->assertTrue($result->hasErrors());
    }

    public function test_rollover_date_rejected(): void
    {
        // 2023-13-31 would roll over to 2024-01-31.
        $result = $this->validate(['date' => '2023-13-31'], new Date());

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_strict_input_rejected(): void
    {
        // 2023-1-1 is not strict Y-m-d (missing leading zeros).
        $result = $this->validate(['date' => '2023-1-1'], new Date());

        $this->assertTrue($result->hasErrors());
    }

    /**
     * BUG-REVEALING TEST: Date with an empty string. Carbon's createFromFormat()
     * throws InvalidFormatException, which the Date constraint does not catch.
     */
    public function test_empty_string_fails(): void
    {
        $result = $this->validate(['date' => ''], new Date());

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_string_types_fail(): void
    {
        foreach ([1234567890, ['2026-01-15'], null, Carbon::parse('2026-01-15')] as $value) {
            $result = $this->validate(['date' => $value], new Date());
            $this->assertTrue($result->hasErrors(), "Expected failure for value: " . var_export($value, true));
        }
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message_contains_format(): void
    {
        // Use a rollover date (2023-13-31 -> 2024-01-31) which fails the
        // round-trip check without throwing, so the error message is recorded.
        $result = $this->validate(['date' => '2023-13-31'], new Date('Y-m-d'));

        $this->assertSame(
            ['The date must be a valid date in Y-m-d format.'],
            $result->errors()['date'],
        );
    }
}