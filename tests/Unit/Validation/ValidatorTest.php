<?php

namespace Tests\Unit\Validation;

use Lucent\Http\Message\ServerRequest;
use Lucent\Validation\Combinators\Each;
use Lucent\Validation\Combinators\Shape;
use Lucent\Validation\Constraints\Email;
use Lucent\Validation\Constraints\Length;
use Lucent\Validation\Constraints\Numeric;
use Lucent\Validation\Constraints\Required;
use Lucent\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class ValidatorTest extends TestCase
{
    use BuildsValidationRequests;

    // ─── single Constraint argument ────────────────────────────────────────

    public function test_single_constraint_argument(): void
    {
        $validator = new Validator(new Each(new Numeric()));

        $result = $validator->validate($this->request(['1', '2']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame([1, 2], $result->values());
    }

    // ─── flat map (sugar for Shape) ────────────────────────────────────────

    public function test_flat_map_validates_fields(): void
    {
        $validator = new Validator([
            'name'  => new Required(),
            'email' => new Email(),
        ]);

        $result = $validator->validate($this->request([
            'name'  => 'Ada',
            'email' => 'ada@example.com',
        ]));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('name'));
        $this->assertSame('ada@example.com', $result->value('email'));
    }

    public function test_flat_map_records_errors(): void
    {
        $validator = new Validator([
            'name'  => new Required(),
            'email' => new Email(),
        ]);

        $result = $validator->validate($this->request([
            'name'  => '',
            'email' => 'not-an-email',
        ]));

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('name', $result->errors());
        $this->assertArrayHasKey('email', $result->errors());
    }

    public function test_absent_field_is_not_seeded(): void
    {
        $validator = new Validator(['name' => new Required()]);

        $result = $validator->validate($this->request([]));

        $this->assertFalse($result->hasValue('name'));
    }

    // ─── top-level Shape ───────────────────────────────────────────────────

    public function test_top_level_shape_validates_object_body(): void
    {
        $validator = new Validator(Shape::object([
            'name'  => new Required(),
            'email' => new Email(),
        ]));

        $result = $validator->validate($this->request([
            'name'  => 'Ada',
            'email' => 'ada@example.com',
        ]));

        $this->assertFalse($result->hasErrors());
        $this->assertSame('Ada', $result->value('name'));
    }

    // ─── top-level Each (array body) ───────────────────────────────────────

    public function test_top_level_each_validates_array_body(): void
    {
        $validator = new Validator(new Each(new Numeric()));

        $result = $validator->validate($this->request(['1', '2', '3']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame([1, 2, 3], $result->values());
    }

    // ─── top-level Tuple (array body) ──────────────────────────────────────

    public function test_top_level_tuple_validates_array_body(): void
    {
        $validator = new Validator(Shape::tuple(new Numeric(), new Length(min: 2)));

        $result = $validator->validate($this->request(['42', 'ab']));

        $this->assertFalse($result->hasErrors());
        $this->assertSame(42, $result->value('0'));
    }

    // ─── empty array ───────────────────────────────────────────────────────

    public function test_empty_array_wraps_in_empty_shape_and_passes(): void
    {
        $validator = new Validator([]);

        $result = $validator->validate($this->request(['anything' => 'x']));

        $this->assertFalse($result->hasErrors());
    }

    // ─── invalid constructor argument ──────────────────────────────────────

    public function test_array_with_non_constraint_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Validator(['name' => 'not-a-constraint']);
    }

    // ─── GET request (null body) ───────────────────────────────────────────

    public function test_get_request_null_body_shape_fails_gracefully(): void
    {
        $validator = new Validator(Shape::object(['name' => new Required()]));

        $result = $validator->validate(ServerRequest::create('GET', '/'));

        $this->assertTrue($result->hasErrors());
        $this->assertArrayHasKey('', $result->errors());
    }

    // ─── top-level tuple rejects object body ───────────────────────────────

    public function test_top_level_tuple_rejects_object_body(): void
    {
        $validator = new Validator(Shape::tuple(new Numeric(), new Numeric()));

        $result = $validator->validate($this->request(['a' => '1', 'b' => '2']));

        $this->assertTrue($result->hasErrors());
    }
}