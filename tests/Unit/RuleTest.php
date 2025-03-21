<?php

namespace Unit;

use Lucent\Facades\Faker;
use Lucent\Validation\Rule;
use PHPUnit\Framework\TestCase;

abstract class TestRule extends Rule
{
    public function validate_bool(array $data): bool
    {
        return sizeof($this->validate($data)) === 0;
    }
}

class NumRule extends TestRule
{
    public function setup(): array
    {
        return [
            'num1' => [
                'min_num:1',
                'max_num:5',
            ],
            'num2' => [
                'min_num:7',
                'max_num:10',
            ]
        ];
    }
}

class DynamicRule extends Rule
{

    private array $keys;

    public function __construct(array $keys){
        $this->keys = $keys;
    }

    public function setup(): array
    {
        $rules = [
            'first_name' => [
                'min:2',
                'max:10',
            ],
            'last_name' => [
                'min:2',
                'max:10',
            ]
        ];

        return array_filter($rules, function (string $field) {
            return array_key_exists($field, $this->keys);
        }, ARRAY_FILTER_USE_KEY);
    }
}

class RuleTest extends TestCase
{
    public function test_num_rule_is_valid() : void
    {
        $rule = new NumRule();

        $test_data = Faker::request()->passing(NumRule::class)->all();

        $this->assertTrue($rule->validate_bool($test_data));
    }

    public function test_num_rule_is_not_valid() : void
    {
        $rule = new NumRule();

        $test_data = Faker::request()->failing(NumRule::class)->all();

        $this->assertFalse($rule->validate_bool($test_data));
    }

    public function test_dynamic_rule_passing() : void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", "Harris");
        $request->setInput("email", "notvalid.com");

        $rule = new DynamicRule($request->all());

        $this->assertTrue($request->validate($rule));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_dynamic_rule_failing() : void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", 1);

        $rule = new DynamicRule($request->all());
        $this->assertFalse($request->validate($rule));
        $this->assertEquals(1,count($request->getValidationErrors()));

    }

    public function test_dynamic_rule_only_validates_present_fields(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");

        // Create a rule that only validates first_name
        $rule = new DynamicRule(["first_name" => "Jack"]);

        // Add an invalid last_name that would fail if validated
        $request->setInput("last_name", "A");  // Too short, would fail min:2

        // Should pass because last_name isn't in the keys array and won't be validated
        $this->assertTrue($request->validate($rule));
        $this->assertEmpty($request->getValidationErrors());
    }
}