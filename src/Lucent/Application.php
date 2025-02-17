<?php

namespace Lucent;

use Lucent\Commandline\DocumentationController;
use Lucent\Commandline\MigrationController;
use Lucent\Commandline\UpdateController;
use Lucent\Facades\CommandLine;
use Lucent\Facades\File;
use Lucent\Logging\Channel;
use Lucent\Commandline\CliRouter;
use Lucent\Http\HttpRouter;
use Lucent\Http\JsonResponse;
use Lucent\Http\Request;
use Lucent\Logging\NullChannel;
use ReflectionClass;
use ReflectionMethod;

class Application
{
    public private(set) HttpRouter $httpRouter;
    public private(set) CliRouter $consoleRouter;

    private array $routes = [];
    private array $commands = [];
    private static ?Application $instance = null;
    private array $env;
    private array $loggers = [];

    public function __construct(){

        //Create our router instance
        $this->httpRouter = new HttpRouter();
        $this->consoleRouter = new CliRouter();

        //Check if we have a .env file present, if not
        //then we create the file.
        if(!file_exists(File::rootPath().".env")){
            $file = fopen(File::rootPath().".env","w");
            fclose($file);
        }

        //Load the env file
        $this->LoadEnv();

        $this->loggers["blank"] = new NullChannel();
    }

    public function addLoggingChannel(string $key, Channel $log): void
    {
        $this->loggers[$key] = $log;
    }

    public function getLoggingChannel(string $key) : Channel
    {
        if(!array_key_exists($key, $this->loggers)){
            return $this->loggers["blank"];
        }
        return $this->loggers[$key];
    }

    public function boot(): void
    {
        foreach ($this->routes as $route){
            $this->httpRouter->loadRoutes($route["file"]);
        }

        foreach ($this->commands as $command){
            require_once File::rootPath().$command;
        }
    }

    public function getEnv(): array
    {
        return $this->env;
    }

    public static function getInstance(): Application
    {
        if(Application::$instance == null){
            Application::$instance = new Application();
        }

        return Application::$instance;
    }

    public function loadRoutes(string $route): void
    {
        $this->routes[] = ["file" => $route];
    }

    public function executeHttpRequest(): string
    {
        $this->boot();

        $response = $this->httpRouter->AnalyseRouteAndLookup($this->httpRouter->GetUriAsArray($_SERVER["REQUEST_URI"]));

        $request = new Request();

        if (!$response["outcome"]) {
            http_response_code(404);

            $response = new JsonResponse()
                ->setStatusCode(404)
                ->setOutcome(false)
                ->setMessage("Invalid API route.");

            return json_encode($response->getArray());
        }

        // Verify controller exists before trying to instantiate it
        if (!class_exists($response["controller"])) {
            http_response_code(500);

            $response = new JsonResponse()
                ->setStatusCode(500)
                ->setOutcome(false)
                ->setMessage("Controller class '" . $response["controller"] . "' not found");

            return json_encode($response->getArray());
        }

        $controller = new $response["controller"]();

        //Check if we have a valid method, if not throw a 500 error.
        if (!method_exists($controller, $response["method"])) {
            http_response_code(500);

            $response = new JsonResponse()
                ->setStatusCode(500)
                ->setOutcome(false)
                ->setMessage("Method '" . $response["method"] . "' not found in controller '" . $response["controller"] . "'");

            return json_encode($response->getArray());
        }

        //Next we check if we have any variables to pass, if not we run the method.
        //Next this as we have not returned we have variables to pass
        $reflect = new ReflectionClass($response["controller"]);
        $method = $reflect->getMethod($response["method"]);

        //Pass our URL variables to the request object.
        $request->setUrlVars($response["variables"]);

        //Run all our middleware
        foreach ($response["middleware"] as $middleware) {
            $object = new $middleware();
            $request = $object->handle($request);
        }

        //Check if we require our request object.
        $requestInjection = $this->requiresHttpRequest($method);

        if ($requestInjection !== null) {
            $response["variables"][$requestInjection] = $request;
        }

        $result = $method->invokeArgs($controller, $response["variables"]);
        http_response_code($result->getStatusCode());

        if ($method->getReturnType()->getName() == JsonResponse::class) {
            header('Content-Type: application/json; charset=utf-8');
        }

        return $result->execute();
    }

    private function requiresHttpRequest(ReflectionMethod $method): ?string
    {
        $name = null;

        foreach ($method->getParameters() as $parameter){

            if($parameter->getType() !== null){
                if($parameter->getType()->getName() === Request::class){
                    $name =  $parameter->getName();
                }
            }
        }

        return $name;
    }

    public function LoadEnv(): void
    {

        $file = fopen(File::rootPath(). ".env", "r");
        $output = [];

        if($file) {
            while(($line = fgets($file)) !== false) {
                // Skip comments and empty lines
                $line = trim($line);
                if(empty($line) || str_starts_with($line, '#')) {
                    continue;
                }

                // Find position of first equals sign
                $pos = strpos($line, '=');
                if($pos !== false) {
                    $key = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));

                    // Remove quotes if present
                    $value = trim($value, '"\'');

                    if(!empty($key)) {
                        $output[$key] = $value;
                    }
                }
            }
        }

        fclose($file);

        $this->env = $output;
    }

    public function executeConsoleCommand($args = []): string
    {

        $this->boot();

        CommandLine::register("Migration make {class}","make", MigrationController::class);
      
        CommandLine::register("update check", "check", UpdateController::class);
        CommandLine::register("update install","install", UpdateController::class);
        CommandLine::register("update rollback", "rollback", UpdateController::class);

        CommandLine::register("generate api-docs", "generateApi", DocumentationController::class);

        if($args === []){
            $args = array_slice($_SERVER["argv"],1);
            $args = str_replace("\n", "", $args);
        }

        $response = $this->consoleRouter->AnalyseRouteAndLookup($args);

        if(!$response["outcome"]){
            return "Invalid command, please try again!";
        }

        if (!class_exists($response["controller"])) {
            return "Ops! We can seem to find the class '".$response["controller"]."' please recheck your command registration.";
        }

        $controller = new $response["controller"]();

        if(!method_exists($controller,$response["method"])) {
            return "Ops! We cant seem to find the method '".$response["method"]."' inside '".$controller::class."' please recheck your command registration.";
        }

        //Next this as we have not returned we have variables to pass
        $reflect = new ReflectionClass($response["controller"]);
        $method = $reflect->getMethod($response["method"]);

        $argCount = count($method->getParameters());
        $varCount = count($response["variables"]);

        //Check our var count matches our parameter count, if not return a error.
        if($argCount !== $varCount){
            return "Ops! ".$response["controller"]."@".$method->getName()." requires ".$varCount." parameters and ".$argCount." were provided.";
        }

        return $method->invokeArgs($controller,$response["variables"]);
    }

    public function loadCommands(string $commandFile): void
    {
        array_push($this->commands, $commandFile);
    }

}