<?php
namespace App\Rules;

use Lucent\Validation\Rule;

class CustomRegexRule extends Rule
{

    public function setup(): array
    {
        $this->addRegexPattern("custom_rule", '/^(?=(?:.*\d){3})(?=(?:.*[a-zA-Z]){3})[a-zA-Z\d]{6}$/');

        return [
            'test' => [
                'regex:custom_rule',
            ]
        ];

    }

}