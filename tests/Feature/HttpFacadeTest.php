<?php

namespace Tests\Feature;

use Lucent\Application;
use Lucent\Facades\Http;
use Lucent\Http\Client\Psr18Client;
use PHPUnit\Framework\TestCase;
use Tests\Support\Http\StartsFixtureServer;

class HttpFacadeTest extends TestCase
{
    use StartsFixtureServer;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset the cached facade client so each test starts fresh.
        Http::swap(null);
    }

    public function test_client_returns_shared_singleton(): void
    {
        $this->assertSame(Http::client(), Http::client());
        $this->assertInstanceOf(Psr18Client::class, Http::client());
    }

    public function test_client_registers_on_application_services(): void
    {
        $client = Http::client();

        $registered = Application::getInstance()->container()->get(Psr18Client::class);
        $this->assertSame($client, $registered);
    }

    public function test_get_delegates_to_client(): void
    {
        // Swap in a real client pointed at the shared fixture server. A
        // response proves the facade forwarded the call (and its arguments)
        // to the shared client rather than short-circuiting.
        $client = new Psr18Client(['base_uri' => self::$baseUrl]);
        Http::swap($client);

        $response = Http::get('/echo');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/echo', $payload['uri']);

        Http::swap(null);
    }
}
