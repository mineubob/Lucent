<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Facades\UUID;
use Lucent\Validation\Constraints\Uuid as UuidConstraint;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class UuidTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, UuidConstraint $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('uuid', $body, $constraint);
    }

    private const V4 = '550e8400-e29b-41d4-a716-446655440000';
    private const V3 = '6ba7b810-9dad-31d1-80b4-00c04fd430c8';
    private const V5 = '886313e1-3b8a-5372-9b90-0c9aee199e5d';
    private const NIL = '00000000-0000-0000-0000-000000000000';

    // ─── valid UUIDs ───────────────────────────────────────────────────────

    public function test_valid_v4_passes(): void
    {
        $result = $this->validate(['uuid' => self::V4], new UuidConstraint());

        $this->assertFalse($result->hasErrors());
    }

    public function test_valid_v5_passes(): void
    {
        $result = $this->validate(['uuid' => self::V5], new UuidConstraint());

        $this->assertFalse($result->hasErrors());
    }

    public function test_uppercase_uuid_passes(): void
    {
        $result = $this->validate(['uuid' => strtoupper(self::V4)], new UuidConstraint());

        $this->assertFalse($result->hasErrors());
    }

    // ─── invalid UUIDs ─────────────────────────────────────────────────────

    public function test_invalid_uuid_fails(): void
    {
        foreach (['not-a-uuid', '550e8400-e29b-41d4-a716', '550e8400e29b41d4a716446655440000'] as $value) {
            $result = $this->validate(['uuid' => $value], new UuidConstraint());
            $this->assertTrue($result->hasErrors(), "Expected fail for $value");
        }
    }

    // ─── version restriction ───────────────────────────────────────────────

    public function test_version_restriction_accepts_matching_version(): void
    {
        $result = $this->validate(['uuid' => self::V4], new UuidConstraint(4));

        $this->assertFalse($result->hasErrors());
    }

    public function test_version_restriction_rejects_other_version(): void
    {
        $result = $this->validate(['uuid' => self::V3], new UuidConstraint(4));

        $this->assertTrue($result->hasErrors());
    }

    public function test_v5_with_version_5_passes(): void
    {
        $result = $this->validate(['uuid' => self::V5], new UuidConstraint(5));

        $this->assertFalse($result->hasErrors());
    }

    // ─── nil UUID special case ─────────────────────────────────────────────

    public function test_nil_uuid_valid_without_version(): void
    {
        $result = $this->validate(['uuid' => self::NIL], new UuidConstraint());

        $this->assertFalse($result->hasErrors());
    }

    public function test_nil_uuid_invalid_with_version(): void
    {
        $result = $this->validate(['uuid' => self::NIL], new UuidConstraint(4));

        $this->assertTrue($result->hasErrors());
    }

    // ─── braces ────────────────────────────────────────────────────────────

    public function test_braced_uuid_fails(): void
    {
        $result = $this->validate(['uuid' => '{' . self::V4 . '}'], new UuidConstraint());

        $this->assertTrue($result->hasErrors());
    }

    /**
     * BUG-REVEALING TEST: UUID::isValid() does not range-check the $version
     * argument. Passing version=9 interpolates '9' into the regex, which would
     * accept a non-standard version-9 UUID. This test asserts a v9 UUID is
     * rejected, which will FAIL because the facade accepts it.
     */
    public function test_non_standard_version_9_rejected(): void
    {
        $v9 = '550e8400-e29b-91d4-a716-446655440000';
        $result = $this->validate(['uuid' => $v9], new UuidConstraint(9));

        $this->assertTrue($result->hasErrors());
    }

    // ─── edge cases ────────────────────────────────────────────────────────

    public function test_empty_string_fails(): void
    {
        $result = $this->validate(['uuid' => ''], new UuidConstraint());

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_string_types_fail(): void
    {
        foreach ([123, [self::V4], null, true] as $value) {
            $result = $this->validate(['uuid' => $value], new UuidConstraint());
            $this->assertTrue($result->hasErrors(), "Expected fail for " . var_export($value, true));
        }
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validate(['uuid' => 'not-a-uuid'], new UuidConstraint());

        $this->assertSame(
            ['The uuid must be a valid UUID.'],
            $result->errors()['uuid'],
        );
    }
}