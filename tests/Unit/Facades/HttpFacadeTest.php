<?php

namespace Unit\Facades;

use Lucent\Application;
use Lucent\Facades\Http;
use Lucent\Http\Client\Psr18Client;
use PHPUnit\Framework\TestCase;

class HttpFacadeTest extends TestCase
{
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

        $registered = Application::getInstance()->services[Psr18Client::class] ?? null;
        $this->assertSame($client, $registered);
    }

    public function test_get_delegates_to_client(): void
    {
        // Swap in a real client pointed at a local fixture server. A response
        // proves the facade forwarded the call (and its arguments) to the
        // shared client rather than short-circuiting.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $router = dirname(__DIR__) . '/Client/fixtures/router.php';
        $process = proc_open(
            sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($router)),
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $pipes,
            dirname(__DIR__)
        );

        $this->assertIsResource($process);

        try {
            $deadline = microtime(true) + 5.0;
            $connected = false;
            while (microtime(true) < $deadline) {
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($conn !== false) {
                    fclose($conn);
                    $connected = true;
                    break;
                }
                usleep(50000);
            }

            $this->assertTrue($connected, 'Fixture server did not start');

            $client = new Psr18Client(['base_uri' => "http://127.0.0.1:{$port}"]);
            Http::swap($client);

            $response = Http::get('/echo');
            $payload = json_decode((string) $response->getBody(), true);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('/echo', $payload['uri']);
        } finally {
            proc_terminate($process);
            proc_close($process);
            Http::swap(null);
        }
    }
}
