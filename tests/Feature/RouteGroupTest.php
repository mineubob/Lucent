<?php

namespace Tests\Feature;

use Exception;
use Lucent\Application;
use Lucent\Facades\App;
use Lucent\Http\HttpStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\Concerns\MakeRequest;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

class RouteGroupTest extends TestCase
{
    use CopiesFixtures;
    use DatabaseTesting;
    use MakeRequest;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Controller' => [
                'RouteGroupTestingController.php',
                'SecondRestController.php',
                'UserController.php',
            ],
            'Middleware' => 'AuthMiddleware.php',
            'Route'      => 'web.php',
        ]);

        self::refreshAndBootApplication();
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
        FixtureLoader::copyModel('TestUser.php');
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
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        // These tests exercise implicit binding, which is opt-in since the
        // default flipped to explicit (MODEL_BINDING defaults to explicit).
        Application::getInstance()->setEnv(['MODEL_BINDING' => 'implicit']);

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
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        Application::getInstance()->setEnv(['MODEL_BINDING' => 'implicit']);

        $response = $this->get('/user/object/100');

        $this->assertEquals(404, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals(404, $decodedResponse["status"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_with_middleware($driver, $config): void
    {
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        Application::getInstance()->setEnv(['MODEL_BINDING' => 'implicit']);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");

        $this->assertTrue($user->create());

        $response = $this->get('/user2/object/1');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_binding_explicit_mode_disables_auto_binding($driver, $config): void
    {
        // Regression test: explicit binding is the DEFAULT. A Model type-hint
        // is NOT auto-resolved from the URL (no unscoped PK lookup), so the
        // controller never receives an auto-bound row unless the app opts
        // back into implicit mode.
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");
        $this->assertTrue($user->create());

        $response = $this->get('/user/object/1');
        $body = (string) $response->getBody();

        // The auto-bound user must NOT be present. The container cannot
        // resolve TestUser from the route variable, so the response should
        // not contain the user's name.
        $this->assertStringNotContainsString('John Doe', $body);
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
