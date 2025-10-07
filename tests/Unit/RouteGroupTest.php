<?php

namespace Unit;

use App\Models\TestUser;
use Lucent\Application;
use Lucent\Database\Dataset;
use Lucent\Facades\App;
use Lucent\Facades\CommandLine;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;


// Manually require the DatabaseDriverSetup file
$driverSetupPath = __DIR__ . '/DatabaseDriverSetup.php';

if (file_exists($driverSetupPath)) {
    require_once $driverSetupPath;
} else {
    // Fallback path if the normal path doesn't work
    require_once dirname(__DIR__, 1) . '/Unit/DatabaseDriverSetup.php';
}

require_once __DIR__ . '/ModelTest.php';

class RouteGroupTest extends DatabaseDriverSetup
{

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function databaseDriverProvider(): array
    {
        return [
            'sqlite' => [
                'sqlite',
                [
                    'DB_DATABASE' => '/storage/database.sqlite'
                ]
            ],
            'mysql' => [
                'mysql',
                [
                    'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
                    'DB_PORT' => getenv('DB_PORT') ?: '3306',
                    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test_database',
                    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''
                ]
            ]
        ];
    }


    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Register routes
        Application::reset();

        self::generateTestRestController();
        self::generateSecondRestController();
        self::generate_test_user_controller();
        self::generate_test_middleware();

        self::generateRoutesFile();
        App::registerRoutes("/routes/web.php");
    }

    public function test_404(): void
    {

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/asdasdsaasdasdas";

        try {
            $response = (array) json_decode(App::execute());

            if ($response == null || !isset($response)) {
                $this->fail("Response is null or undefined.");
            }

        } catch (\Exception $e) {
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
            Log::channel('phpunit')->info("Starting test with URI: /test/three");

            $response = App::execute();
            Log::channel('phpunit')->info("Raw response: " . $response);

            $decodedResponse = json_decode($response, true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            Log::channel('phpunit')->info("Decoded response: " . json_encode($decodedResponse));

            $this->assertFalse($decodedResponse["outcome"]);
            $this->assertEquals(500, $decodedResponse["status"]);

        } catch (\Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    public function test_500_invalid_controller(): void
    {

        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/four";

        try {
            Log::channel('phpunit')->info("Starting test with URI: /test/three");

            $response = App::execute();
            Log::channel('phpunit')->info("Raw response: " . $response);

            $decodedResponse = json_decode($response, true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            Log::channel('phpunit')->info("Decoded response: " . json_encode($decodedResponse));

            $this->assertFalse($decodedResponse["outcome"]);
            $this->assertEquals(500, $decodedResponse["status"]);

        } catch (\Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    public function test_route_group(): void
    {

        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/one/ping";

        try {
            $response = App::execute();
            $decodedResponse = json_decode($response, true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("pong", $decodedResponse["message"]);

        } catch (\Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }

        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["REQUEST_URI"] = "/test/two";

        try {
            $response = App::execute();
            $decodedResponse = json_decode($response, true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("Hello from test 2", $decodedResponse["message"]);

        } catch (\Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    public function test_route_group_with_none_default_controller(): void
    {
        // Set up server environment for testing
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/test/five";

        try {
            $response = App::execute();

            $decodedResponse = json_decode($response, true);

            if ($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("Hello from five", $decodedResponse["message"]);

        } catch (\Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_model_id_raw($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        $this->perform_model_migration($driver, $config);

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user/99";

        $response = App::execute();

        $decodedResponse = json_decode($response, true);

        $this->assertEquals(200, $decodedResponse["status"]);
        $this->assertEquals(99, $decodedResponse["content"]["id"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_by_id($driver, $config): void
    {

        self::setupDatabase($driver, $config);
        $this->perform_model_migration($driver, $config);

        $user = new TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user/object/1";

        $response = App::execute();

        $decodedResponse = json_decode($response, true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_by_id_not_found($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        $this->perform_model_migration($driver, $config);

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user/object/100";

        $response = App::execute();

        $decodedResponse = json_decode($response, true);

        $this->assertEquals(404, $decodedResponse["status"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_route_get_user_model_with_middleware($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        $this->perform_model_migration($driver, $config);

        $user = new TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/user2/object/1";

        $response = App::execute();

        $decodedResponse = json_decode($response, true);

        $this->assertEquals("John Doe", $decodedResponse["content"]["full_name"]);
    }

    #[DataProvider('databaseDriverProvider')]
    public function perform_model_migration($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        ModelTest::generate_test_model();

        $output = CommandLine::execute("Migration make App/Models/TestUser");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    public static function generateTestRestController(): void
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\Http\JsonResponse;

        class RouteGroupTestingController
        {
           
            public function one($input) : JsonResponse
            {
            
                 $response = new JsonResponse();
                 
                 if($input === "ping"){
                     $response->setMessage("pong");
                 }else{
                     $response->setOutcome(false);
                     $response->setStatusCode(400);
                     $response->setMessage("Message not passed as url parameter.");
                 }                 
                 return $response;
            }
        
          
            public function two() : JsonResponse
            {
            $response = new JsonResponse();
                 
                 $response->setMessage("Hello from test 2");
                 
                 return $response;
            }
        }
        PHP;


        $appPath = rtrim(TEMP_ROOT, DIRECTORY_SEPARATOR) . '/App';
        $controllerPath = $appPath . '/Controllers';

        if (!is_dir($controllerPath)) {
            mkdir($controllerPath, 0755, true);
        }

        file_put_contents(
            $controllerPath . '/RouteGroupTestingController.php',
            $controllerContent
        );
    }

    public static function generateSecondRestController(): void
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\Http\JsonResponse;

        class SecondRestController
        {
            public function test() : JsonResponse
            {
                $response = new JsonResponse();
                 
                 $response->setMessage("Hello from five");
                 
                 return $response;
            }
        }
        PHP;

        $appPath = rtrim(TEMP_ROOT, DIRECTORY_SEPARATOR) . '/App';
        $controllerPath = $appPath . '/Controllers';

        if (!is_dir($controllerPath)) {
            mkdir($controllerPath, 0755, true);
        }

        file_put_contents(
            $controllerPath . '/SecondRestController.php',
            $controllerContent
        );
    }

    public static function generateTestRpcController(): void
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\Http\JsonResponse;

        class RouteGroupRpcTestingController
        {
           
            public function one($input) : JsonResponse
            {
                 $response = new JsonResponse();
                 
                 if($input === "ping"){
                     $response->setMessage("pong");
                 }else{
                     $response->setOutcome(false);
                     $response->setStatusCode(400);
                     $response->setMessage("Message not passed as url parameter.");
                 }                 
                 return $response;
            }
        
          
            public function two() : JsonResponse
            {
            $response = new JsonResponse();
                 
                 $response->setMessage("Hello from test 2");
                 
                 return $response;
            }
        }
        PHP;


        $appPath = TEMP_ROOT . "app";
        $controllerPath = $appPath . DIRECTORY_SEPARATOR . "controllers";

        if (!is_dir($controllerPath)) {
            mkdir($controllerPath, 0755, true);
        }

        file_put_contents(
            $controllerPath . DIRECTORY_SEPARATOR . 'RouteGroupRpcTestingController.php',
            $controllerContent
        );
    }

    private static function generate_test_user_controller(): void
    {
        $modelContent = <<<'PHP'
        <?php
        
        namespace App\Controllers;
        
        use Lucent\Http\JsonResponse;
        use App\Models\TestUser;
        
        class UserController
        {
            public function getById($id) : JsonResponse
            {
                $response = new JsonResponse();
                $response->addContent("id",$id);
                
                return $response;
            }
            
            public function getModelById(TestUser $user) : JsonResponse
            {
                $response = new JsonResponse();
                $response->addContent("full_name",$user->getFullName());
                
                return $response;
            }

        }
        PHP;


        $appPath = FileSystem::rootPath() . "/App";
        $modelPath = $appPath . DIRECTORY_SEPARATOR . "Controllers";

        if (!is_dir($modelPath)) {
            mkdir($modelPath, 0755, true);
        }

        file_put_contents(
            $modelPath . DIRECTORY_SEPARATOR . 'UserController.php',
            $modelContent
        );

    }

    private static function generate_test_middleware(): void
    {
        $middlewareContent = <<<'PHP'
        <?php
        
        namespace App\Middleware;
        
        use Lucent\Http\JsonResponse;
        use App\Models\TestUser;
        use Lucent\Middleware;
        use Lucent\Http\Request;
        
        class AuthMiddleware extends Middleware
        {
             public function handle(Request $request): Request
            {
                if($request->getUrlVariable("user") === "1"){
                    $request->context["user"] = TestUser::where("id",1)->getFirst();
                }
        
                return $request;
            }

        }
        PHP;


        $appPath = FileSystem::rootPath() . "/App";
        $middlewarePath = $appPath . DIRECTORY_SEPARATOR . "Middleware";

        if (!is_dir($middlewarePath)) {
            mkdir($middlewarePath, 0755, true);
        }

        file_put_contents(
            $middlewarePath . DIRECTORY_SEPARATOR . 'AuthMiddleware.php',
            $middlewareContent
        );

    }

    private static function generateRoutesFile(): void
    {

        $routesContent = <<<'PHP'
        <?php
            use App\Controllers\RouteGroupTestingController;
            use App\Controllers\SecondRestController;
            use App\Controllers\UserController;
            use App\Middleware\AuthMiddleware;

            use Lucent\Facades\Route;
        
            Route::rest()->group("rest_test")
                ->prefix("/test")
                ->defaultController(RouteGroupTestingController::class)
                ->get(path: "/one/{input}",method:"one")
                ->post(path:"/two",method: "two")
                ->get(path: "/three",method:"three")
                ->get(path: "/four",method:"test",controller: TestControllerAbc::class)
                ->get(path: "/five",method:"test",controller: SecondRestController::class);
                

            Route::rest()->group("user")
                ->prefix("/user")
                ->defaultController(UserController::class)
                ->get(path: "/{id}",method:"getById")
                ->get(path: "/object/{user}",method:"getModelById");
                
            Route::rest()->group("user2")
                ->prefix("/user2")
                ->defaultController(UserController::class)
                ->middleware([AuthMiddleware::class])
                ->get(path: "/object/{user}",method:"getModelById");

        PHP;

        $routesPath = rtrim(TEMP_ROOT, DIRECTORY_SEPARATOR) . '/routes';

        if (!is_dir($routesPath)) {
            mkdir($routesPath, 0755, true);
        }

        file_put_contents($routesPath . '/web.php', $routesContent);

    }
}
