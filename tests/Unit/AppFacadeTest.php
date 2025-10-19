<?php

namespace Unit;

use Lucent\Facades\App;
use PHPUnit\Framework\TestCase;

class AppFacadeTest extends TestCase
{

    public function test_get_url():void
    {

        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/four";

        App::handleHttpRequest();
        $url = App::currentRoute();

        $this->assertEquals(["test","four"], $url);
    }

}