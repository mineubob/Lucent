<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the bundled dev-server router (src/Lucent/Commandline/resources/server.php).
 *
 * The router is passed to PHP's built-in server by the `serve` command. It must:
 *   - serve real static files directly (return false),
 *   - forward everything else to the project's public/index.php.
 *
 * We boot the router with a disposable docroot (a temp dir containing a static
 * file and an index.php) and assert the responses, mirroring how the `serve`
 * command launches the server (CWD = docroot).
 */
class DevServerRouterTest extends TestCase
{
    /** @var string Base URL of the test server */
    private static string $baseUrl;

    /** @var resource|null The running server process */
    private static $serverProcess = null;

    /** @var string The disposable docroot */
    private static string $docRoot;

    /** @var int The port the server is bound to */
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Build a disposable docroot with a static file and an index.php.
        self::$docRoot = sys_get_temp_dir() . '/lucent_router_' . uniqid();
        mkdir(self::$docRoot, 0755, true);
        file_put_contents(self::$docRoot . '/style.css', 'body { color: red; }');
        file_put_contents(
            self::$docRoot . '/index.php',
            '<?php echo "FRAMEWORK:" . ($_SERVER["REQUEST_URI"] ?? "/");'
        );

        // Find a free port.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail("Unable to find a free port: $errstr");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int) substr($address, strrpos($address, ':') + 1);

        $router = dirname(__DIR__, 2) . '/src/Lucent/Commandline/resources/server.php';
        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s %s',
            self::$port,
            escapeshellarg(self::$docRoot),
            escapeshellarg($router)
        );

        $pipes = [];
        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes, self::$docRoot);

        if (!is_resource($process)) {
            self::fail('Unable to start dev server router test server');
        }

        self::$serverProcess = $process;
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;

        // Wait for the server to accept connections.
        $deadline = microtime(true) + 5.0;
        $connected = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                $connected = true;
                break;
            }
            usleep(50000);
        }

        if (!$connected) {
            self::killServer();
            self::fail('Dev server router test server did not start in time');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::killServer();
        if (isset(self::$docRoot) && is_dir(self::$docRoot)) {
            @unlink(self::$docRoot . '/style.css');
            @unlink(self::$docRoot . '/index.php');
            @rmdir(self::$docRoot);
        }
        parent::tearDownAfterClass();
    }

    public function test_serves_static_files_directly(): void
    {
        $body = file_get_contents(self::$baseUrl . '/style.css');
        $this->assertSame('body { color: red; }', $body);
    }

    public function test_forwards_other_requests_to_index_php(): void
    {
        $body = file_get_contents(self::$baseUrl . '/users');
        $this->assertSame('FRAMEWORK:/users', $body);
    }

    public function test_root_uri_forwards_to_index_php(): void
    {
        $body = file_get_contents(self::$baseUrl . '/');
        $this->assertSame('FRAMEWORK:/', $body);
    }

    private static function killServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }
}