<?php

namespace Tests\Feature;

use Exception;
use Lucent\Application;
use Lucent\Facades\App;
use Lucent\Http\HttpStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\Concerns\MakeRequest;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

class RouteGroupTest extends TestCase
{
    use DatabaseTesting;
    use MakeRequest;
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
        try {
            $response = (array) json_decode((string) $this->get('/asdasdsaasdasdas')->getBody());

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
        try {
            $response = $this->get('/test/three');

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
        try {
            $response = $this->get('/test/four');

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
        try {
            $response = $this->get('/test/one/ping');

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

        try {
            $response = $this->post('/test/two');

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
        try {
            $response = $this->get('/test/five');

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

        $response = $this->get('/user/99');

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

        $response = $this->get('/user/object/1');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_by_id_not_found($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $response = $this->get('/user/object/100');

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

        $response = $this->get('/user2/object/1');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    public function test_invalid_route_file(): void
    {
        // Reset so boot() runs fresh and loads the invalid route file.
        self::refreshApplication();
        App::registerRoutes("/test/123.php");
        $res = $this->get('/');

        $this->assertEquals(500, $res->getStatusCode());

        $body = json_decode((string) $res->getBody(), true);
        $this->assertArrayNotHasKey('errors', $body);
        $this->assertStringContainsString(HttpStatus::fromCode(500)->message(), (string) $res->getBody());
    }

    public function test_invalid_route_file_debug(): void
    {
        // Reset so boot() runs fresh and loads the invalid route file.
        self::refreshApplication();
        Application::getInstance()->setEnv(['DEBUG' => true]);
        App::registerRoutes("/test/123.php");
        $res = $this->get('/');

        $this->assertEquals(500, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);

        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('exception', $body['errors']);
    }

}
