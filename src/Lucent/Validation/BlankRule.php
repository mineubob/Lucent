<?php

namespace Lucent\Validation;

class BlankRule extends Rule
{

    public function setup(): array
    {
        //Do nothing.
        return [];
    }

    public function setRules(array $rules): void
    {
        $this->rules = $rules;
    }
}