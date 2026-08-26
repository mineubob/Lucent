<?php

namespace Tests\Unit\Validation;

use Lucent\Http\Message\ServerRequest;
use Lucent\Validation\Combinators\All;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Normalizers\Trim;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class NormalizersTest extends TestCase
{
    use BuildsValidationRequests;

    // ─── Trim ──────────────────────────────────────────────────────────────

    public function test_trim_trims_whitespace_from_string(): void
    {
        $validator = new Validator(['name' => new Trim()]);

        $result = $validator->validate($this->request(['name' => '  Ada  ']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('name'));
    }

    public function test_trim_whitespace_only_normalizes_to_empty(): void
    {
        $validator = new Validator(['name' => new Trim()]);

        $result = $validator->validate($this->request(['name' => '   ']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('', $result->value('name'));
    }

    public function test_trim_empty_string_normalizes_to_empty(): void
    {
        $validator = new Validator(['name' => new Trim()]);

        $result = $validator->validate($this->request(['name' => '']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('', $result->value('name'));
    }

    public function test_trim_leaves_non_string_unchanged(): void
    {
        foreach ([42, 3.14, ['a'], null, true] as $value) {
            $validator = new Validator(['name' => new Trim()]);
            $result = $validator->validate($this->request(['name' => $value]));
            $this->assertFalse($result->hasErrors(), "Expected pass for " . var_export($value, true));
            $this->assertSame($value, $result->value('name'));
        }
    }

    public function test_trim_always_passes_without_error(): void
    {
        $validator = new Validator(['name' => new Trim()]);

        $result = $validator->validate($this->request(['name' => '  x  ']));

        $this->assertFalse($result->hasErrors());
    }

    public function test_trim_composed_with_required(): void
    {
        // Trim runs first, then Required sees the trimmed value.
        $validator = new Validator([
            'name' => All::of(new Trim(), new Required()),
        ]);

        $result = $validator->validate($this->request(['name' => '  Ada  ']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('name'));
    }

    public function test_trim_composed_with_required_fails_on_whitespace_only(): void
    {
        // Trim turns '   ' into '', then Required fails on the empty value.
        $validator = new Validator([
            'name' => All::of(new Trim(), new Required()),
        ]);

        $result = $validator->validate($this->request(['name' => '   ']));

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('name', $result->errors());
    }

    // ─── defaultMessage returns null ───────────────────────────────────────

    public function test_default_message_returns_null(): void
    {
        $trim = new Trim();
        $request = ServerRequest::create('POST', '/');
        $ctx = new \Lucent\Validation\FieldContext('name', 'x', true, $request, new \Lucent\Validation\Result(), null, null);

        // A normalizer always passes, so its message is never requested in
        // normal flow. Requesting it must return null (no error), not throw.
        $this->assertNull($trim->message($ctx));
    }
}