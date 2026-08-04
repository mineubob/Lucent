<?php

namespace Unit\Middleware;

use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Middleware\LegacyHandlerAdapter;
use PHPUnit\Framework\TestCase;

class LegacyHandlerAdapterTest extends TestCase
{
    public function test_psr7_response_passthrough(): void
    {
        $expectedResponse = (new Response())->withStatus(201);
        $adapter = new LegacyHandlerAdapter(function (ServerRequest $request) use ($expectedResponse) {
            return $expectedResponse;
        });

        $request = new ServerRequest();
        $result = $adapter->handle($request);

        $this->assertSame($expectedResponse, $result);
        $this->assertSame(201, $result->getStatusCode());
    }

    public function test_legacy_http_response_conversion(): void
    {
        $adapter = new LegacyHandlerAdapter(function (ServerRequest $request) {
            return new \Lucent\Http\HttpResponse('legacy body', 200, ['X-Legacy' => 'yes']);
        });

        $request = new ServerRequest();
        $result = $adapter->handle($request);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('legacy body', (string) $result->getBody());
        $this->assertSame('yes', $result->getHeaderLine('X-Legacy'));
    }

    public function test_legacy_json_response_conversion(): void
    {
        $adapter = new LegacyHandlerAdapter(function (ServerRequest $request) {
            $json = new \Lucent\Http\JsonResponse();
            $json->setContent(['data' => 'test'])
                ->setMessage('OK')
                ->setOutcome(true)
                ->setStatusCode(200);
            return $json;
        });

        $request = new ServerRequest();
        $result = $adapter->handle($request);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_invalid_return_type_throws(): void
    {
        $adapter = new LegacyHandlerAdapter(function (ServerRequest $request) {
            return 'invalid string';
        });

        $this->expectException(\RuntimeException::class);
        $adapter->handle(new ServerRequest());
    }
}