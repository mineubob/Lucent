<?php

namespace Lucent\Http\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiResponse
{
    public function __construct(
        public string $message,
        public bool $outcome = true,
        public int $status = 200,
        public array $errors = []
    ) {}
}