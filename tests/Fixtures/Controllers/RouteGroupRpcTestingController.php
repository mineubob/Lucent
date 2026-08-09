<?php
namespace App\Controllers;

use Lucent\Http\Message\Response;

class RouteGroupRpcTestingController
{
    
    public function one($input) : Response
    {
            if($input === "ping"){
                return (new Response())->withJsonEnvelope([], 'pong', true, 200);
            }
            
            return (new Response())->withJsonEnvelope([], 'Message not passed as url parameter.', false, 400);
    }

    
    public function two() : Response
    {
            return (new Response())->withJsonEnvelope([], 'Hello from test 2', true, 200);
    }
}