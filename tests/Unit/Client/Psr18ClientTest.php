<?php

namespace Unit\Client;

use Lucent\Http\Client\Exception\NetworkException;
use Lucent\Http\Client\Handler\CurlHandler;
use Lucent\Http\Client\Psr18Client;
use Lucent\Http\Message\Factory\HttpFactory;
use Lucent\Http\Message\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Unit\Client\Handler\MockHandler;

class Psr18ClientTest extends TestCase
{
    /** @var string Base URL of the fixture server */
    private static string $baseUrl;

    /** @var resource|null The running server process */
    private static $serverProcess = null;

    /** @var array<int, string> Server process pipes */
    private static array $serverPipes = [];

    private static ?int $serverPort = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Find a free port.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail("Unable to find a free port: $errstr");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$serverPort = (int) substr($address, strrpos($address, ':') + 1);

        $router = __DIR__ . '/fixtures/router.php';
        $cmd = sprintf(
            'php -S 127.0.0.1:%d %s',
            self::$serverPort,
            escapeshellarg($router)
        );

        $pipes = [];
        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, __DIR__);

        if (!is_resource($process)) {
            self::fail('Unable to start fixture server');
        }

        self::$serverProcess = $process;
        self::$serverPipes = $pipes;
        self::$baseUrl = 'http://127.0.0.1:' . self::$serverPort;

        // Wait for the server to accept connections.
        $deadline = microtime(true) + 5.0;
        $connected = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                $connected = true;
                break;
            }
            usleep(50000);
        }

        if (!$connected) {
            self::killServer();
            self::fail('Fixture server did not start in time');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::killServer();
        parent::tearDownAfterClass();
    }

    private static function killServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

    private function client(array $config = []): Psr18Client
    {
        return new Psr18Client($config);
    }

    public function test_send_request_returns_psr7_response(): void
    {
        $response = $this->client()->get(self::$baseUrl . '/echo');

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('GET', $payload['method']);
        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_client_implements_psr18_interface(): void
    {
        $this->assertInstanceOf(ClientInterface::class, $this->client());
    }

    public function test_send_request_preserves_method_and_uri(): void
    {
        $request = (new HttpFactory())->createRequest('DELETE', self::$baseUrl . '/echo');
        $response = $this->client()->sendRequest($request);

        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('DELETE', $payload['method']);
        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_send_request_sends_headers(): void
    {
        $request = (new HttpFactory())
            ->createRequest('GET', self::$baseUrl . '/echo')
            ->withHeader('X-Custom-Header', 'custom-value');

        $response = $this->client()->sendRequest($request);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('custom-value', $payload['headers']['x-custom-header']);
    }

    public function test_send_request_sends_body(): void
    {
        $request = (new HttpFactory())
            ->createRequest('POST', self::$baseUrl . '/echo')
            ->withBody(Stream::fromString('raw body content'));

        $response = $this->client()->sendRequest($request);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('raw body content', $payload['body']);
    }

    public function test_large_stream_body_streams_via_readfunction(): void
    {
        // Large bodies stream via READFUNCTION without full buffering.
        $large = str_repeat('abcdefgh', 200_000); // 1.6 MiB
        $request = (new HttpFactory())
            ->createRequest('PUT', self::$baseUrl . '/echo')
            ->withBody(Stream::fromString($large));

        $response = $this->client()->sendRequest($request);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($large, $payload['body']);
    }

    public function test_get_with_query_params(): void
    {
        $response = $this->client()->get(self::$baseUrl . '/echo', ['page' => 2, 'sort' => 'asc']);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('page=2&sort=asc', $payload['query']);
    }

    public function test_post_json_body_sets_content_type(): void
    {
        $response = $this->client()->post(self::$baseUrl . '/echo', ['name' => 'Jane', 'age' => 30]);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('application/json', $payload['headers']['content-type']);
        $this->assertSame('{"name":"Jane","age":30}', $payload['body']);
    }

    public function test_post_raw_string_body(): void
    {
        $response = $this->client()->post(self::$baseUrl . '/echo', 'plain string body');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('plain string body', $payload['body']);
        // cURL auto-sets application/x-www-form-urlencoded for raw POST bodies —
        // the important assertion is that JSON content-type was NOT set.
        $this->assertNotSame('application/json', $payload['headers']['content-type'] ?? null);
    }

    public function test_post_large_streamed_body_round_trips(): void
    {
        // All bodies stream via READFUNCTION. INFILESIZE (from getSize())
        // ensures Content-Length is sent so the PHP server can read
        // php://input — without it libcurl would send Transfer-Encoding:
        // chunked, which the server cannot parse.
        $big = str_repeat('x', (2 * 1024 * 1024) + 1); // > 2 MiB
        $response = $this->client()->post(self::$baseUrl . '/echo', Stream::fromString($big));
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame($big, $payload['body']);
        // Content-Length must be sent (not chunked) for the server to read it.
        $this->assertSame((string) strlen($big), $payload['headers']['content-length']);
        $this->assertArrayNotHasKey('transfer-encoding', $payload['headers']);
    }

    public function test_post_unknown_size_stream_body_sends_body(): void
    {
        // IteratorStream has an unknown size (getSize() === null) — the body
        // must still be sent via READFUNCTION, falling back to chunked.
        $iterator = new \ArrayIterator(['it', 'er', 'ator', '-', 'body']);
        $stream = new \Lucent\Http\Message\Stream\IteratorStream($iterator);

        $response = $this->client()->post(self::$baseUrl . '/echo', $stream);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('iterator-body', $payload['body']);
        // Unknown size → chunked transfer-encoding (no Content-Length).
        $this->assertSame('chunked', $payload['headers']['transfer-encoding'] ?? null);
        $this->assertArrayNotHasKey('content-length', $payload['headers']);
    }

    public function test_put_patch_delete_methods(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $this->client()->{$method === 'PUT' ? 'put' : ($method === 'PATCH' ? 'patch' : 'delete')}(
                self::$baseUrl . '/echo',
                ['data' => $method]
            );
            $payload = json_decode((string) $response->getBody(), true);

            $this->assertSame($method, $payload['method']);
            $this->assertSame('{"data":"' . $method . '"}', $payload['body']);
        }
    }

    public function test_head_method(): void
    {
        $response = $this->client()->head(self::$baseUrl . '/echo');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_base_uri_prepended(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $response = $client->get('/echo');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_base_uri_resolves_dot_segments(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl . '/v1/']);

        // /v1/../echo resolves to /echo — the server 404s on /v1/../echo.
        $response = $client->get('../echo');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_absolute_request_uri_overrides_base(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $response = $client->get(self::$baseUrl . '/echo');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('/echo', $payload['uri']);
    }

    public function test_basic_auth_config(): void
    {
        $client = $this->client([
            'base_uri' => self::$baseUrl,
            'basic_auth' => ['user', 'pass'],
        ]);

        $response = $client->get('/auth/user/pass');
        $this->assertSame(200, $response->getStatusCode());

        $bad = $this->client(['basic_auth' => ['user', 'wrong']]);
        $response = $bad->get(self::$baseUrl . '/auth/user/pass');
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_custom_user_agent(): void
    {
        $client = $this->client([
            'base_uri' => self::$baseUrl,
            'user_agent' => 'MyTestApp/2.0',
        ]);

        $response = $client->get('/echo');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('MyTestApp/2.0', $payload['headers']['user-agent']);
    }

    public function test_default_headers_merged(): void
    {
        $client = $this->client([
            'base_uri' => self::$baseUrl,
            'headers' => ['X-Default' => 'default-value'],
        ]);

        $response = $client->get('/echo');
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('default-value', $payload['headers']['x-default']);
    }

    public function test_request_headers_override_defaults(): void
    {
        $client = $this->client([
            'base_uri' => self::$baseUrl,
            'headers' => ['X-Test' => 'default'],
        ]);

        $response = $client->get('/echo', [], ['headers' => ['X-Test' => 'override']]);
        $payload = json_decode((string) $response->getBody(), true);

        $this->assertSame('override', $payload['headers']['x-test']);
    }

    public function test_sink_to_file_path(): void
    {
        $sinkFile = tempnam(sys_get_temp_dir(), 'lucent_sink_');

        try {
            $client = $this->client(['base_uri' => self::$baseUrl]);

            $response = $client->get('/echo', [], ['sink' => $sinkFile]);

            $this->assertSame(200, $response->getStatusCode());
            $written = file_get_contents($sinkFile);
            $payload = json_decode($written, true);

            $this->assertSame('/echo', $payload['uri']);
            $this->assertSame('/echo', json_decode((string) $response->getBody(), true)['uri']);
        } finally {
            @unlink($sinkFile);
        }
    }

    public function test_sink_to_stream_interface(): void
    {
        // php://temp is a writable, seekable stream (unlike string-backed streams).
        $resource = fopen('php://temp', 'w+');
        $this->assertNotFalse($resource);
        $stream = Stream::fromResource($resource);

        $client = $this->client(['base_uri' => self::$baseUrl]);

        $response = $client->get('/echo', [], ['sink' => $stream]);

        $stream->rewind();
        $payload = json_decode((string) $stream, true);

        $this->assertSame('/echo', $payload['uri']);
        $this->assertSame('/echo', json_decode((string) $response->getBody(), true)['uri']);
    }

    public function test_network_exception_on_connection_refused(): void
    {
        // Find a closed port.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $request = (new HttpFactory())->createRequest('GET', "http://127.0.0.1:{$port}/");

        $this->expectException(NetworkException::class);
        $this->client()->sendRequest($request);
    }

    public function test_network_exception_holds_original_request(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $request = (new HttpFactory())->createRequest('GET', "http://127.0.0.1:{$port}/");

        try {
            $this->client()->sendRequest($request);
            $this->fail('Expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertSame($request, $e->getRequest());
        }
    }

    public function test_sink_option_rejects_conflicting_curl_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['curl_options' => [CURLOPT_RETURNTRANSFER => true]]);
    }

    public function test_per_request_curl_rejects_conflicting_options(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('/echo', [], ['curl' => [CURLOPT_WRITEFUNCTION => 'foo']]);
    }

    public function test_curl_rejects_progress_options(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        // XFERINFOFUNCTION conflicts with the progress option.
        $this->expectException(\InvalidArgumentException::class);
        $client->get('/echo', [], ['curl' => [CURLOPT_XFERINFOFUNCTION => 'foo']]);
    }

    public function test_curl_rejects_body_options(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        // READFUNCTION conflicts with the client's body streaming.
        $this->expectException(\InvalidArgumentException::class);
        $client->get('/echo', [], ['curl' => [CURLOPT_READFUNCTION => 'foo']]);
    }

    public function test_config_rejects_progress_options(): void
    {
        // NOPROGRESS is managed by the progress option.
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['curl_options' => [CURLOPT_NOPROGRESS => false]]);
    }

    public function test_unknown_request_option_throws(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('/echo', [], ['bogus_option' => true]);
    }

    public function test_json_encode_failure_throws(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        // A resource cannot be JSON-encoded — a request-level failure.
        $this->expectException(\Lucent\Http\Client\Exception\RequestException::class);
        $client->post('/echo', ['bad' => fopen('php://temp', 'r')]);
    }

    public function test_progress_callback_receives_four_args(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $args = null;
        $client->get('/echo', [], [
            'progress' => function (...$received) use (&$args) {
                $args = $received;
            },
        ]);

        // Download-first: ($downloaded, $total, $uploaded, $uploadTotal).
        $this->assertIsArray($args);
        $this->assertCount(4, $args);
        $this->assertIsInt($args[0]);
        $this->assertIsInt($args[1]);
        $this->assertIsInt($args[2]);
        $this->assertIsInt($args[3]);
    }

    public function test_progress_callback_two_args_still_works(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $calls = 0;
        $response = $client->get('/echo', [], [
            'progress' => function (int $downloaded, int $total) use (&$calls) {
                $calls++;
            },
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThan(0, $calls);
    }

    public function test_immutable_config(): void
    {
        $client = $this->client(['timeout' => 5, 'verify_ssl' => false, 'base_uri' => self::$baseUrl]);

        $response = $client->get('/echo');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_unknown_config_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['baseUrl' => 'http://example.com']);
    }

    public function test_wrong_config_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['timeout' => '30']);
    }

    public function test_invalid_base_uri_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(['base_uri' => 'http://']);
    }

    public function test_stream_option_dispatches_to_stream_handler(): void
    {
        $client = $this->client(['base_uri' => self::$baseUrl]);

        $response = $client->get('/stream', [], ['stream' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('3', $response->getHeaderLine('X-Chunks'));

        // Live body — read incrementally.
        $body = $response->getBody();
        $this->assertFalse($body->isSeekable());

        $contents = '';
        while (!$body->eof()) {
            $contents .= $body->read(6);
        }
        $this->assertSame('chunk1chunk2chunk3', $contents);
    }

    public function test_custom_handler_injection(): void
    {
        $handler = new MockHandler();

        // The injected handler is used as the default transport.
        $client = new Psr18Client(
            ['base_uri' => self::$baseUrl],
            $handler
        );

        $client->get('/echo');

        $this->assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $handler->request);
        $this->assertSame('/echo', $handler->request->getUri()->getPath());
    }

    public function test_handler_receives_merged_defaults(): void
    {
        $handler = new MockHandler();

        $client = new Psr18Client([
            'base_uri' => self::$baseUrl,
            'timeout' => 7,
            'verify_ssl' => false,
            'user_agent' => 'TestAgent/1.0',
            'headers' => ['X-Default' => 'default-value'],
            'curl_options' => [CURLOPT_FRESH_CONNECT => true],
        ], $handler);

        $client->get('/echo', [], ['headers' => ['X-Request' => 'request-value']]);

        $this->assertSame(7, $handler->options['timeout']);
        $this->assertFalse($handler->options['verify_ssl']);
        $this->assertSame('TestAgent/1.0', $handler->options['user_agent']);

        // Per-request headers are deep-merged over config defaults.
        $this->assertSame('default-value', $handler->options['headers']['X-Default']);
        $this->assertSame('request-value', $handler->options['headers']['X-Request']);

        // Config curl options are merged into the per-request curl array.
        $this->assertTrue($handler->options['curl'][CURLOPT_FRESH_CONNECT]);
    }

    public function test_stream_path_skips_config_curl_defaults(): void
    {
        $handler = new MockHandler();

        $client = new Psr18Client([
            'base_uri' => self::$baseUrl,
            'curl_options' => [CURLOPT_FRESH_CONNECT => true],
        ], new CurlHandler(), $handler);

        $client->get('/stream', [], ['stream' => true]);

        // The stream handler must not receive config curl defaults, or it
        // would reject a streaming request the user never configured with curl.
        $this->assertSame([], $handler->options['curl']);
    }

    public function test_handler_validate_options_runs(): void
    {
        $handler = new MockHandler();
        $handler->reject = ['progress' => 'not-a-callable'];

        $client = new Psr18Client(['base_uri' => self::$baseUrl], $handler);

        $this->expectException(\InvalidArgumentException::class);
        $client->get('/echo', [], ['progress' => 'not-a-callable']);
    }
}
