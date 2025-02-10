<?php

namespace Lucent\Http\Attributes;

use Attribute;

#[Attribute]
class ApiEndpoint
{
    public function __construct(
        public string $description,
        public string $path,
        public ?string $rule = null,
        public string $method = 'POST',
        public array $pathParams = []
    ) {}

    public function getUrlParameters(): array
    {
        preg_match_all('/{([^}]+)}/', $this->path, $matches);
        return $matches[1] ?? [];
    }

}