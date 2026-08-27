<?php

namespace Tests\Unit\Validation;

use Lucent\Http\Message\UploadedFile;
use Lucent\Validation\FieldContext;
use Lucent\Validation\Result;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

class FieldContextTest extends TestCase
{
    private function context(
        string $field = 'name',
        mixed $value = 'Ada',
        bool $present = true,
        array|null $files = null,
        mixed $body = null,
        array $context = [],
        string $name = '',
    ): FieldContext {
        return new FieldContext($field, $value, $present, new Result(), $files, $body, $context, $name);
    }

    // ─── readonly properties ───────────────────────────────────────────────

    public function test_exposes_field_property(): void
    {
        $ctx = $this->context(field: 'user.name');

        $this->assertSame('user.name', $ctx->field);
    }

    public function test_exposes_present_property(): void
    {
        $this->assertTrue($this->context(present: true)->present);
        $this->assertFalse($this->context(present: false)->present);
    }

    // ─── name defaulting ───────────────────────────────────────────────────

    public function test_name_defaults_to_field_when_empty(): void
    {
        $ctx = $this->context(field: 'user.name');

        $this->assertSame('user.name', $ctx->name);
    }

    public function test_name_uses_explicit_value(): void
    {
        $ctx = $this->context(field: 'user.name', name: 'name');

        $this->assertSame('name', $ctx->name);
    }

    // ─── child ─────────────────────────────────────────────────────────────

    public function test_child_from_root_uses_bare_name(): void
    {
        $ctx = $this->context(field: '');
        $child = $ctx->child('name', 'Ada', true);

        $this->assertSame('name', $child->field);
        $this->assertSame('name', $child->name);
    }

    public function test_child_extends_dotted_path(): void
    {
        $ctx = $this->context(field: 'user');
        $child = $ctx->child('name', 'Ada', true);

        $this->assertSame('user.name', $child->field);
        $this->assertSame('name', $child->name);
    }

    public function test_grandchild_extends_path_recursively(): void
    {
        $ctx = $this->context(field: 'user');
        $address = $ctx->child('address', ['city' => 'Sydney'], true);
        $city = $address->child('city', 'Sydney', true);

        $this->assertSame('user.address', $address->field);
        $this->assertSame('user.address.city', $city->field);
    }

    public function test_child_preserves_present_flag(): void
    {
        $ctx = $this->context(field: 'user');
        $child = $ctx->child('name', null, false);

        $this->assertFalse($child->present);
    }

    // ─── file ──────────────────────────────────────────────────────────────

    public function test_file_returns_uploaded_file_when_present(): void
    {
        $file = new UploadedFile('path/to/file.txt', 10, UPLOAD_ERR_OK, 'file.txt');
        $ctx = $this->context(field: 'avatar', files: ['avatar' => $file]);

        $this->assertSame($file, $ctx->file());
    }

    public function test_file_returns_null_when_key_absent(): void
    {
        $file = new UploadedFile('path/to/file.txt', 10, UPLOAD_ERR_OK, 'file.txt');
        $ctx = $this->context(field: 'avatar', files: ['other' => $file]);

        $this->assertNull($ctx->file());
    }

    public function test_file_returns_null_when_files_is_null(): void
    {
        $ctx = $this->context(field: 'avatar', files: null);

        $this->assertNull($ctx->file());
    }

    public function test_file_returns_null_when_files_is_empty(): void
    {
        $ctx = $this->context(field: 'avatar', files: []);

        $this->assertNull($ctx->file());
    }

    public function test_file_returns_null_when_value_is_not_uploaded_file(): void
    {
        $ctx = $this->context(field: 'avatar', files: ['avatar' => 'not-a-file']);

        $this->assertNull($ctx->file());
    }

    public function test_file_uses_leaf_name_not_full_path(): void
    {
        $file = new UploadedFile('path/to/file.txt', 10, UPLOAD_ERR_OK, 'file.txt');
        $ctx = $this->context(field: 'user.avatar', files: ['avatar' => $file], name: 'avatar');

        $this->assertSame($file, $ctx->file());
    }

    public function test_file_lookup_in_nested_tree(): void
    {
        $file = new UploadedFile('path/to/file.txt', 10, UPLOAD_ERR_OK, 'file.txt');
        $ctx = $this->context(field: 'user.avatar', files: ['user' => ['avatar' => $file]], name: 'avatar');

        // file() looks up by leaf name at the top level of the files array.
        $this->assertNull($ctx->file());
    }

    // ─── valueOf ───────────────────────────────────────────────────────────

    public function test_value_of_self_returns_own_value(): void
    {
        $ctx = $this->context(field: 'name', value: 'Ada');

        $this->assertSame('Ada', $ctx->valueOf('name'));
    }

    public function test_value_of_self_returns_updated_value_after_normalize(): void
    {
        $ctx = $this->context(field: 'age', value: '5');
        $ctx->normalize(5);

        $this->assertSame(5, $ctx->valueOf('age'));
    }

    public function test_value_of_sibling_resolves_relative_to_parent(): void
    {
        $ctx = $this->context(field: 'user.password', value: 'secret', body: [
            'user' => ['password' => 'secret', 'password_confirmation' => 'secret'],
        ]);

        $this->assertSame('secret', $ctx->valueOf('password_confirmation'));
    }

    public function test_value_of_prefers_normalized_result_over_raw_body(): void
    {
        $ctx = $this->context(field: 'user.age', value: '5', body: [
            'user' => ['age' => '5', 'other' => 'x'],
        ]);
        $ctx->normalize(5);

        $this->assertSame(5, $ctx->valueOf('age'));
    }

    public function test_value_of_falls_back_to_raw_body(): void
    {
        $ctx = $this->context(field: 'user.name', value: 'Ada', body: [
            'user' => ['name' => 'Ada', 'email' => 'ada@example.com'],
        ]);

        $this->assertSame('ada@example.com', $ctx->valueOf('email'));
    }

    public function test_value_of_returns_null_for_absent_field(): void
    {
        $ctx = $this->context(field: 'user.name', value: 'Ada', body: [
            'user' => ['name' => 'Ada'],
        ]);

        $this->assertNull($ctx->valueOf('email'));
    }

    public function test_value_of_returns_null_when_body_is_null(): void
    {
        $ctx = $this->context(field: 'name', value: 'Ada', body: null);

        $this->assertNull($ctx->valueOf('email'));
    }

    /**
     * BUG-REVEALING TEST: valueOf() with a dotted field name. resolveSibling()
     * prepends the parent path to any field containing a dot, producing
     * "user.user.password_confirmation" instead of "user.password_confirmation",
     * so the lookup returns null.
     */
    public function test_value_of_dotted_field_prepends_parent_path(): void
    {
        $ctx = $this->context(field: 'user.password', value: 'secret', body: [
            'user' => ['password' => 'secret', 'password_confirmation' => 'secret'],
        ]);

        $this->assertSame('secret', $ctx->valueOf('user.password_confirmation'));
    }

    // ─── normalize ─────────────────────────────────────────────────────────

    public function test_normalize_updates_value_and_result(): void
    {
        $ctx = $this->context(field: 'age', value: '5');
        $ctx->normalize(5);

        $this->assertSame(5, $ctx->value);
        $this->assertSame(5, $ctx->result->value('age'));
    }

    // ─── context bag ───────────────────────────────────────────────────────

    public function test_context_returns_default_when_key_absent(): void
    {
        $ctx = $this->context();

        $this->assertNull($ctx->context('missing', 'int'));
        $this->assertSame(0, $ctx->context('missing', 'int', 0));
    }

    public function test_context_casts_scalar_types(): void
    {
        $ctx = $this->context(context: ['age' => '42']);

        $this->assertSame(42, $ctx->context('age', 'int'));
        $this->assertSame('42', $ctx->context('age', 'string'));
    }

    public function test_context_returns_class_instance_when_matching(): void
    {
        $request = new \stdClass();
        $ctx = $this->context(context: ['request' => $request]);

        $this->assertSame($request, $ctx->context('request', \stdClass::class));
    }

    public function test_context_returns_default_when_class_not_matching(): void
    {
        $ctx = $this->context(context: ['request' => 'not-an-object']);

        $this->assertNull($ctx->context('request', \stdClass::class));
    }

    public function test_child_propagates_context_bag(): void
    {
        $ctx = $this->context(field: 'user', context: ['user_id' => 42]);
        $child = $ctx->child('name', 'Ada', true);

        $this->assertSame(42, $child->context('user_id', 'int'));
    }

    // ─── requireContext() fail-fast typed getter ──────────────────────────

    public function test_require_context_casts_scalar_types(): void
    {
        $ctx = $this->context(context: ['age' => '42']);

        $this->assertSame(42, $ctx->requireContext('age', 'int'));
    }

    public function test_require_context_returns_class_instance_when_matching(): void
    {
        $request = new \stdClass();
        $ctx = $this->context(context: ['request' => $request]);

        $this->assertSame($request, $ctx->requireContext('request', \stdClass::class));
    }

    public function test_require_context_throws_when_key_absent(): void
    {
        $ctx = $this->context();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Context key "user_id" is missing.');

        $ctx->requireContext('user_id', 'int');
    }

    public function test_require_context_throws_when_class_not_matching(): void
    {
        $ctx = $this->context(context: ['request' => 'not-an-object']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Context value "request" cannot be cast to type "stdClass".');

        $ctx->requireContext('request', \stdClass::class);
    }

    public function test_require_context_throws_when_array_not_matching(): void
    {
        $ctx = $this->context(context: ['request' => 'not-an-array']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Context value "request" cannot be cast to type "array".');

        $ctx->requireContext('request', 'array');
    }
}