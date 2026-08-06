<?php

namespace Unit\Middleware;

use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use Lucent\Http\Middleware\CallbackRequestHandler;
use Lucent\Http\Middleware\MiddlewarePipeline;
use PHPUnit\Framework\TestCase;

class MiddlewarePipelineTest extends TestCase
{
    private function createHandler(): \Psr\Http\Server\RequestHandlerInterface
    {
        return new CallbackRequestHandler(function (ServerRequest $req) {
            return (new Response())->withStatus(200);
        });
    }

    public function test_empty_pipeline_passes_to_handler(): void
    {
        $handler = $this->createHandler();

        $pipeline = new MiddlewarePipeline([], $handler);
        $request = new ServerRequest();

        $result = $pipeline->handle($request);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function test_middleware_modifies_request(): void
    {
        $handler = $this->createHandler();

        $pipeline = new MiddlewarePipeline(
            [
                new class implements \Psr\Http\Server\MiddlewareInterface {
                    public function process(
                        \Psr\Http\Message\ServerRequestInterface $request,
                        \Psr\Http\Server\RequestHandlerInterface $handler
                    ): \Psr\Http\Message\ResponseInterface {
                        $request = $request->withAttribute('processed', true);
                        return $handler->handle($request);
                    }
                },
                new class implements \Psr\Http\Server\MiddlewareInterface {
                    public function process(
                        \Psr\Http\Message\ServerRequestInterface $request,
                        \Psr\Http\Server\RequestHandlerInterface $handler
                    ): \Psr\Http\Message\ResponseInterface {
                        $response = $handler->handle($request);
                        return $response->withHeader('X-Middleware', 'applied');
                    }
                },
            ],
            $handler
        );

        $request = new ServerRequest();
        $result = $pipeline->handle($request);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('applied', $result->getHeaderLine('X-Middleware'));
    }

    public function test_middleware_can_return_early(): void
    {
        $handler = $this->createHandler();

        $pipeline = new MiddlewarePipeline(
            [
                new class implements \Psr\Http\Server\MiddlewareInterface {
                    public function process(
                        \Psr\Http\Message\ServerRequestInterface $request,
                        \Psr\Http\Server\RequestHandlerInterface $handler
                    ): \Psr\Http\Message\ResponseInterface {
                        return (new Response())->withStatus(401)->withHeader('X-Blocked', 'true');
                    }
                },
            ],
            $handler
        );

        $result = $pipeline->handle(new ServerRequest());

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('true', $result->getHeaderLine('X-Blocked'));
    }
}