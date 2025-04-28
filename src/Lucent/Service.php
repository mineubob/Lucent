<?php

namespace Lucent;

class Service
{

    public function singleton(string $classname) : mixed
    {
        return Application::getInstance()->addService($classname);
    }

}