<?php

namespace Lucent\Facades;

use Lucent\Faker\FakeRequest;

class Faker
{

    public static function request() : FakeRequest
    {
        return new FakeRequest();
    }

}