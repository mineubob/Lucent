<?php
namespace App\Rules;

class OverrideMessageRule extends TestRule
{
    public function setup(): array
    {

        $this->overrideRuleMessage("min", "Message Override!");

        return [
            'first_name' => [
                'min:5',
                'max:10',
            ]
        ];
    }
}