<?php
namespace App\Controllers;

use Lucent\Http\EventStream\Event;
use Lucent\Http\Message\Response;
use Generator;

class StreamTestController
{
    public function stream(): Response
    {
        return (new Response())->withEventStream($this->generateEvents());
    }

    private function generateEvents(): Generator
    {
        for ($i = 1; $i <= 10; $i++) {
            yield Event::data('number', ['value' => $i])->toSSE();
            sleep(1);
        }
        yield Event::complete(['total' => 10])->toSSE();
    }
}