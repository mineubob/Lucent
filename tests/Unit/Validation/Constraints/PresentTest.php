<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Present;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class PresentTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, Present $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('field', $body, $constraint);
    }

    // ─── present values pass ───────────────────────────────────────────────

    public function test_present_with_null_value_passes(): void
    {
        $result = $this->validate(['field' => null], new Present());

        $this->assertFalse($result->hasErrors());
    }

    public function test_present_with_empty_string_passes(): void
    {
        $result = $this->validate(['field' => ''], new Present());

        $this->assertFalse($result->hasErrors());
    }

    public function test_present_with_false_passes(): void
    {
        $result = $this->validate(['field' => false], new Present());

        $this->assertFalse($result->hasErrors());
    }

    public function test_present_with_zero_passes(): void
    {
        $result = $this->validate(['field' => 0], new Present());

        $this->assertFalse($result->hasErrors());
    }

    // ─── absent fails ──────────────────────────────────────────────────────

    public function test_absent_field_fails(): void
    {
        $result = $this->validate([], new Present());

        $this->assertTrue($result->hasErrors());
    }

    public function test_absent_in_shape_fails(): void
    {
        $validator = new Validator([
            'user' => \Lucent\Validation\Combinators\Shape::object([
                'name' => new Present(),
            ]),
        ]);

        $result = $validator->validate($this->request(['user' => []]));

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('user.name', $result->errors());
    }

    // ─── comparison with Required ──────────────────────────────────────────

    public function test_present_passes_where_required_fails_on_empty_string(): void
    {
        $present = $this->validate(['field' => ''], new Present());
        $required = (new Validator(['field' => new Required()]))
            ->validate($this->request(['field' => '']));

        $this->assertFalse($present->hasErrors());
        $this->assertTrue($required->hasErrors());
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validate([], new Present());

        $this->assertSame(
            ['The field field must be present.'],
            $result->errors()['field'],
        );
    }
}