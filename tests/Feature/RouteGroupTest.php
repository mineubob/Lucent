<?php

namespace Tests\Feature;

use App\Models\SluggedModel;
use App\Models\TestUser;
use App\Models\TestUserTwo;
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
            'Middleware' => ['AuthMiddleware.php', 'JaneDoeScope.php'],
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
        self::setupDatabase($driver, $config, [TestUser::class]);

        $response = $this->get('/user/99');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $decodedResponse["status"]);
        $this->assertEquals(99, $decodedResponse["content"]["id"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_resolves_by_primary_key($driver, $config): void
    {
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        $user = new TestUser("john@doe.com", "password", "John Doe");
        $user->save();

        $response = $this->get('/user/object/1');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_returns_404_when_not_found($driver, $config): void
    {
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        $response = $this->get('/user/object/100');

        $this->assertEquals(404, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals(404, $decodedResponse["status"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_resolves_by_non_pk_column($driver, $config): void
    {
        FixtureLoader::copyModel('SluggedModel.php');
        self::setupDatabase($driver, $config, [SluggedModel::class]);

        $model = new SluggedModel("hello-world", "Hello World");
        $model->save();

        $response = $this->get('/user/slug/hello-world');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("Hello World", $decodedResponse["content"]["name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_scope_filters_to_404($driver, $config): void
    {
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        // The scope only matches full_name = 'Jane Doe'; the stored row is
        // 'John Doe', so the scoped lookup must 404 even though the PK exists.
        $user = new TestUser("john@doe.com", "password", "John Doe");
        $user->save();

        $response = $this->get('/user/scoped/1');

        $this->assertEquals(404, $response->getStatusCode());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_scope_passes_when_matching($driver, $config): void
    {
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        $user = new TestUser("jane@doe.com", "password", "Jane Doe");
        $user->save();

        $response = $this->get('/user/scoped/1');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("Jane Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_scope_accepts_class_method_array($driver, $config): void
    {
        // [Class::class, 'method'] arrays of constants are legal attribute
        // arguments — the second supported callable form.
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        $user = new TestUser("jane@doe.com", "password", "Jane Doe");
        $user->save();

        $response = $this->get('/user/scoped-array/1');

        $this->assertEquals(200, $response->getStatusCode());
        $decodedResponse = json_decode((string) $response->getBody(), true);

        $this->assertEquals("Jane Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_no_bind_attribute_means_no_auto_binding($driver, $config): void
    {
        // Regression test: a Model type-hint WITHOUT #[Bind] is never
        // auto-resolved from the URL (the IDOR fix). The container cannot
        // resolve TestUser from the route variable, so the response must
        // not contain the user's name.
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        $user = new TestUser("john@doe.com", "password", "John Doe");
        $user->save();

        $response = $this->get('/user/unbound/1');
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('John Doe', $body);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_bind_with_middleware($driver, $config): void
    {
        FixtureLoader::copyModel('TestUser.php');
        self::setupDatabase($driver, $config, [TestUser::class]);

        $user = new TestUser("john@doe.com", "password", "John Doe");
        $user->save();

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
