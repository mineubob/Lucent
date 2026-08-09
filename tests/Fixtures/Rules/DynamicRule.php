<?php
namespace App\Rules;

use Lucent\Validation\Rule;

class DynamicRule extends Rule
{

    private array $keys;

    public function __construct(array $keys)
    {
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
            ,
            'address' => [
                'min:0',
                'max:10',
            ]
        ];

        return array_filter($rules, function (string $field) {
            return array_key_exists($field, $this->keys);
        }, ARRAY_FILTER_USE_KEY);
    }
}