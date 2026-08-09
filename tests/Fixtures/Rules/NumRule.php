<?php
namespace App\Rules;

class NumRule extends TestRule
{
    public function setup(): array
    {
        return [
            'num1' => [
                'min_num:1',
                'max_num:5',
            ],
            'num2' => [
                'min_num:7',
                'max_num:10',
            ]
        ];
    }
}