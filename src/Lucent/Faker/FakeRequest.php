<?php

namespace Lucent\Faker;

use Lucent\Http\Request;

class FakeRequest extends Request
{

    private array $fakeData = [];

    public function all(): array
    {
        return $this->fakeData;
    }

    /**
     * Generate passing validation data
     */
    public function passing(string $ruleClass): self
    {
        $rule = new $ruleClass();
        $this->fakeData = [];

        foreach ($rule->rules as $field => $rules) {
            $this->fakeData[$field] = $this->generateValidValue($field);
        }

        return $this;
    }

    /**
     * Generate failing validation data
     */
    public function failing(string $ruleClass): self
    {
        $rule = new $ruleClass();
        $this->fakeData = [];

        foreach ($rule->rules as $field => $rules) {
            $this->fakeData[$field] = '';
        }

        return $this;
    }

    private function generateValidValue(string $field): string
    {
        return match($field) {
            'email' => 'example@domain.com',
            'password' => 'SecureP@ss123',
            'full_name' => 'John Doe',
            'phone' => '+1234567890',
            'date' => '2025-02-10',
            default => 'example_value'
        };
    }

}