<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Enum;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;
use Tests\Support\Stubs\TestBackedEnum;
use Tests\Support\Stubs\TestIntEnum;
use Tests\Support\Stubs\TestPureEnum;

class EnumTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(mixed $value, Enum $constraint): \Lucent\Validation\Result
    {
        return $this->validateValue($value, $constraint);
    }

    // ─── backed (string) enum ──────────────────────────────────────────────

    public function test_backed_string_value_passes_and_normalizes(): void
    {
        $result = $this->validate('active', new Enum(TestBackedEnum::class));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(TestBackedEnum::Active, $result->value(''));
    }

    public function test_backed_string_invalid_value_fails(): void
    {
        $result = $this->validate('unknown', new Enum(TestBackedEnum::class));

        $this->assertTrue($result->hasErrors());
    }

    public function test_backed_enum_instance_passes(): void
    {
        $result = $this->validate(TestBackedEnum::Disabled, new Enum(TestBackedEnum::class));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(TestBackedEnum::Disabled, $result->value(''));
    }

    // ─── backed (int) enum ─────────────────────────────────────────────────

    public function test_backed_int_value_passes_and_normalizes(): void
    {
        $result = $this->validate(2, new Enum(TestIntEnum::class));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(TestIntEnum::Two, $result->value(''));
    }

    public function test_backed_int_invalid_value_fails(): void
    {
        $result = $this->validate(99, new Enum(TestIntEnum::class));

        $this->assertTrue($result->hasErrors());
    }

    // ─── pure (non-backed) enum ────────────────────────────────────────────

    public function test_pure_enum_case_name_passes_and_normalizes(): void
    {
        $result = $this->validate('Bar', new Enum(TestPureEnum::class));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(TestPureEnum::Bar, $result->value(''));
    }

    public function test_pure_enum_instance_passes(): void
    {
        $result = $this->validate(TestPureEnum::Foo, new Enum(TestPureEnum::class));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(TestPureEnum::Foo, $result->value(''));
    }

    public function test_pure_enum_invalid_name_fails(): void
    {
        $result = $this->validate('Nope', new Enum(TestPureEnum::class));

        $this->assertTrue($result->hasErrors());
    }

    // ─── constructor validation ────────────────────────────────────────────

    public function test_constructor_rejects_non_enum_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Enum(\stdClass::class);
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message_lists_allowed_values(): void
    {
        $result = $this->validateField('field', ['field' => 'unknown'], new Enum(TestBackedEnum::class));

        $this->assertSame(
            ['The field must be one of: pending, active, disabled'],
            $result->errors()['field'],
        );
    }
}