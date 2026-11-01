<?php

namespace Tests\Unit\Validation\Combinators;

use Lucent\Validation\Combinators\All;
use Lucent\Validation\Combinators\Cases;
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Email;
use Lucent\Validation\Constraints\Enum;
use Lucent\Validation\Constraints\Equals;
use Lucent\Validation\Constraints\Length;
use Lucent\Validation\Constraints\Numeric;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Constraints\Str;
use Lucent\Validation\Normalizers\Trim;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stubs\TestBackedEnum;
use Tests\Support\Stubs\TestIntEnum;

class CasesTest extends TestCase
{
    // ─── self-mode (magic static) ──────────────────────────────────────────

    public function test_self_mode_matches_own_field_value(): void
    {
        // Self-mode composed on the discriminator key via All: the Cases
        // compares the anchor field's own (enum-normalized) value.
        $validator = new Validator(Shape::object([
            'kind' => All::of(
                new Enum(TestBackedEnum::class),
                Cases::is(TestBackedEnum::Active, Shape::object(['card_number' => new Required()]))
                    ->case(TestBackedEnum::Disabled, Shape::object(['iban' => new Required()])),
            ),
        ]));

        $result = $validator->validate(['kind' => 'active', 'card_number' => '4111']);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('4111', $result->value('card_number'));
    }

    public function test_self_mode_missing_branch_constraint_fails(): void
    {
        $validator = new Validator(Shape::object([
            'kind' => All::of(
                new Enum(TestBackedEnum::class),
                Cases::is(TestBackedEnum::Active, Shape::object(['card_number' => new Required()]))
                    ->passOnNoMatch(),
            ),
        ]));

        $result = $validator->validate(['kind' => 'active']);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('card_number', $result->errors());
    }

    public function test_self_mode_sibling_errors_land_at_sibling_level(): void
    {
        $validator = new Validator(Shape::object([
            'type' => All::of(
                new Enum(TestBackedEnum::class),
                Cases::is(TestBackedEnum::Active, Shape::object(['email' => new Required()])),
            ),
        ]));

        $result = $validator->validate(['type' => 'active']);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('email', $result->errors());
        $this->assertArrayNotHasKey('type.email', $result->errors());
    }

    public function test_self_mode_no_match_errors_by_default(): void
    {
        $validator = new Validator(Shape::object([
            'kind' => All::of(
                new Enum(TestBackedEnum::class),
                Cases::is(TestBackedEnum::Active, Shape::object(['card_number' => new Required()])),
            ),
        ]));

        $result = $validator->validate(['kind' => 'disabled']);

        $this->assertSame(
            ['The kind value does not match any case.'],
            $result->errors()['kind'],
        );
    }

    public function test_self_mode_custom_no_match_message(): void
    {
        $validator = new Validator(Shape::object([
            'kind' => All::of(
                new Enum(TestBackedEnum::class),
                Cases::is(TestBackedEnum::Active, Shape::object(['card_number' => new Required()]))
                    ->withMessage('Unknown kind.'),
            ),
        ]));

        $result = $validator->validate(['kind' => 'disabled']);

        $this->assertSame(['Unknown kind.'], $result->errors()['kind']);
    }

    // ─── targeted mode ─────────────────────────────────────────────────────

    public function test_targeted_mode_branch_constrains_the_declaring_field(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['email' => new Required()]))
                ->case(TestBackedEnum::Disabled, Shape::object(['reason' => new Required()])),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);
        $this->assertArrayHasKey('payment.email', $result->errors());
        $this->assertArrayNotHasKey('payment.reason', $result->errors());

        $result = $validator->validate(['type' => 'disabled', 'payment' => []]);
        $this->assertArrayHasKey('payment.reason', $result->errors());
        $this->assertArrayNotHasKey('payment.email', $result->errors());
    }

    public function test_targeted_mode_runs_after_referenced_field_despite_declaration_order(): void
    {
        // The Cases is declared BEFORE the field it references; the scheduler
        // must validate the Enum first so Cases sees the enum instance, not
        // the raw backing string.
        $validator = new Validator(Shape::object([
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['note' => new Required()])),
            'type' => new Enum(TestBackedEnum::class),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertArrayHasKey('payment.note', $result->errors());
    }

    public function test_declared_field_branch_constrains_the_declaring_field(): void
    {
        // A Cases declared on 'card' switches on 'type' but constrains
        // 'card's value — the natural discriminated-union shape.
        $validator = new Validator(Shape::object([
            'type' => new Enum(TestBackedEnum::class),
            'card' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['number' => new Required()])),
        ]));

        // An absent declaring field passes silently (nothing to constrain);
        // a present one fails the branch shape as a whole.
        $result = $validator->validate(['type' => 'active']);
        $this->assertArrayNotHasKey('card', $result->errors());

        $result = $validator->validate(['type' => 'active', 'card' => 'oops']);
        $this->assertArrayHasKey('card', $result->errors());

        // A present-but-incomplete target reports the specific sub-field.
        $result = $validator->validate(['type' => 'active', 'card' => []]);
        $this->assertArrayHasKey('card.number', $result->errors());
    }

    public function test_targeted_mode_passes_when_target_field_absent(): void
    {
        $validator = new Validator(Shape::object([
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['email' => new Required()])),
        ]));

        $result = $validator->validate([]);

        $this->assertFalse($result->hasErrors());
    }

    // ─── skip guard ────────────────────────────────────────────────────────

    public function test_first_match_wins(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['first' => new Required()]))
                ->case(TestBackedEnum::Active, Shape::object(['second' => new Required()])),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertArrayHasKey('payment.first', $result->errors());
        $this->assertArrayNotHasKey('payment.second', $result->errors());
    }

    public function test_case_does_not_mutate_the_receiver(): void
    {
        $base = Cases::on('type')->case(TestBackedEnum::Active, Shape::object(['note' => new Required()]));
        $extended = $base->case(TestBackedEnum::Disabled, Shape::object(['reason' => new Required()]));

        $this->assertNotSame($base, $extended);

        // The base instance must only know the first branch: 'disabled'
        // matches no case on the base instance.
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => $base,
        ]));

        $result = $validator->validate(['type' => 'disabled', 'payment' => []]);

        $this->assertSame(
            ['The type value does not match any case.'],
            $result->errors()['type'],
        );
    }

    public function test_empty_cases_throws_on_validation(): void
    {
        $cases = Cases::on('type');

        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => $cases,
        ]));

        $this->expectException(\LogicException::class);

        $validator->validate(['type' => 'active']);
    }

    // ─── matcher forms ─────────────────────────────────────────────────────

    public function test_scalar_condition(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')->case('active', Shape::object(['note' => new Required()])),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertArrayHasKey('payment.note', $result->errors());
    }

    public function test_list_condition_matches_any_element(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')->case(
                ['pending', 'active'],
                Shape::object(['note' => new Required()]),
            ),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);
        $this->assertArrayHasKey('payment.note', $result->errors());

        $result = $validator->validate(['type' => 'disabled', 'payment' => []]);
        $this->assertSame(
            ['The type value does not match any case.'],
            $result->errors()['type'],
        );
    }

    public function test_enum_case_matches_backing_value_before_enum_constraint(): void
    {
        // No Enum constraint on 'type': the Cases sees the raw backing value,
        // which the enum-case matcher accepts.
        $validator = new Validator(Shape::object([
            'payment' => Cases::on('type')->case(
                TestBackedEnum::Active,
                Shape::object(['note' => new Required()]),
            ),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertArrayHasKey('payment.note', $result->errors());
    }

    public function test_enum_case_matches_normalized_instance(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')->case(
                TestBackedEnum::Active,
                Shape::object(['note' => new Required()]),
            ),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertArrayHasKey('payment.note', $result->errors());
    }

    public function test_int_backed_enum_case_matches_backing_int(): void
    {
        $validator = new Validator(Shape::object([
            'num' => new Enum(TestIntEnum::class),
            'dep' => Cases::on('num')->case(
                TestIntEnum::Two,
                Shape::object(['note' => new Required()]),
            ),
        ]));

        $result = $validator->validate(['num' => 2, 'dep' => []]);

        $this->assertArrayHasKey('dep.note', $result->errors());
    }

    public function test_non_string_discriminators(): void
    {
        // Bool discriminator via targeted mode.
        $validator = new Validator(Shape::object([
            'flagged' => new \Lucent\Validation\Constraints\Boolean(),
            'extra'   => Cases::on('flagged')->case(true, Shape::object(['detail' => new Required()])),
        ]));

        $result = $validator->validate(['flagged' => true, 'extra' => []]);
        $this->assertArrayHasKey('extra.detail', $result->errors());

        // Int discriminator: Numeric normalizes '5' to int 5, matching is: 5.
        $validator = new Validator(Shape::object([
            'count' => new Numeric(),
            'dep'   => Cases::on('count')->case(5, Shape::object(['five' => new Required()])),
        ]));

        $result = $validator->validate(['count' => '5', 'dep' => []]);
        $this->assertArrayHasKey('dep.five', $result->errors());
    }

    public function test_constraint_as_condition(): void
    {
        // All::of(Trim, Email) composes a normalize-and-check condition: the
        // raw ' user@example.com ' is trimmed then validated.
        $validator = new Validator(Shape::object([
            'contact' => All::of(
                new Trim(),
                Cases::is(
                    All::of(new Email()),
                    Shape::object(['newsletter' => new Required()]),
                )->passOnNoMatch(),
            ),
        ]));

        $result = $validator->validate(['contact' => 'user@example.com', 'newsletter' => 'x']);
        $this->assertFalse($result->hasErrors());

        // A non-email contact matches no branch -> passes silently.
        $result = $validator->validate(['contact' => 'not-an-email']);
        $this->assertFalse($result->hasErrors());
    }

    public function test_constraint_condition_does_not_leak_into_result(): void
    {
        $validator = new Validator(Shape::object([
            'kind' => All::of(
                new Trim(),
                Cases::is(
                    All::of(new Equals('card')),
                    Shape::object(['card_number' => new Required()]),
                ),
            ),
        ]));

        $result = $validator->validate(['kind' => ' card ', 'card_number' => '4111']);

        // The declaring field's own Trim normalizer writes back the trimmed
        // value; the condition's isolated evaluation must not record extra
        // errors or further corrupt the stored value.
        $this->assertFalse($result->hasErrors());
        $this->assertSame('card', $result->value('kind'));
    }

    public function test_scheduler_orders_dependents_of_dependents(): void
    {
        // 'b' depends on 'a' and is declared BEFORE it; 'c' depends on 'b'.
        // The reference-ordered scheduler must run a -> b -> c so both
        // dependent conditions see normalized values.
        $validator = new Validator(Shape::object([
            'b' => Cases::on('a')->case(1, new Equals(1)),
            'c' => Cases::on('b')->case(1, new Equals(1)),
            'a' => new Numeric(),
        ]));

        $result = $validator->validate(['a' => '1', 'b' => 1, 'c' => 1]);

        // Both conditions must see normalized values (Numeric turned '1'
        // into 1): any scheduling failure would surface as a no-match error.
        $this->assertFalse($result->hasErrors());
    }

    public function test_numeric_normalization_via_scheduler(): void
    {
        // Numeric normalizes '5' to 5; the scheduler guarantees it ran before
        // the dependent Cases, so the int 5 matcher sees an int.
        $validator = new Validator(Shape::object([
            'count'   => new Numeric(),
            'derived' => Cases::on('count')->case(
                5,
                Shape::object(['extra' => new Required()]),
            ),
        ]));

        $result = $validator->validate(['count' => '5', 'derived' => []]);

        $this->assertArrayHasKey('derived.extra', $result->errors());
    }

    public function test_enum_condition_composed_after_normalizer(): void
    {
        // The Equals constraint composes with the Enum normalizer on the
        // anchor field: normalize-and-check via the constraint form.
        $validator = new Validator(Shape::object([
            'kind' => All::of(
                new Enum(TestBackedEnum::class),
                new Equals(TestBackedEnum::Active),
            ),
            'payment' => Cases::on('kind')
                ->case(TestBackedEnum::Active, Shape::object(['card_number' => new Required()])),
        ]));

        $result = $validator->validate(['kind' => 'active', 'payment' => []]);
        $this->assertArrayHasKey('payment.card_number', $result->errors());

        $result = $validator->validate(['kind' => 'pending']);
        $this->assertArrayHasKey('kind', $result->errors());
        $this->assertArrayNotHasKey('payment', $result->errors());
    }

    public function test_nested_cascade(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')
                ->case(
                    TestBackedEnum::Active,
                    Cases::on('tier')->case(
                        'gold',
                        Shape::object(['perk' => new Required()]),
                    ),
                ),
        ]));

        $result = $validator->validate(['type' => 'active', 'tier' => 'gold', 'payment' => []]);

        $this->assertArrayHasKey('payment.perk', $result->errors());
    }

    public function test_length_still_applies_inside_branch(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')->case(
                TestBackedEnum::Active,
                Shape::object(['email' => All::of(new Required(), new Length(min: 5))]),
            ),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => ['email' => 'ab']]);

        $this->assertArrayHasKey('payment.email', $result->errors());
    }

    public function test_custom_message_does_not_mask_branch_error(): void
    {
        // A matched branch whose sub-shape fails reports only the specific
        // child errors — the custom no-match message must not appear, since
        // a case DID match.
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['cvv' => new Required()]))
                ->withMessage('Custom no-match message.'),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertSame(
            ['The payment.cvv is required.'],
            $result->errors()['payment.cvv'],
        );
        $this->assertArrayNotHasKey('payment', $result->errors());
    }

    public function test_custom_message_applies_on_no_match(): void
    {
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['cvv' => new Required()]))
                ->withMessage('Custom no-match message.'),
        ]));

        $result = $validator->validate(['type' => 'disabled', 'payment' => []]);

        $this->assertSame(
            ['Custom no-match message.'],
            $result->errors()['type'],
        );
    }

    public function test_scalar_sibling_if_else(): void
    {
        // Cases doubles as a declarative if/else for scalar sibling fields.
        $validator = new Validator(Shape::object([
            'mode'    => new Enum(TestBackedEnum::class),
            'threads' => Cases::on('mode')
                ->case(TestBackedEnum::Active, new Numeric())
                ->case(TestBackedEnum::Disabled, new \Lucent\Validation\Constraints\Range(min: 1, max: 4)),
        ]));

        $this->assertFalse(
            $validator->validate(['mode' => 'active', 'threads' => 8])->hasErrors(),
        );
        $this->assertFalse(
            $validator->validate(['mode' => 'disabled', 'threads' => 2])->hasErrors(),
        );

        $result = $validator->validate(['mode' => 'disabled', 'threads' => 8]);
        $this->assertArrayHasKey('threads', $result->errors());
    }

    public function test_type_based_branching_via_constraint_condition(): void
    {
        // A constraint can be the case matcher, enabling branching on a
        // referenced field's TYPE: when 'other' is a string, 'value' must be
        // numeric; when 'other' is an integer, 'value' must be >= 0.
        $validator = new Validator(Shape::object([
            'value' => Cases::on('other')
                ->case(new \Lucent\Validation\Constraints\Str(), new Numeric())
                ->case(new \Lucent\Validation\Constraints\Integer(), new \Lucent\Validation\Constraints\Range(min: 0)),
            'other' => new Required(),
        ]));

        // 'other' is a string -> value must be numeric.
        $this->assertFalse(
            $validator->validate(['value' => 5, 'other' => 'hi'])->hasErrors(),
        );

        // 'other' is an integer -> value must be >= 0.
        $result = $validator->validate(['value' => -1, 'other' => 5]);
        $this->assertArrayHasKey('value', $result->errors());

        $this->assertFalse(
            $validator->validate(['value' => 0, 'other' => 5])->hasErrors(),
        );
    }

    public function test_on_cannot_target_its_own_field(): void
    {
        // Self-branching has exactly one spelling: Cases::is composed on the
        // field. Cases::on($ownField) is a specification mistake.
        $cases = Cases::on('value')->case(1, new \Lucent\Validation\Constraints\Equals(1));

        $validator = new Validator(Shape::object([
            'value' => $cases,
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('cannot target its own field');

        $validator->validate(['value' => 1]);
    }

    public function test_with_message_override_forces_custom_message(): void
    {
        // override: true forces the custom message even when a child already
        // recorded a specific error.
        $validator = new Validator(Shape::object([
            'type'    => new Enum(TestBackedEnum::class),
            'payment' => Cases::on('type')
                ->case(TestBackedEnum::Active, Shape::object(['cvv' => new Required()]))
                ->withMessage('Custom message.', override: true),
        ]));

        $result = $validator->validate(['type' => 'active', 'payment' => []]);

        $this->assertSame(
            ['Custom message.'],
            $result->errors()['payment'],
        );
    }

    public function test_own_mode_type_switch(): void
    {
        // Own-value mode: the field's input type decides which rules apply
        // to the field itself — integers are range-checked, strings are
        // length-capped.
        $validator = new Validator(Shape::object([
            'input' => Cases::own(
                new \Lucent\Validation\Constraints\Integer(),
                new \Lucent\Validation\Constraints\Range(min: 0),
            )
                ->case(new Str(), new Length(max: 3)),
        ]));

        $this->assertFalse($validator->validate(['input' => 12])->hasErrors());
        $this->assertFalse($validator->validate(['input' => 'ab'])->hasErrors());

        $result = $validator->validate(['input' => -1]);
        $this->assertArrayHasKey('input', $result->errors());

        $result = $validator->validate(['input' => 'abcd']);
        $this->assertArrayHasKey('input', $result->errors());
    }

    public function test_own_mode_no_match_errors_at_own_path(): void
    {
        // A value matching no branch (a float with only Str/Integer cases)
        // errors under the field's own path, mirroring sibling mode.
        $validator = new Validator(Shape::object([
            'input' => Cases::own(
                new \Lucent\Validation\Constraints\Integer(),
                new \Lucent\Validation\Constraints\Range(min: 0),
            )
                ->case(new Str(), new Length(max: 3)),
        ]));

        $result = $validator->validate(['input' => 1.5]);

        $this->assertSame(
            ['The input value does not match any case.'],
            $result->errors()['input'],
        );
    }

    public function test_own_mode_passes_on_absent_field(): void
    {
        // An absent field has nothing to switch on; the field's own
        // Required reports the absence.
        $validator = new Validator(Shape::object([
            'input' => Cases::own(
                new \Lucent\Validation\Constraints\Integer(),
                new \Lucent\Validation\Constraints\Range(min: 0),
            )
                ->case(new Str(), new Length(max: 3)),
        ]));

        $this->assertFalse($validator->validate([])->hasErrors());
    }

    public function test_own_mode_composes_with_normalizer(): void
    {
        // Own mode composes with All on the same field: the normalizer runs
        // first, so the matcher sees the normalized value. The Number
        // matcher (a real int/float post-normalize) gates the range check.
        $validator = new Validator(Shape::object([
            'count' => All::of(
                new Numeric(),
                Cases::own(
                    new \Lucent\Validation\Constraints\Number(),
                    new \Lucent\Validation\Constraints\Range(max: 10),
                ),
            ),
        ]));

        $this->assertFalse($validator->validate(['count' => '5'])->hasErrors());

        $result = $validator->validate(['count' => '50']);
        $this->assertArrayHasKey('count', $result->errors());
    }
}
