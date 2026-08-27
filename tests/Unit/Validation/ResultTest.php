<?php

namespace Tests\Unit\Validation;

use Lucent\Validation\Result;
use PHPUnit\Framework\TestCase;

class ResultTest extends TestCase
{
    // ─── addError ──────────────────────────────────────────────────────────

    public function test_add_error_accumulates_multiple_messages_for_same_field(): void
    {
        $result = new Result();
        $result->addError('name', 'first');
        $result->addError('name', 'second');

        $this->assertSame(['first', 'second'], $result->errors()['name']);
    }

    public function test_add_error_records_multiple_fields(): void
    {
        $result = new Result();
        $result->addError('name', 'name error');
        $result->addError('email', 'email error');

        $this->assertSame(['name error'], $result->errors()['name']);
        $this->assertSame(['email error'], $result->errors()['email']);
    }

    // ─── set ───────────────────────────────────────────────────────────────

    public function test_set_single_segment_path(): void
    {
        $result = new Result();
        $result->set('name', 'Ada');

        $this->assertSame('Ada', $result->value('name'));
    }

    public function test_set_two_segment_path_creates_nested_array(): void
    {
        $result = new Result();
        $result->set('user.name', 'Ada');

        $this->assertSame('Ada', $result->value('user.name'));
        $this->assertSame(['name' => 'Ada'], $result->value('user'));
    }

    public function test_set_three_segment_path_creates_deep_nesting(): void
    {
        $result = new Result();
        $result->set('a.b.c', 'deep');

        $this->assertSame('deep', $result->value('a.b.c'));
        $this->assertSame(['b' => ['c' => 'deep']], $result->value('a'));
    }

    public function test_set_sibling_paths_coexist(): void
    {
        $result = new Result();
        $result->set('a.b', 1);
        $result->set('a.c', 2);

        $this->assertSame(1, $result->value('a.b'));
        $this->assertSame(2, $result->value('a.c'));
        $this->assertSame(['b' => 1, 'c' => 2], $result->value('a'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $result = new Result();
        $result->set('name', 'Ada');
        $result->set('name', 'Grace');

        $this->assertSame('Grace', $result->value('name'));
    }

    public function test_set_empty_path_replaces_entire_values(): void
    {
        $result = new Result();
        $result->set('name', 'Ada');
        $result->set('', ['replacement' => true]);

        $this->assertSame(['replacement' => true], $result->values());
    }

    public function test_set_with_hyphen_in_path_segment(): void
    {
        $result = new Result();
        $result->set('user.email-address', 'ada@example.com');

        $this->assertSame('ada@example.com', $result->value('user.email-address'));
    }

    public function test_set_overwrites_final_segment_value(): void
    {
        // seedRaw then normalize both write to the same path; the final
        // segment is overwritten, not treated as a conflict.
        $result = new Result();
        $result->set('value', '5');
        $result->set('value', 5);

        $this->assertSame(5, $result->value('value'));
    }

    public function test_set_throws_when_intermediate_segment_is_non_array(): void
    {
        // Regression test for AUD-COR-005: writing through a non-array
        // intermediate would silently destroy it, so it throws instead.
        $result = new Result();
        $result->set('user', 'not-an-array');

        $this->expectException(\LogicException::class);

        $result->set('user.name', 'Ada');
    }

    // ─── value ─────────────────────────────────────────────────────────────

    public function test_value_returns_stored_value(): void
    {
        $result = new Result();
        $result->set('name', 'Ada');

        $this->assertSame('Ada', $result->value('name'));
    }

    public function test_value_returns_default_when_absent(): void
    {
        $result = new Result();

        $this->assertSame('fallback', $result->value('absent', 'fallback'));
    }

    public function test_value_returns_null_when_absent_without_default(): void
    {
        $result = new Result();

        $this->assertNull($result->value('absent'));
    }

    public function test_value_empty_path_returns_whole_array(): void
    {
        $result = new Result();
        $result->set('name', 'Ada');

        $this->assertSame(['name' => 'Ada'], $result->value(''));
    }

    public function test_value_returns_null_for_present_null_value(): void
    {
        $result = new Result();
        $result->set('a.b', null);

        $this->assertNull($result->value('a.b'));
    }

    // ─── tryValue ──────────────────────────────────────────────────────────

    public function test_try_value_returns_found_value(): void
    {
        $result = new Result();
        $result->set('user.name', 'Ada');

        $this->assertSame([true, 'Ada'], $result->tryValue('user.name'));
    }

    public function test_try_value_distinguishes_present_null_from_absent(): void
    {
        $result = new Result();
        $result->set('a.b', null);

        $this->assertSame([true, null], $result->tryValue('a.b'));
        $this->assertSame([false, null], $result->tryValue('a.c'));
    }

    // ─── hasValue ──────────────────────────────────────────────────────────

    public function test_has_value_empty_path_always_true(): void
    {
        $result = new Result();

        $this->assertTrue($result->hasValue(''));
    }

    public function test_has_value_distinguishes_present_null_from_absent(): void
    {
        $result = new Result();
        $result->set('a.b', null);

        $this->assertTrue($result->hasValue('a.b'));
        $this->assertFalse($result->hasValue('a.c'));
    }

    public function test_has_value_true_for_stored_value(): void
    {
        $result = new Result();
        $result->set('name', 'Ada');

        $this->assertTrue($result->hasValue('name'));
    }

    // ─── values ────────────────────────────────────────────────────────────

    public function test_values_returns_nested_structure(): void
    {
        $result = new Result();
        $result->set('user.name', 'Ada');
        $result->set('user.email', 'ada@example.com');
        $result->set('active', true);

        $this->assertSame([
            'user' => ['name' => 'Ada', 'email' => 'ada@example.com'],
            'active' => true,
        ], $result->values());
    }

    // ─── errors ────────────────────────────────────────────────────────────

    public function test_errors_returns_expected_structure(): void
    {
        $result = new Result();
        $result->addError('name', 'name error');
        $result->addError('email', 'email error');

        $this->assertSame([
            'name' => ['name error'],
            'email' => ['email error'],
        ], $result->errors());
    }

    // ─── hasErrors ─────────────────────────────────────────────────────────

    public function test_has_errors_false_when_empty(): void
    {
        $result = new Result();

        $this->assertFalse($result->hasErrors());
    }

    public function test_has_errors_true_after_add_error(): void
    {
        $result = new Result();
        $result->addError('name', 'error');

        $this->assertTrue($result->hasErrors());
    }

    // ─── snapshotErrors / restoreErrors ────────────────────────────────────

    public function test_snapshot_is_isolated_from_later_add_error(): void
    {
        $result = new Result();
        $result->addError('a', 'a error');
        $snapshot = $result->snapshotErrors();

        $result->addError('b', 'b error');

        $this->assertSame(['a' => ['a error']], $snapshot);
        $this->assertArrayHasKey('b', $result->errors());
    }

    public function test_restore_errors_fully_replaces_not_merges(): void
    {
        $result = new Result();
        $result->addError('a', 'a error');
        $snapshot = $result->snapshotErrors();

        $result->addError('b', 'b error');
        $result->restoreErrors($snapshot);

        $this->assertSame(['a' => ['a error']], $result->errors());
        $this->assertArrayNotHasKey('b', $result->errors());
    }

    // ─── valueAs() typed getter ────────────────────────────────────────────

    public function test_as_casts_to_int(): void
    {
        $result = new Result();
        $result->set('age', '42');

        $this->assertSame(42, $result->valueAs('age', 'int'));
    }

    public function test_as_casts_to_string(): void
    {
        $result = new Result();
        $result->set('age', 42);

        $this->assertSame('42', $result->valueAs('age', 'string'));
    }

    public function test_as_casts_to_bool(): void
    {
        $result = new Result();
        $result->set('active', '1');

        $this->assertTrue($result->valueAs('active', 'bool'));
    }

    public function test_as_casts_to_float(): void
    {
        $result = new Result();
        $result->set('price', '9.99');

        $this->assertSame(9.99, $result->valueAs('price', 'float'));
    }

    public function test_as_returns_default_when_path_absent(): void
    {
        $result = new Result();

        $this->assertSame(0, $result->valueAs('missing', 'int', 0));
    }

    public function test_as_array_returns_default_when_not_array(): void
    {
        $result = new Result();
        $result->set('user', 'not-an-array');

        $this->assertNull($result->valueAs('user', 'array'));
    }

    public function test_as_array_returns_array_value(): void
    {
        $result = new Result();
        $result->set('user', ['name' => 'Ada']);

        $this->assertSame(['name' => 'Ada'], $result->valueAs('user', 'array'));
    }

    public function test_as_class_returns_instance_when_matching(): void
    {
        $result = new Result();
        $result->set('user', new \stdClass());

        $this->assertInstanceOf(\stdClass::class, $result->valueAs('user', \stdClass::class));
    }

    public function test_as_class_returns_default_when_not_matching(): void
    {
        $result = new Result();
        $result->set('user', 'not-an-object');

        $this->assertNull($result->valueAs('user', \stdClass::class));
    }

    // ─── requireValueAs() fail-fast typed getter ──────────────────────────

    public function test_require_as_casts_to_int(): void
    {
        $result = new Result();
        $result->set('age', '42');

        $this->assertSame(42, $result->requireValueAs('age', 'int'));
    }

    public function test_require_as_returns_class_instance_when_matching(): void
    {
        $result = new Result();
        $user = new \stdClass();
        $result->set('user', $user);

        $this->assertSame($user, $result->requireValueAs('user', \stdClass::class));
    }

    public function test_require_as_throws_when_path_absent(): void
    {
        $result = new Result();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Result has no value at path "age".');

        $result->requireValueAs('age', 'int');
    }

    public function test_require_as_throws_when_class_not_matching(): void
    {
        $result = new Result();
        $result->set('user', 'not-an-object');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Result value at path "user" cannot be cast to type "stdClass".');

        $result->requireValueAs('user', \stdClass::class);
    }

    public function test_require_as_throws_when_array_not_matching(): void
    {
        $result = new Result();
        $result->set('user', 'not-an-array');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Result value at path "user" cannot be cast to type "array".');

        $result->requireValueAs('user', 'array');
    }
}