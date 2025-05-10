<?php

namespace Lucent;

class Service
{

    public function singleton(string $classname) : mixed
    {
        return Application::getInstance()->addService($classname);
    }

    public function instance(object $service, ?string $alias = null): mixed
    {
        return Application::getInstance()->addService($service, $alias);
    }

    public function get(string $classnameOrAlias): mixed
    {
        return Application::getInstance()->services[$classnameOrAlias];
    }

    public function has(string $classnameOrAlias): bool
    {
        return array_key_exists($classnameOrAlias,Application::getInstance()->services);
    }

}