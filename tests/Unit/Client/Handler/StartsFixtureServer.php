<?php

namespace Unit\Client\Handler;

/**
 * Boots the shared fixture server (tests/Unit/Client/fixtures/router.php)
 * once per test class and exposes its base URL.
 */
trait StartsFixtureServer
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
            \PHPUnit\Framework\Assert::fail("Unable to find a free port: $errstr");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$serverPort = (int) substr($address, strrpos($address, ':') + 1);

        $router = __DIR__ . '/../fixtures/router.php';
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
            \PHPUnit\Framework\Assert::fail('Unable to start fixture server');
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
            \PHPUnit\Framework\Assert::fail('Fixture server did not start in time');
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
}