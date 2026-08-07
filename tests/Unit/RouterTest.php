<?php

namespace Tests\Unit;

use Lucent\Application;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function test_get_uri_as_array():void
    {
        $url = Application::getInstance()->httpRouter->getUriAsArray('/test/four');

        $this->assertEquals(["test","four"], $url);
    }

}