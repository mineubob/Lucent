<?php

namespace Unit;

use App\Services\PingPongService;
use Lucent\Application;
use Lucent\Facades\App;
use Lucent\Filesystem\File;
use PHPUnit\Framework\TestCase;

class DependencyInjectionTest extends TestCase
{
    // public static function setUpBeforeClass(): void
    // {
    //     parent::setUpBeforeClass();

    //     Application::reset();

    //     If(!self::generateRoutesWebFile()->exists()){
    //         throw new \Exception('Unable to generate routes web file');
    //     }

    //     App::registerRoutes("/routes/DIWeb.php");
    // }

    // public function test_service_registration() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());
    //     $service = App::service()->singleton(PingPongService::class);
    //     $this->assertInstanceOf(PingPongService::class, $service);

    //     $this->assertEquals($service,Application::getInstance()->services[PingPongService::class]);
    // }

    // public function test_service_singleton() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());
    //     $service = App::service()->singleton(PingPongService::class);

    //     $this->assertEquals("pong",$service->ping());
    //     $service->setPong("Hello World!");

    //     $this->assertEquals("Hello World!",Application::getInstance()->services[PingPongService::class]->ping());

    //     //Reset our singleton
    //     App::service()->singleton(PingPongService::class);
    // }

    // public function test_controller_with_no_constructor() : void
    // {
    //     $this->assertTrue($this->generateControllerWithNoConstructor()->exists());

    //     $_SERVER["REQUEST_METHOD"] = "GET";
    //     $_SERVER["REQUEST_URI"] = "/di/test1";


    //     $response = (array)json_decode(App::execute());

    //     $this->assertEquals("pong", $response["message"]);
    // }

    // public function test_controller_with_empty_constructor() : void
    // {
    //     $this->assertTrue($this->generateControllerWithEmptyConstructor()->exists());

    //     $_SERVER["REQUEST_METHOD"] = "GET";
    //     $_SERVER["REQUEST_URI"] = "/di/test2";


    //     $response = (array)json_decode(App::execute());

    //     $this->assertEquals("pong", $response["message"]);
    // }

    // public function test_controller_with_dependencies_in_constructor() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());
    //     $this->assertTrue($this->generateControllerWithDependenciesInConstructor()->exists());

    //     $_SERVER["REQUEST_METHOD"] = "GET";
    //     $_SERVER["REQUEST_URI"] = "/di/test3";


    //     $response = (array)json_decode(App::execute());

    //     $this->assertEquals("pong", $response["message"]);
    // }

    // public function test_controller_with_dependencies_in_method() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());
    //     $this->assertTrue($this->generateControllerWithDependenciesInMethod()->exists());
    //     $service = App::service()->singleton(PingPongService::class);
    //     $this->assertInstanceOf(PingPongService::class, $service);

    //     $_SERVER["REQUEST_METHOD"] = "GET";
    //     $_SERVER["REQUEST_URI"] = "/di/test4";


    //     $response = (array)json_decode(App::execute());

    //     $this->assertEquals("pong", $response["message"]);
    // }

    // public function test_controller_with_dependencies_in_method_with_request() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());
    //     $this->assertTrue($this->generateControllerWithDependenciesInMethod()->exists());
    //     $service = App::service()->singleton(PingPongService::class);
    //     $this->assertInstanceOf(PingPongService::class, $service);

    //     $_SERVER["REQUEST_METHOD"] = "GET";
    //     $_SERVER["REQUEST_URI"] = "/di/test5";


    //     $response = (array)json_decode(App::execute());

    //     $this->assertEquals("pong", $response["message"]);
    // }

    // public function test_service_set_with_class() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());

    //     $test = new PingPongService();
    //     App::service()->instance($test);

    //     $this->assertTrue(App::service()->has($test::class));
    //     $this->assertEquals($test,App::service()->get($test::class));
    // }

    // public function test_service_set_with_alias() : void
    // {
    //     $this->assertTrue($this->generatePingPongService()->exists());
    //     $test = new PingPongService();
    //     App::service()->instance($test,"my-service");
    //     $this->assertTrue(App::service()->has("my-service"));
    //     $this->assertEquals($test,App::service()->get("my-service"));
    // }

    // private function generateControllerWithNoConstructor() : File
    // {

    //     $controllerContent = <<<'PHP'
    //     <?php
    //     namespace App\Controllers;
        
    //     use Lucent\Http\JsonResponse;

    //     class DependencyInjectionControllerWithNoConstructor
    //     {
           
    //         public function ping() : JsonResponse
    //         {
            
    //              $response = new JsonResponse();
                 
    //              $response->setMessage("pong");
    //              $response->setStatusCode(200);
                 
    //              return $response;
    //         }
        
          
    //     }
    //     PHP;

    //     return new File("/App/Controllers/DependencyInjectionControllerWithNoConstructor.php",$controllerContent);
    // }

    // private function generateControllerWithEmptyConstructor() : File
    // {

    //     $controllerContent = <<<'PHP'
    //     <?php
    //     namespace App\Controllers;
        
    //     use Lucent\Http\JsonResponse;

    //     class DependencyInjectionControllerWithEmptyConstructor
    //     {
        
    //         private string $message;
        
    //         public function __construct(){
    //                $this->message = "pong";
    //         }
           
    //         public function ping() : JsonResponse
    //         {
            
    //              $response = new JsonResponse();
                 
    //              $response->setMessage($this->message);
    //              $response->setStatusCode(200);
                 
    //              return $response;
    //         }
        
          
    //     }
    //     PHP;

    //     return new File("/App/Controllers/DependencyInjectionControllerWithEmptyConstructor.php",$controllerContent);
    // }

    // private function generateControllerWithDependenciesInConstructor() : File
    // {

    //     $controllerContent = <<<'PHP'
    //     <?php
    //     namespace App\Controllers;
        
    //     use Lucent\Http\JsonResponse;
    //     use App\Services\PingPongService;

    //     class DependencyInjectionControllerWithDependenciesInConstructor
    //     {
        
    //         private PingPongService $service;
        
    //         public function __construct(PingPongService $service){
    //                $this->service = $service;
    //         }
           
    //         public function ping() : JsonResponse
    //         {
            
    //              $response = new JsonResponse();
                 
    //              $response->setMessage($this->service->ping());
    //              $response->setStatusCode(200);
                 
    //              return $response;
    //         }
        
          
    //     }
    //     PHP;

    //     return new File("/App/Controllers/DependencyInjectionControllerWithDependenciesInConstructor.php",$controllerContent);
    // }

    // private function generateControllerWithDependenciesInMethod() : File
    // {

    //     $controllerContent = <<<'PHP'
    //     <?php
    //     namespace App\Controllers;
        
    //     use Lucent\Http\JsonResponse;
    //     use App\Services\PingPongService;use Lucent\Http\Request;

    //     class DependencyInjectionControllerWithDependenciesInMethod
    //     {
        
          
    //         public function ping(PingPongService $service) : JsonResponse
    //         {
            
    //              $response = new JsonResponse();
                 
    //              $response->setMessage($service->ping());
    //              $response->setStatusCode(200);
                 
    //              return $response;
    //         }
            
            
    //         public function ping2(Request $request,PingPongService $service) : JsonResponse
    //         {
           
    //              $response = new JsonResponse();
                 
    //              $response->setMessage($service->ping());
    //              $response->setStatusCode(200);
                 
    //              return $response;
    //         }
        
          
    //     }
    //     PHP;

    //     return new File("/App/Controllers/DependencyInjectionControllerWithDependenciesInMethod.php",$controllerContent);
    // }


    // private function generatePingPongService() : File
    // {

    //     $controllerContent = <<<'PHP'
    //     <?php
    //     namespace App\Services;
        
    //     class PingPongService
    //     {
                
    //         private string $pong = "pong";
          
           
    //         public function ping() : string
    //         {
            
    //              return $this->pong;
    //         }
            
    //         public function setPong(string $pong) : void
    //         {
    //             $this->pong = $pong;
    //         }
          
    //     }
    //     PHP;

    //     return new File("/App/Services/PingPongService.php",$controllerContent);
    // }

    // private static function generateRoutesWebFile() : File{
    //     $routesContent = <<<'PHP'
    //     <?php
         
    //         use Lucent\Facades\Route;
        
    //         Route::rest()->group("Dependency Injection")
    //             ->prefix("/di")
    //             ->get(path: "/test1",method:"ping",controller: \App\Controllers\DependencyInjectionControllerWithNoConstructor::class)
    //             ->get(path:"/test2",method: "ping", controller: \App\Controllers\DependencyInjectionControllerWithEmptyConstructor::class)
    //             ->get(path: "/test3",method:"ping",controller: \App\Controllers\DependencyInjectionControllerWithDependenciesInConstructor::class)
    //             ->get(path: "/test4",method:"ping",controller: \App\Controllers\DependencyInjectionControllerWithDependenciesInMethod::class)
    //             ->get(path: "/test5",method:"ping2",controller: \App\Controllers\DependencyInjectionControllerWithDependenciesInMethod::class);


    //     PHP;

    //     return new File("/routes/DIWeb.php",$routesContent);
    // }
}