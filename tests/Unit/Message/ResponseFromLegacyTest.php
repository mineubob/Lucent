<?php

namespace Unit\Message;

use Lucent\Http\EventStream\EventStreamResponse;
use Lucent\Http\HttpResponse;
use Lucent\Http\JsonResponse;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream\CallbackStream;
use Lucent\Http\RedirectResponse;
use PHPUnit\Framework\TestCase;

class ResponseFromLegacyTest extends TestCase
{
    public function test_from_legacy_http_response(): void
    {
        $legacy = new HttpResponse('Body content', 200, ['X-Custom' => 'value']);

        $psr7 = Response::fromLegacy($legacy);

        $this->assertSame(200, $psr7->getStatusCode());
        $this->assertSame('OK', $psr7->getReasonPhrase());
        $this->assertSame('Body content', (string) $psr7->getBody());
        $this->assertSame('value', $psr7->getHeaderLine('X-Custom'));
    }

    public function test_from_legacy_json_response(): void
    {
        $legacy = new JsonResponse(['key' => 'value'], 201);
        $legacy->setMessage('Success')
            ->setOutcome(true)
            ->setStatusCode(201);

        $psr7 = Response::fromLegacy($legacy);

        $this->assertSame(201, $psr7->getStatusCode());
        $this->assertSame('Created', $psr7->getReasonPhrase());

        $body = json_decode((string) $psr7->getBody(), true);
        $this->assertSame('Success', $body['message']);
        $this->assertTrue($body['outcome']);
        $this->assertSame(['key' => 'value'], $body['content']);
    }

    public function test_from_legacy_redirect_response(): void
    {
        $legacy = new RedirectResponse('/target', 301);

        $psr7 = Response::fromLegacy($legacy);

        $this->assertSame(301, $psr7->getStatusCode());
        $this->assertSame('/target', $psr7->getHeaderLine('Location'));
    }

    public function test_from_legacy_event_stream_response(): void
    {
        $legacy = new EventStreamResponse(function () {
            return 'event data';
        });

        $psr7 = Response::fromLegacy($legacy);

        $this->assertSame(200, $psr7->getStatusCode());
        $this->assertSame('text/event-stream', $psr7->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache, no-store, must-revalidate', $psr7->getHeaderLine('Cache-Control'));
        $this->assertInstanceOf(CallbackStream::class, $psr7->getBody());
        $this->assertSame('event data', (string) $psr7->getBody());
    }

    public function test_from_legacy_emits_deprecation(): void
    {
        $legacy = new HttpResponse('test', 200);

        $errors = [];
        set_error_handler(function (int $errno, string $errstr) use (&$errors) {
            $errors[] = ['errno' => $errno, 'errstr' => $errstr];
            return true;
        }, E_USER_DEPRECATED);

        Response::fromLegacy($legacy);

        restore_error_handler();

        $this->assertCount(1, $errors);
        $this->assertSame(E_USER_DEPRECATED, $errors[0]['errno']);
        $this->assertStringContainsString('fromLegacy', $errors[0]['errstr']);
    }

    public function test_from_legacy_handles_null_body(): void
    {
        $legacy = new HttpResponse(null, 204);

        $psr7 = Response::fromLegacy($legacy);

        $this->assertSame(204, $psr7->getStatusCode());
        $this->assertSame('', (string) $psr7->getBody());
    }

    public function test_from_legacy_preserves_multiple_headers(): void
    {
        $legacy = new HttpResponse('test', 200, [
            'X-Custom' => 'value1',
            'X-Other' => 'value2',
        ]);

        $psr7 = Response::fromLegacy($legacy);

        $this->assertSame('value1', $psr7->getHeaderLine('X-Custom'));
        $this->assertSame('value2', $psr7->getHeaderLine('X-Other'));
    }
}