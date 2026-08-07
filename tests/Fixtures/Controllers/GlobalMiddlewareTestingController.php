<?php
namespace App\Controllers;

use Lucent\Http\Message\Response;

class GlobalMiddlewareTestingController
{
    public function ok(): Response
    {
        return (new Response())->withJsonEnvelope([], 'ok', true, 200);
    }

    public function boom(): Response
    {
        throw new \RuntimeException('Controller exploded');
    }
}