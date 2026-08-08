<?php
namespace App\Services;

use App\Services\InjectionGreeterInterface;

class InjectionGreeter implements InjectionGreeterInterface
{
    public function greet(): string
    {
        return 'hello-from-container';
    }
}
