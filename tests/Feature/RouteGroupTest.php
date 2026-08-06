<?php

namespace Tests\Feature;

use Exception;
use Lucent\Application;
use Lucent\Facades\App;
use Lucent\Http\HttpStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

class RouteGroupTest extends TestCase
{
    use DatabaseTesting;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Register routes
        self::refreshApplication();

        FixtureLoader::copyController('RouteGroupTestingController.php');
        FixtureLoader::copyController('SecondRestController.php');
        FixtureLoader::copyController('UserController.php');
        FixtureLoader::copyMiddleware('AuthMiddleware.php');

        FixtureLoader::copyRoutes('web.php');
        Application::getInstance()->boot();
    }

    public function test_404(): void
    {

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/asdasdsaasdasdas";

        try {
            $response = (array) json_decode((string) App::handleHttpRequest()->getBody());

            if ($response == null || !isset($response)) {
                $this->fail("Response is null or undefined.");
            }
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }

        $this->assertFalse($response["outcome"]);
        $this->assertTrue($response["status"] === 404);
    }

    public function test_500_invalid_controller_method(): void
    {

        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/three";

        try {
            $response = App::handleHttpRequest();

            $this->assertEquals(500, $response->getStatusCode());
            $decodedResponse = json_decode((string) $response->getBody(), true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertFalse($decodedResponse["outcome"]);
            $this->assertEquals(500, $decodedResponse["status"]);
        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    public function test_500_invalid_controller(): void
    {

        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/four";

        try {
            $response = App::handleHttpRequest();

            $this->assertEquals(500, $response->getStatusCode());
            $decodedResponse = json_decode((string) $response->getBody(), true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertFalse($decodedResponse["outcome"]);
            $this->assertEquals(500, $decodedResponse["status"]);
        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    public function test_route_group(): void
    {

        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/one/ping";

        try {
            $response = App::handleHttpRequest();

            $this->assertEquals(200, $response->getStatusCode());
            $decodedResponse = json_decode((string) $response->getBody(), true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("pong", $decodedResponse["message"]);
        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }

        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["REQUEST_URI"] = "/test/two";

        try {
            $response = App::handleHttpRequest();

            $this->assertEquals(200, $response->getStatusCode());
            $decodedResponse = json_decode((string) $response->getBody(), true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("Hello from test 2", $decodedResponse["message"]);
        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    public function test_route_group_with_none_default_controller(): void
    {
        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/five";

        try {
            $response = App::handleHttpRequest();

            $this->assertEquals(200, $response->getStatusCode());
            $decodedResponse = json_decode((string) $response->getBody(), true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("Hello from five", $decodedResponse["message"]);
        } catch (Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_model_id_raw($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user/99";

        $response = App::handleHttpRequest();

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $decodedResponse["status"]);
        $this->assertEquals(99, $decodedResponse["content"]["id"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_by_id($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");

        $this->assertTrue($user->create());

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user/object/1";

        $response = App::handleHttpRequest();

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_by_id_not_found($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user/object/100";

        $response = App::handleHttpRequest();

        $this->assertEquals(404, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals(404, $decodedResponse["status"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_with_middleware($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");

        $this->assertTrue($user->create());

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user2/object/1";

        $response = App::handleHttpRequest();

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    public function test_invalid_route_file(): void
    {
        // Reset so boot() runs fresh and loads the invalid route file.
        self::refreshApplication();
        App::registerRoutes("/test/123.php");
        $res = App::handleHttpRequest();

        $this->assertEquals(500, $res->getStatusCode());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertArrayNotHasKey('errors', $body);
        $this->assertStringContainsString(HttpStatus::fromCode(500)->message(), (string) $res->getBody());
    }

    public function test_invalid_route_file_debug(): void
    {
        // Reset so boot() runs fresh and loads the invalid route file.
        self::refreshApplication();
        Application::getInstance()->setEnv("debug", true);
        App::registerRoutes("/test/123.php");
        $res = App::handleHttpRequest();

        $this->assertEquals(500, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);

        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('exception', $body['errors']);
    }

}
