<?php

namespace Tests\Feature;

use Lucent\Application;
use Lucent\Facades\App;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\Concerns\MakeRequest;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

class GlobalMiddlewareTest extends TestCase
{
    use MakeRequest;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        FixtureLoader::copyController('GlobalMiddlewareTestingController.php');
        FixtureLoader::copyMiddleware('GlobalHeaderMiddleware.php');
        FixtureLoader::copyMiddleware('GlobalShortCircuitMiddleware.php');
        FixtureLoader::copyMiddleware('GlobalThrowingMiddleware.php');
        FixtureLoader::copyMiddleware('GlobalAuthMiddleware.php');
        FixtureLoader::copyMiddleware('AuthMiddleware.php');

        FixtureLoader::copyRoutes('globalMiddleware.php');

        // Boot once. Route files are loaded via require_once, so a second
        // boot() in the same process would register no routes.
        self::refreshApplication();
        Application::getInstance()->boot();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any global middleware registered by a previous test so it
        // does not leak into this one.
        $reflection = new \ReflectionProperty(Application::class, 'globalMiddlewares');
        $reflection->setValue(Application::getInstance(), []);
    }

    protected function request(string $method, string $uri): ResponseInterface
    {
        return $this->handle($this->makeRequest($method, $uri));
    }

    public function test_global_middleware_runs_on_matched_route(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalHeaderMiddleware::class);

        $response = $this->request('GET', '/global/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Global-Middleware'));
    }

    public function test_global_middleware_runs_on_unmatched_route(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalHeaderMiddleware::class);

        $response = $this->request('GET', '/does/not/exist');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Global-Middleware'));
    }

    public function test_global_middleware_runs_on_disabled_route(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalHeaderMiddleware::class);
        Application::getInstance()->httpRouter->disable('global/ok');

        $response = $this->request('GET', '/global/ok');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Global-Middleware'));
    }

    public function test_global_middleware_wraps_dispatch_error(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalHeaderMiddleware::class);

        $response = $this->request('GET', '/global/boom');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Global-Middleware'));
    }

    public function test_global_middleware_can_short_circuit_404(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalShortCircuitMiddleware::class);

        $response = $this->request('GET', '/does/not/exist');

        $this->assertSame(200, $response->getStatusCode());
        $body = (array) json_decode((string) $response->getBody(), true);
        $this->assertSame('short-circuited', $body['message'] ?? null);
    }

    public function test_global_middleware_that_throws_yields_500(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalThrowingMiddleware::class);

        $response = $this->request('GET', '/global/ok');

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_global_middleware_http_exception_keeps_status(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalAuthMiddleware::class);

        $response = $this->request('GET', '/global/ok');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_global_middleware_runs_before_route_middleware(): void
    {
        App::registerGlobalMiddlewares(\App\Middleware\GlobalHeaderMiddleware::class);

        // Route-scoped AuthMiddleware reads urlVars; global middleware must
        // have run first (it adds a header to the final response).
        $response = $this->request('GET', '/global-route-mw/ok');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Global-Middleware'));
    }
}