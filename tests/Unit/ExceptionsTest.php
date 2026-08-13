<?php

namespace Tests\Unit;

use Lucent\Http\Exceptions\Exceptions;
use Lucent\Http\Exceptions\HttpException;
use Lucent\Http\HttpStatus;
use Lucent\Http\Message\RequestContext;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

class ExceptionsTest extends TestCase
{
    private function request(): ServerRequestInterface
    {
        return ServerRequest::create();
    }

    public function test_report_callback_typed_to_throwable_runs_for_every_exception(): void
    {
        $manager = new Exceptions();
        $reported = [];

        $manager->report(function (Throwable $e, ServerRequestInterface $request) use (&$reported) {
            $reported[] = $e::class;
        });

        $request = $this->request();
        $manager->reportException(new HttpException(HttpStatus::NOT_FOUND), $request);
        $manager->reportException(new RuntimeException('boom'), $request);

        $this->assertSame([HttpException::class, RuntimeException::class], $reported);
    }

    public function test_report_callback_typed_to_specific_class_only_matches_that_class(): void
    {
        $manager = new Exceptions();
        $reported = [];

        $manager->report(function (HttpException $e, ServerRequestInterface $request) use (&$reported) {
            $reported[] = $e::class;
        });

        $request = $this->request();
        $manager->reportException(new HttpException(HttpStatus::NOT_FOUND), $request);
        $manager->reportException(new RuntimeException('boom'), $request);

        $this->assertSame([HttpException::class], $reported);
    }

    public function test_all_matching_report_callbacks_run(): void
    {
        $manager = new Exceptions();
        $count = 0;

        $manager->report(function (Throwable $e, ServerRequestInterface $request) use (&$count) {
            $count++;
        });
        $manager->report(function (Throwable $e, ServerRequestInterface $request) use (&$count) {
            $count++;
        });

        $request = $this->request();
        $manager->reportException(new RuntimeException('boom'), $request);

        $this->assertSame(2, $count);
    }

    public function test_render_callback_typed_to_throwable_replaces_default_response(): void
    {
        $manager = new Exceptions();
        $custom = new Response();

        $manager->render(function (Throwable $e, ServerRequestInterface $request) use ($custom) {
            return $custom;
        });

        $request = $this->request();
        $response = $manager->renderException(new RuntimeException('boom'), $request);

        $this->assertSame($custom, $response);
    }

    public function test_render_callback_returning_null_falls_through(): void
    {
        $manager = new Exceptions();

        $manager->render(function (Throwable $e, ServerRequestInterface $request) {
            return null;
        });

        $request = $this->request();
        $this->assertNull($manager->renderException(new RuntimeException('boom'), $request));
    }

    public function test_render_callback_returning_nothing_falls_through(): void
    {
        $manager = new Exceptions();

        // A callback that returns nothing (implicitly null) must also fall
        // through to the framework default, just like an explicit null.
        $manager->render(function (Throwable $e, ServerRequestInterface $request) {
            // no return
        });

        $request = $this->request();
        $this->assertNull($manager->renderException(new RuntimeException('boom'), $request));
    }

    public function test_render_callback_typed_to_non_matching_exception_falls_through(): void
    {
        $manager = new Exceptions();
        $custom = new Response();

        $manager->render(function (HttpException $e, ServerRequestInterface $request) use ($custom) {
            return $custom;
        });

        $request = $this->request();
        $this->assertNull($manager->renderException(new RuntimeException('boom'), $request));
    }

    public function test_first_matching_render_callback_wins(): void
    {
        $manager = new Exceptions();
        $first = new Response();
        $second = new Response();

        $manager->render(function (Throwable $e, ServerRequestInterface $request) use ($first) {
            return $first;
        });
        $manager->render(function (Throwable $e, ServerRequestInterface $request) use ($second) {
            return $second;
        });

        $request = $this->request();
        $this->assertSame($first, $manager->renderException(new RuntimeException('boom'), $request));
    }

    public function test_report_callback_request_context_is_visible_to_render(): void
    {
        $manager = new Exceptions();

        $manager->report(function (Throwable $e, ServerRequestInterface $request) {
            // RequestContext::fromRequest() reads the shared mutable bag, so
            // the write is visible to the render callback without by-reference
            // reassignment of the request.
            RequestContext::fromRequest($request)?->set('error_id', 'abc-123');
        });

        $seen = null;
        $manager->render(function (Throwable $e, ServerRequestInterface $request) use (&$seen) {
            $seen = RequestContext::fromRequest($request)?->get('error_id');
            return new Response();
        });

        $request = $this->request();
        $manager->reportException(new RuntimeException('boom'), $request);
        $manager->renderException(new RuntimeException('boom'), $request);

        $this->assertSame('abc-123', $seen);
    }

    public function test_render_callback_typed_to_subclass_matches_parent_exception(): void
    {
        $manager = new Exceptions();
        $custom = new Response();

        // HttpException extends RuntimeException, so a callback typed to
        // RuntimeException must match an HttpException.
        $manager->render(function (RuntimeException $e, ServerRequestInterface $request) use ($custom) {
            return $custom;
        });

        $request = $this->request();
        $this->assertSame($custom, $manager->renderException(new HttpException(HttpStatus::NOT_FOUND), $request));
    }
}