<?php

namespace Unit;

use Lucent\Facades\App;
use Lucent\Facades\Log;
use PHPUnit\Framework\TestCase;

class RouteGroupTest extends TestCase
{

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::generateTestRestController();
        self::generateSecondRestController();
        self::generateRoutesFile();

        // Register routes
        App::registerRoutes("/routes/web.php");
    }

    public function test_404(): void
    {

        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/asdasdsaasdasdas";

        try {
            $response = (array)json_decode(App::execute());

            if($response == null || !isset($response)){
                $this->fail("Response is null or undefined.");
            }

        }catch (\Exception $e){
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

            if($decodedResponse === null) {
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

            if($decodedResponse === null) {
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

            if($decodedResponse === null) {
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

            if($decodedResponse === null) {
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

            if($decodedResponse === null) {
                $this->fail("Failed to decode JSON response: " . json_last_error_msg());
            }

            $this->assertTrue($decodedResponse["outcome"]);
            $this->assertEquals(200, $decodedResponse["status"]);
            $this->assertEquals("Hello from five", $decodedResponse["message"]);

        } catch (\Exception $e) {
            $this->fail("Test failed with exception: " . $e->getMessage());
        }
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


        $appPath = TEMP_ROOT. "app";
        $controllerPath = $appPath . DIRECTORY_SEPARATOR . "controllers";

        if (!is_dir($controllerPath)) {
            mkdir($controllerPath, 0755, true);
        }

        file_put_contents(
            $controllerPath.DIRECTORY_SEPARATOR.'RouteGroupRpcTestingController.php',
            $controllerContent
        );
    }

    private static function generateRoutesFile(): void
    {
        $routesContent = <<<'PHP'
        <?php
            use App\Controllers\RouteGroupTestingController;
                use App\Controllers\SecondRestController;
            use Lucent\Facades\Route;
        
            Route::rest()->group("rest_test")
                ->prefix("/test")
                ->defaultController(RouteGroupTestingController::class)
                ->get(path: "/one/{input}",method:"one")
                ->post(path:"/two",method: "two")
                ->get(path: "/three",method:"three")
                ->get(path: "/four",method:"test",controller: TestControllerAbc::class)
                ->get(path: "/five",method:"test",controller: SecondRestController::class);


        PHP;

        $routesPath = rtrim(TEMP_ROOT, DIRECTORY_SEPARATOR) . '/routes';

        if (!is_dir($routesPath)) {
            mkdir($routesPath, 0755, true);
        }

        file_put_contents($routesPath . '/web.php', $routesContent);

    }
}
