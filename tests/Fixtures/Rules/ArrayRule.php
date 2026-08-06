<?php
namespace App\Rules;

use Lucent\Validation\Rule;

class ArrayRule extends Rule
{

    private array $keys = ["first_name", "last_name", "address"];

    public function setup(): array
    {
        return [
            'values' => "allowed_values"
        ];
    }

    protected function allowed_values(array $value): bool
    {
        return empty(array_diff(array_keys($value), $this->keys));
    }
}