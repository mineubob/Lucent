<?php
namespace App\Rules;

use Lucent\Validation\Rule;

class CustomRule extends Rule
{

    public function setup(): array
    {
        return [
            'post_code' => [
                'validate_post_code',
            ]
        ];
    }

    protected function validate_post_code(mixed $value): bool
    {
        return strlen((string) $value) === 4;
    }
}