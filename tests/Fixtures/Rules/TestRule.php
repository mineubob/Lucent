<?php
namespace App\Rules;

use Lucent\Validation\Rule;

abstract class TestRule extends Rule
{
    public function validate_bool(array $data): bool
    {
        return sizeof($this->validate($data)) === 0;
    }
}