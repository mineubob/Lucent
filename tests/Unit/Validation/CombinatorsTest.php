<?php

namespace Tests\Unit\Validation;

use Lucent\Validation\Combinators\All;
use Lucent\Validation\Combinators\Any;
use Lucent\Validation\Combinators\Each;
use Lucent\Validation\Combinators\Optional;
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Email;
use Lucent\Validation\Constraints\Length;
use Lucent\Validation\Constraints\Numeric;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\FieldContext;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stubs\CountingConstraint;
use Tests\Support\Stubs\PassingConstraint;
use Tests\Support\Concerns\BuildsValidationRequests;

class CombinatorsTest extends TestCase
{
    use BuildsValidationRequests;

    // ─── All ───────────────────────────────────────────────────────────────

    public function test_all_passes_when_all_constraints_pass(): void
    {
        $validator = new Validator([
            'value' => All::of(new Required(), new Length(min: 2)),
        ]);

        $result = $validator->validate(['value' => 'ab']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_all_stops_at_first_failure(): void
    {
        $first = new CountingConstraint(false);
        $second = new CountingConstraint(true);

        $validator = new Validator(['value' => All::of($first, $second)]);
        $validator->validate(['value' => 'x']);

        $this->assertSame(1, $first->calls);
        $this->assertSame(0, $second->calls);
    }

    public function test_all_uses_first_failing_constraint_message(): void
    {
        $validator = new Validator([
            'value' => All::of(new Required(), new Email()),
        ]);

        $result = $validator->validate(['value' => '']);

        $this->assertSame(['The value is required.'], $result->errors()['value']);
    }

    public function test_all_empty_passes(): void
    {
        $validator = new Validator(['value' => All::of()]);

        $result = $validator->validate(['value' => 'anything']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_all_message_returns_null_when_no_constraint_failed(): void
    {
        $all = All::of(new Required());
        $ctx = new FieldContext('value', 'x', true, new \Lucent\Validation\Result(), null, null);

        // All passes, so no constraint failed -> defaultMessage returns null.
        $this->assertTrue($all->validate($ctx));

        $this->assertNull($all->message($ctx));
    }

    // ─── Any ───────────────────────────────────────────────────────────────

    public function test_any_single_passing_alternative_passes(): void
    {
        $validator = new Validator([
            'value' => Any::of(new Length(min: 2)),
        ]);

        $result = $validator->validate(['value' => 'ab']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_any_single_failing_alternative_fails(): void
    {
        $validator = new Validator([
            'value' => Any::of(new Length(min: 5)),
        ]);

        $result = $validator->validate(['value' => 'ab']);

        $this->assertTrue($result->hasErrors());
    }

    public function test_any_first_pass_skips_subsequent_alternatives(): void
    {
        $first = new CountingConstraint(true);
        $second = new CountingConstraint(false);

        $validator = new Validator(['value' => Any::of($first, $second)]);
        $validator->validate(['value' => 'x']);

        $this->assertSame(1, $first->calls);
        $this->assertSame(0, $second->calls);
    }

    public function test_any_middle_pass_rolls_back_prior_errors(): void
    {
        $validator = new Validator([
            'value' => Any::of(
                new Length(min: 10),
                new Length(min: 2),
                new Length(min: 20),
            ),
        ]);

        $result = $validator->validate(['value' => 'ab']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_any_all_fail_records_all_alternative_messages(): void
    {
        $validator = new Validator([
            'value' => Any::of(new Length(min: 5), new Length(min: 10)),
        ]);

        $result = $validator->validate(['value' => 'x']);

        $this->assertTrue($result->hasErrors());
        $this->assertCount(2, $result->errors()['value']);
    }

    public function test_any_adds_no_own_message(): void
    {
        $validator = new Validator([
            'value' => Any::of(new Length(min: 5)),
        ]);

        $result = $validator->validate(['value' => 'x']);

        // Only the alternative's message is present.
        $this->assertSame(
            ['The value field must be at least 5 characters long.'],
            $result->errors()['value'],
        );
    }

    public function test_any_empty_fails_with_no_error(): void
    {
        $validator = new Validator(['value' => Any::of()]);

        $result = $validator->validate(['value' => 'x']);

        // Any::of() with zero constraints returns false from validate(), but
        // records no error (defaultMessage is null), so hasErrors() is false.
        $this->assertFalse($result->hasErrors());
        $this->assertArrayNotHasKey('value', $result->errors());
    }

    public function test_any_normalized_values_from_failed_alternative_persist(): void
    {
        // A failed alternative that normalizes a value leaves it in the result
        // (Any only snapshots/restores errors, not values).
        $validator = new Validator([
            'value' => Any::of(
                new Numeric(),
                new Length(min: 10),
            ),
        ]);

        $result = $validator->validate(['value' => 'abc']);

        $this->assertTrue($result->hasErrors());
        // Numeric failed but the value was not normalized (abc is not numeric).
        $this->assertSame('abc', $result->value('value'));
    }

    public function test_any_discards_failed_alternative_errors_on_success(): void
    {
        // First alternative (a Shape) fails and records user.name; the second
        // (a plain string) passes. Any must discard the failed branch's errors.
        $validator = new Validator([
            'value' => Any::of(
                Shape::object(['name' => new Required()]),
                new Length(min: 2),
            ),
        ]);

        $result = $validator->validate(['value' => 'ok']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_any_reports_error_when_all_alternatives_fail(): void
    {
        $validator = new Validator([
            'value' => Any::of(
                Shape::object(['name' => new Required()]),
                new Length(min: 2),
            ),
        ]);

        $result = $validator->validate(['value' => 'x']);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('value', $result->errors());
    }

    public function test_any_keeps_errors_from_prior_fields(): void
    {
        // A failing field before the Any must not be rolled back by Any.
        $validator = new Validator([
            'name'  => new Required(),
            'value' => Any::of(new Length(min: 2), new Length(min: 5)),
        ]);

        $result = $validator->validate([
            'name'  => '',
            'value' => 'ok',
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('name', $result->errors());
        $this->assertArrayNotHasKey('value', $result->errors());
    }

    public function test_any_records_all_failed_alternative_messages(): void
    {
        $validator = new Validator([
            'value' => Any::of(new Length(min: 5), new Length(min: 10)),
        ]);

        $result = $validator->validate(['value' => 'x']);

        $this->assertTrue($result->hasErrors());
        $this->assertCount(2, $result->errors()['value']);
    }

    // ─── Optional ──────────────────────────────────────────────────────────

    public function test_optional_absent_passes(): void
    {
        $validator = new Validator(['name' => new Optional(new Required())]);

        $result = $validator->validate([]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_optional_null_passes(): void
    {
        $validator = new Validator(['name' => new Optional(new Required())]);

        $result = $validator->validate(['name' => null]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_optional_empty_string_passes(): void
    {
        $validator = new Validator(['name' => new Optional(new Required())]);

        $result = $validator->validate(['name' => '']);

        $this->assertFalse($result->hasErrors());
    }

    public function test_optional_empty_array_passes(): void
    {
        $validator = new Validator(['name' => new Optional(new Required())]);

        $result = $validator->validate(['name' => []]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_optional_falsy_values_delegate_to_inner(): void
    {
        foreach ([0, '0', false, '0.0'] as $value) {
            $validator = new Validator(['name' => new Optional(new Required())]);
            $result = $validator->validate(['name' => $value]);
            $this->assertFalse($result->hasErrors(), "Expected pass for " . var_export($value, true));
        }
    }

    /**
     * BUG-REVEALING TEST: The Optional docstring says empty values are
     * "normalized to null" before the inner constraint is applied, but the
     * validate() method just returns true without calling normalize(). This
     * test asserts the documented behavior and will FAIL.
     */
    public function test_optional_present_empty_normalizes_to_null(): void
    {
        $validator = new Validator(['name' => new Optional(new Required())]);

        $result = $validator->validate(['name' => '']);

        $this->assertFalse($result->hasErrors());
        $this->assertNull($result->value('name'));
    }

    public function test_optional_inner_failure_records_inner_message(): void
    {
        // A non-empty value delegates to the inner constraint. Use Length so
        // the inner constraint can fail on a non-empty value.
        $validator = new Validator(['name' => new Optional(new Length(min: 5))]);

        $result = $validator->validate(['name' => 'ab']);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(
            ['The name field must be at least 5 characters long.'],
            $result->errors()['name'],
        );
    }

    public function test_optional_email_invalid_fails_with_email_message(): void
    {
        $validator = new Validator(['email' => new Optional(new Email())]);

        $result = $validator->validate(['email' => 'not-an-email']);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(
            ['The email must be a valid email address.'],
            $result->errors()['email'],
        );
    }

    // ─── Shape ─────────────────────────────────────────────────────────────

    public function test_shape_tuple_rejects_object(): void
    {
        $validator = new Validator([
            'pair' => Shape::tuple(new Numeric(), new Length(min: 2)),
        ]);

        $result = $validator->validate(['pair' => (object) ['a', 'b']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('pair', $result->errors());
    }

    /**
     * A tuple is a positional list. An associative array is a map/object and
     * is rejected, so no "Undefined array key" warning is triggered.
     */
    public function test_shape_tuple_with_associative_keys(): void
    {
        $validator = new Validator([
            'pair' => Shape::tuple(new Numeric(), new Length(min: 2)),
        ]);

        $result = $validator->validate(['pair' => ['a' => '42', 'b' => 'ab']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('pair', $result->errors());
    }

    public function test_shape_tuple_missing_index_fails(): void
    {
        $validator = new Validator([
            'pair' => Shape::tuple(new Numeric(), new Length(min: 2)),
        ]);

        $result = $validator->validate(['pair' => ['42']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('pair', $result->errors());
    }

    public function test_shape_tuple_null_element_fails(): void
    {
        $validator = new Validator([
            'pair' => Shape::tuple(new Required(), new Required()),
        ]);

        $result = $validator->validate(['pair' => [null, 'x']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('pair.0', $result->errors());
    }

    public function test_shape_tuple_exact_error_message(): void
    {
        $validator = new Validator([
            'pair' => Shape::tuple(new Numeric()),
        ]);

        $result = $validator->validate(['pair' => ['1', '2']]);

        $this->assertSame(
            ['The pair must be an array with exactly 1 elements.'],
            $result->errors()['pair'],
        );
    }

    public function test_shape_object_rejects_non_array_with_exact_message(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate(['user' => 'not-an-object']);

        $this->assertSame(['The user must be an object.'], $result->errors()['user']);
    }

    public function test_nested_shape_namespaces_errors(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'name'  => new Required(),
                'email' => new Email(),
            ]),
        ]);

        $result = $validator->validate([
            'user' => [
                'name'  => '',
                'email' => 'bad',
            ],
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('user.name', $result->errors());
        $this->assertArrayHasKey('user.email', $result->errors());
    }

    public function test_nested_shape_stores_nested_values(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'name'  => new Required(),
                'email' => new Email(),
            ]),
        ]);

        $result = $validator->validate([
            'user' => [
                'name'  => 'Ada',
                'email' => 'ada@example.com',
            ],
        ]);

        $this->assertSame('Ada', $result->value('user.name'));
        $this->assertSame('ada@example.com', $result->value('user.email'));
        $this->assertSame(
            ['name' => 'Ada', 'email' => 'ada@example.com'],
            $result->value('user'),
        );
    }

    public function test_shape_object_accepts_object_value(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate([
            'user' => (object) ['name' => 'Ada'],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('user.name'));
    }

    public function test_shape_fails_when_value_is_not_an_array(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate(['user' => 'not-an-object']);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('user', $result->errors());
    }

    public function test_shape_object_excludes_extra_keys(): void
    {
        // Shape seeds the parent with only the declared sub-fields, so
        // undeclared keys from the raw input are excluded from the result.
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate(['user' => ['name' => 'Ada', 'extra' => 'ignored']]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(['name' => 'Ada'], $result->value('user'));
    }

    public function test_nested_shape_excludes_extra_keys(): void
    {
        // A nested Shape must also drop undeclared keys from its own level.
        $validator = new Validator([
            'user' => Shape::object([
                'address' => Shape::object(['city' => new Required()]),
            ]),
        ]);

        $result = $validator->validate([
            'user' => ['address' => ['city' => 'Sydney', 'extra' => 'ignored']],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(['city' => 'Sydney'], $result->value('user.address'));
    }

    public function test_each_of_shape_excludes_extra_keys(): void
    {
        // Each element validated by a Shape must drop undeclared keys.
        $validator = new Validator([
            'users' => new Each(Shape::object(['name' => new Required()])),
        ]);

        $result = $validator->validate([
            'users' => [
                ['name' => 'Ada', 'extra' => 'ignored'],
                ['name' => 'Grace'],
            ],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(['name' => 'Ada'], $result->value('users.0'));
        $this->assertSame(['name' => 'Grace'], $result->value('users.1'));
    }

    public function test_custom_constraint_over_container_keeps_value(): void
    {
        // A non-normalizing custom constraint over a container field must not
        // lose the value: the container is declared by ensureContainer and its
        // children seeded, even though the constraint itself stores nothing.
        $validator = new Validator([
            'user' => Shape::object(['tags' => new Each(new PassingConstraint())]),
        ]);

        $result = $validator->validate([
            'user' => ['tags' => ['a', 'b']],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasValue('user.tags'));
        $this->assertSame(['a', 'b'], $result->value('user.tags'));
    }

    public function test_each_custom_constraint_over_shape_keeps_elements(): void
    {
        // Each over a Shape with a non-normalizing inner element constraint
        // must keep the element container and its declared children.
        $validator = new Validator([
            'users' => new Each(Shape::object(['name' => new PassingConstraint()])),
        ]);

        $result = $validator->validate([
            'users' => [['name' => 'Ada']],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasValue('users.0'));
        $this->assertSame(['name' => 'Ada'], $result->value('users.0'));
    }

    public function test_shape_object_excludes_extra_object_props(): void
    {
        // For object input, the inner Shape re-seeds the parent with only the
        // declared sub-fields, so extra (undeclared) props are excluded.
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate([
            'user' => (object) ['name' => 'Ada', 'extra' => 'ignored'],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(['name' => 'Ada'], $result->value('user'));
    }

    public function test_shape_object_with_array_property(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['tags' => new Each(new Numeric())]),
        ]);

        $result = $validator->validate([
            'user' => (object) ['tags' => ['1', '2']],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame([1, 2], $result->value('user.tags'));
    }

    public function test_shape_object_nested_object(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'address' => Shape::object(['city' => new Required()]),
            ]),
        ]);

        $result = $validator->validate([
            'user' => (object) ['address' => (object) ['city' => 'Sydney']],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Sydney', $result->value('user.address.city'));
    }

    public function test_shape_object_absent_optional_subfield(): void
    {
        $validator = new Validator([
            'user' => Shape::object([
                'name' => new Required(),
                'nickname' => new Optional(new Required()),
            ]),
        ]);

        $result = $validator->validate(['user' => ['name' => 'Ada']]);

        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->hasValue('user.nickname'));
    }

    public function test_shape_empty_object_passes_on_any_array(): void
    {
        $validator = new Validator(['user' => Shape::object([])]);

        $result = $validator->validate(['user' => ['anything' => 'x']]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_shape_empty_tuple_passes_on_empty_array(): void
    {
        $validator = new Validator(['pair' => Shape::tuple()]);

        $result = $validator->validate(['pair' => []]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_shape_object_numeric_string_keys(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['0' => new Numeric()]),
        ]);

        $result = $validator->validate(['user' => ['0' => '42']]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(42, $result->value('user.0'));
    }

    public function test_shape_object_null_subfield_value_fails(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate(['user' => ['name' => null]]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('user.name', $result->errors());
    }

    public function test_shape_child_failure_suppresses_generic_error(): void
    {
        $validator = new Validator([
            'user' => Shape::object(['name' => new Required()]),
        ]);

        $result = $validator->validate(['user' => ['name' => '']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('user.name', $result->errors());
        $this->assertArrayNotHasKey('user', $result->errors());
    }

    // ─── Each ──────────────────────────────────────────────────────────────

    public function test_each_empty_array_passes_and_seeds(): void
    {
        $validator = new Validator(['items' => new Each(new Numeric())]);

        $result = $validator->validate(['items' => []]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $result->value('items'));
    }

    public function test_each_seeds_parent_with_raw_array(): void
    {
        $validator = new Validator(['items' => new Each(new Numeric())]);

        $result = $validator->validate(['items' => ['1', '2']]);

        $this->assertSame([1, 2], $result->value('items'));
    }

    public function test_each_seeds_elements_when_inner_does_not_normalize(): void
    {
        // Regression test: Each must seed each element's raw value even when
        // the inner constraint only validates and does not normalize, so the
        // elements are not lost from the result.
        $validator = new Validator(['items' => new Each(new PassingConstraint())]);

        $result = $validator->validate(['items' => ['ab', 'cd']]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(['ab', 'cd'], $result->value('items'));
    }

    public function test_each_associative_keys_preserved_in_paths(): void
    {
        $validator = new Validator(['items' => new Each(new Numeric())]);

        $result = $validator->validate(['items' => ['a' => '1', 'b' => '2']]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame(1, $result->value('items.a'));
        $this->assertSame(2, $result->value('items.b'));
    }

    public function test_each_non_array_element_fails_with_namespaced_error(): void
    {
        $validator = new Validator([
            'items' => new Each(Shape::object(['name' => new Required()])),
        ]);

        $result = $validator->validate(['items' => ['not-an-array']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('items.0', $result->errors());
    }

    public function test_each_of_shape_validates_array_of_objects(): void
    {
        $validator = new Validator([
            'users' => new Each(Shape::object([
                'name' => new Required(),
            ])),
        ]);

        $result = $validator->validate([
            'users' => [
                ['name' => 'Ada'],
                ['name' => 'Grace'],
            ],
        ]);

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('users.0.name'));
        $this->assertSame('Grace', $result->value('users.1.name'));
    }

    public function test_each_of_shape_namespaces_errors(): void
    {
        $validator = new Validator([
            'users' => new Each(Shape::object([
                'name' => new Required(),
            ])),
        ]);

        $result = $validator->validate([
            'users' => [
                ['name' => 'Ada'],
                ['name' => ''],
            ],
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('users.1.name', $result->errors());
    }

    public function test_each_null_element_fails(): void
    {
        $validator = new Validator(['items' => new Each(new Required())]);

        $result = $validator->validate(['items' => ['x', null, 'y']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('items.1', $result->errors());
    }

    public function test_each_child_failure_suppresses_generic_error(): void
    {
        $validator = new Validator(['items' => new Each(new Numeric())]);

        $result = $validator->validate(['items' => ['1', 'abc']]);

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('items.1', $result->errors());
        $this->assertArrayNotHasKey('items', $result->errors());
    }

    public function test_each_max_items_passes_within_bound(): void
    {
        $validator = new Validator(['items' => new Each(new Numeric(), maxItems: 3)]);

        $result = $validator->validate(['items' => ['1', '2']]);

        $this->assertFalse($result->hasErrors());
    }

    public function test_each_max_items_fails_when_exceeded(): void
    {
        $validator = new Validator(['items' => new Each(new Numeric(), maxItems: 2)]);

        $result = $validator->validate(['items' => ['1', '2', '3']]);

        $this->assertTrue($result->hasErrors());
        $this->assertSame(
            ['The items must contain at most 2 items.'],
            $result->errors()['items'],
        );
    }

    // ─── shared-instance reuse (AUD-CNC-001) ──────────────────────────────

    public function test_shape_reused_across_validations_does_not_leak_state(): void
    {
        // Regression test for AUD-CNC-001: per-validation failure state is
        // stored on the FieldContext, not the constraint instance, so reusing
        // the same Shape across validations must not bleed state between them.
        $shape = Shape::object(['name' => new Required()]);
        $validator = new Validator(['user' => $shape]);

        // First validation: child fails -> generic error suppressed.
        $first = $validator->validate(['user' => ['name' => '']]);
        $this->assertTrue($first->hasErrors());
        $this->assertArrayHasKey('user.name', $first->errors());
        $this->assertArrayNotHasKey('user', $first->errors());

        // Second validation: value is not an object -> generic error present.
        $second = $validator->validate(['user' => 'not-an-object']);
        $this->assertTrue($second->hasErrors());
        $this->assertArrayHasKey('user', $second->errors());
    }

    public function test_each_reused_across_validations_does_not_leak_state(): void
    {
        $each = new Each(new Numeric());
        $validator = new Validator(['items' => $each]);

        // First validation: element fails -> generic error suppressed.
        $first = $validator->validate(['items' => ['1', 'abc']]);
        $this->assertTrue($first->hasErrors());
        $this->assertArrayHasKey('items.1', $first->errors());
        $this->assertArrayNotHasKey('items', $first->errors());

        // Second validation: value is not an array -> generic error present.
        $second = $validator->validate(['items' => 'not-an-array']);
        $this->assertTrue($second->hasErrors());
        $this->assertArrayHasKey('items', $second->errors());
    }

    public function test_all_reused_across_validations_does_not_leak_state(): void
    {
        $all = All::of(new Required(), new Email());
        $validator = new Validator(['value' => $all]);

        // First validation: Required fails -> its message is used.
        $first = $validator->validate(['value' => '']);
        $this->assertSame(['The value is required.'], $first->errors()['value']);

        // Second validation: Required passes, Email fails -> Email's message.
        $second = $validator->validate(['value' => 'not-an-email']);
        $this->assertSame(['The value must be a valid email address.'], $second->errors()['value']);
    }
}