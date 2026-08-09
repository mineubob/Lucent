<?php
namespace App\Rules;

use Lucent\Validation\Rule;

class SignupRule extends Rule
{

    public function setup(): array
    {
        return [
            "email" => [
                "regex:email",
                "max:255"
            ],
            "full_name" => [
                "min:2",
                "max:100"
            ],
            "password" => [
                "regex:password",
                "min:8",
                "max:255"
            ],
            "password_confirmation" => [
                "same:password"
            ]
        ];
    }
}