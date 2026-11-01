<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Combinators\All;
use Lucent\Validation\Constraints\Equals;
use Lucent\Validation\Constraints\Enum;
use Lucent\Validation\Constraints\In;
use Lucent\Validation\Constraints\Integer;
use Lucent\Validation\Constraints\ProhibitedIf;
use Lucent\Validation\Constraints\RequiredIf;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stubs\TestBackedEnum;
use Tests\Support\Stubs\TestIntEnum;

class ConditionalConstraintsTest extends TestCase
{
    // ─── RequiredIf ────────────────────────────────────────────────────────

    public function test_required_if_fails_when_referenced_matches_and_field_missing(): void
    {
        $validator = new Validator([
            'type'        => new Equals('card'),
            'card_number' => new RequiredIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'card']);

        $this->assertArrayHasKey('card_number', $result->errors());
        $this->assertSame(
            ['The card_number is required when type matches.'],
            $result->errors()['card_number'],
        );
    }

    public function test_required_if_passes_when_referenced_matches_and_field_present(): void
    {
        $validator = new Validator([
            'type'        => new Equals('card'),
            'card_number' => new RequiredIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'card', 'card_number' => '4111']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_required_if_passes_when_referenced_does_not_match(): void
    {
        $validator = new Validator([
            'type'        => new Equals('bank'),
            'card_number' => new RequiredIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'bank']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_required_if_empty_string_counts_as_missing(): void
    {
        $validator = new Validator([
            'type'        => new Equals('card'),
            'card_number' => new RequiredIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'card', 'card_number' => '']);

        $this->assertArrayHasKey('card_number', $result->errors());
    }

    public function test_required_if_enum_case_condition(): void
    {
        $validator = new Validator([
            'type'        => new Enum(TestBackedEnum::class),
            'card_number' => new RequiredIf('type', is: TestBackedEnum::Active),
        ]);

        // Raw backing value matches via triple-match even though the enum
        // normalizes it.
        $result = $validator->validate(['type' => 'active']);

        $this->assertArrayHasKey('card_number', $result->errors());
    }

    public function test_required_if_declared_before_referenced_field_still_sees_normalized_value(): void
    {
        // The scheduler validates 'num' (Numeric) first despite declaration
        // order, so the condition sees int 5, not the raw '5'. Integer
        // validates without normalizing, so Numeric is the right normalizer.
        $validator = new Validator([
            'count' => new RequiredIf('num', is: 5),
            'num'   => new \Lucent\Validation\Constraints\Numeric(),
        ]);

        $result = $validator->validate(['num' => '5']);

        $this->assertArrayHasKey('count', $result->errors());
    }

    public function test_required_if_skips_when_referenced_field_failed(): void
    {
        $validator = new Validator([
            'type'        => new Enum(TestBackedEnum::class),
            'card_number' => new RequiredIf('type', is: TestBackedEnum::Active),
        ]);

        $result = $validator->validate(['type' => 'bogus']);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('type', $result->errors());
        $this->assertArrayNotHasKey('card_number', $result->errors());
    }

    public function test_required_if_custom_message(): void
    {
        $constraint = new RequiredIf('type', is: 'card');
        $constraint->withMessage('Card number is mandatory.');

        $validator = new Validator([
            'type'        => new Equals('card'),
            'card_number' => $constraint,
        ]);

        $result = $validator->validate(['type' => 'card']);

        $this->assertSame(['Card number is mandatory.'], $result->errors()['card_number']);
    }

    // ─── ProhibitedIf ──────────────────────────────────────────────────────

    public function test_prohibited_if_fails_when_referenced_matches_and_field_present(): void
    {
        $validator = new Validator([
            'type' => new Equals('card'),
            'iban' => new ProhibitedIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'card', 'iban' => 'DE89']);

        $this->assertArrayHasKey('iban', $result->errors());
        $this->assertSame(
            ['The iban must not be present when type matches.'],
            $result->errors()['iban'],
        );
    }

    public function test_prohibited_if_passes_when_referenced_matches_and_field_absent(): void
    {
        $validator = new Validator([
            'type' => new Equals('card'),
            'iban' => new ProhibitedIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'card']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_prohibited_if_passes_when_referenced_does_not_match(): void
    {
        $validator = new Validator([
            'type' => new Equals('bank'),
            'iban' => new ProhibitedIf('type', is: 'card'),
        ]);

        $result = $validator->validate(['type' => 'bank', 'iban' => 'DE89']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_prohibited_if_skips_when_referenced_field_failed(): void
    {
        $validator = new Validator([
            'type' => new Enum(TestBackedEnum::class),
            'iban' => new ProhibitedIf('type', is: TestBackedEnum::Active),
        ]);

        $result = $validator->validate(['type' => 'bogus', 'iban' => 'DE89']);

        $this->assertArrayHasKey('type', $result->errors());
        $this->assertArrayNotHasKey('iban', $result->errors());
    }

    // ─── Equals ────────────────────────────────────────────────────────────

    public function test_equals_passes_on_strict_match(): void
    {
        $validator = new Validator(['status' => new Equals('active')]);

        $result = $validator->validate(['status' => 'active']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_equals_fails_on_type_mismatch(): void
    {
        $validator = new Validator(['count' => new Equals(5)]);

        $result = $validator->validate(['count' => '5']);

        $this->assertSame(
            ["The count must equal 5."],
            $result->errors()['count'],
        );
    }

    public function test_equals_enum_case_matches_backing_value(): void
    {
        $validator = new Validator(['type' => new Equals(TestBackedEnum::Active)]);

        $result = $validator->validate(['type' => 'active']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_equals_enum_case_matches_instance(): void
    {
        $validator = new Validator(['type' => new Equals(TestBackedEnum::Active)]);

        $result = $validator->validate(['type' => TestBackedEnum::Active]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_equals_rejects_arrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Equals(['a', 'b']);
    }

    public function test_equals_composes_inside_all(): void
    {
        $validator = new Validator(All::of(
            new \Lucent\Validation\Constraints\Enum(TestBackedEnum::class),
            new Equals(TestBackedEnum::Active),
        ));

        $result = $validator->validate('active');
        $this->assertFalse($result->hasErrors());

        $result = $validator->validate('pending');
        $this->assertTrue($result->hasErrors());
    }

    // ─── In ────────────────────────────────────────────────────────────────

    public function test_in_passes_on_any_candidate(): void
    {
        $validator = new Validator(['scheme' => new In(['visa', 'mc'])]);

        $this->assertFalse($validator->validate(['scheme' => 'visa'])->hasErrors());
        $this->assertFalse($validator->validate(['scheme' => 'mc'])->hasErrors());
    }

    public function test_in_fails_on_unlisted_value(): void
    {
        $validator = new Validator(['scheme' => new In(['visa', 'mc'])]);

        $result = $validator->validate(['scheme' => 'amex']);

        $this->assertSame(
            ['The scheme must be one of: \'visa\', \'mc\'.'],
            $result->errors()['scheme'],
        );
    }

    public function test_in_accepts_enum_cases(): void
    {
        $validator = new Validator([
            'type' => new In([TestBackedEnum::Pending, TestBackedEnum::Active]),
        ]);

        $this->assertFalse($validator->validate(['type' => 'pending'])->hasErrors());
        $this->assertFalse($validator->validate(['type' => 'active'])->hasErrors());
        $this->assertTrue($validator->validate(['type' => 'disabled'])->hasErrors());
    }

    public function test_in_accepts_int_enum_cases(): void
    {
        $validator = new Validator(['num' => new In([TestIntEnum::One, TestIntEnum::Three])]);

        $this->assertFalse($validator->validate(['num' => 1])->hasErrors());
        $this->assertFalse($validator->validate(['num' => 3])->hasErrors());
        $this->assertTrue($validator->validate(['num' => 2])->hasErrors());
    }

    public function test_in_rejects_empty_candidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new In([]);
    }
}
