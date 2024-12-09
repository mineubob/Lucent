<?php

namespace Lucent\Validation;

class BlankRule extends Rule
{

    public function setup()
    {
        //Do nothing.
    }

    public function setRules(array $rules): void
    {
        $this->rules = $rules;
    }
}