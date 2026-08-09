<?php
namespace App\Controllers;

use Lucent\Http\Message\Response;

class SecondRestController
{
    public function test() : Response
    {
            return (new Response())->withJsonEnvelope([], 'Hello from five', true, 200);
    }
}