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

class RuleTest extends TestCase
{
    public function test_num_rule_is_valid()
    {
        $rule = new NumRule();

        $test_data = Faker::request()->passing(NumRule::class)->all();

        $this->assertTrue($rule->validate_bool($test_data));
    }

    public function test_num_rule_is_not_valid()
    {
        $rule = new NumRule();

        $test_data = Faker::request()->failing(NumRule::class)->all();

        $this->assertFalse($rule->validate_bool($test_data));
    }
}