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

/**
 * Main Application class responsible for handling HTTP requests, console commands,
 * routing, and managing the application lifecycle.
 *
 * This class implements a singleton pattern and serves as the central
 * coordination point for the Lucent framework.
 */
class Application
{
    /**
     * HTTP router instance for handling web requests
     *
     * @var HttpRouter
     */
    public private(set) HttpRouter $httpRouter;

    /**
     * CLI router instance for handling console commands
     *
     * @var CliRouter
     */
    public private(set) CliRouter $consoleRouter;

    /**
     * Array of registered route files
     *
     * @var array
     */
    private array $routes = [];

    /**
     * Array of registered command files
     *
     * @var array
     */
    private array $commands = [];

    /**
     * Singleton instance of the Application
     *
     * @var Application|null
     */
    private static ?Application $instance = null;

    /**
     * Environment variables loaded from .env file
     *
     * @var array
     */
    private array $env;

    /**
     * Registered logging channels
     *
     * @var array<string, Channel>
     */
    private array $loggers = [];

    /**
     * Initialize a new Application instance
     *
     * Sets up HTTP and CLI routers, ensures .env file exists,
     * loads environment variables, and initializes a null logger.
     */
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

    /**
     * Register a new logging channel
     *
     * @param string $key Channel identifier
     * @param Channel $log Logger instance
     * @return void
     */
    public function addLoggingChannel(string $key, Channel $log): void
    {
        $this->loggers[$key] = $log;
    }

    /**
     * Get a logging channel by key
     *
     * Returns the null logger if the requested channel doesn't exist
     *
     * @param string $key Channel identifier
     * @return Channel Logger instance
     */
    public function getLoggingChannel(string $key) : Channel
    {
        if(!array_key_exists($key, $this->loggers)){
            return $this->loggers["blank"];
        }
        return $this->loggers[$key];
    }

    /**
     * Boot the application
     *
     * Loads all registered routes and commands
     *
     * @return void
     */
    public function boot(): void
    {
        foreach ($this->routes as $route){
            $this->httpRouter->loadRoutes($route["file"]);
        }

        foreach ($this->commands as $command){
            require_once File::rootPath().$command;
        }
    }

    /**
     * Get environment variables
     *
     * @return array Environment variables
     */
    public function getEnv(): array
    {
        return $this->env;
    }

    /**
     * Get or create the singleton Application instance
     *
     * @return Application The singleton instance
     */
    public static function getInstance(): Application
    {
        if(Application::$instance == null){
            Application::$instance = new Application();
        }

        return Application::$instance;
    }

    /**
     * Register a route file
     *
     * @param string $route Path to route file
     * @return void
     */
    public function loadRoutes(string $route): void
    {
        $this->routes[] = ["file" => $route];
    }

    /**
     * Execute an HTTP request
     *
     * Process incoming HTTP request by:
     * 1. Booting the application
     * 2. Analyzing and looking up the requested route
     * 3. Validating controller and method
     * 4. Running middleware
     * 5. Performing route model binding
     * 6. Executing the controller method
     *
     * @return string JSON response
     */
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

        // Apply model binding for route parameters
        foreach ($method->getParameters() as $parameter) {

            if($parameter->getType() === null){
                continue;
            }
            if(is_subclass_of($parameter->getType()->getName(), Model::class)){
                $class = $parameter->getType()->getName();
                $reflection = new ReflectionClass($class);

                $pkValue = $response["variables"][$parameter->getName()];
                $pkKey = $class::getDatabasePrimaryKey($reflection)["NAME"];

                $instance = $class::where($pkKey,$pkValue)->getFirst();

                if($instance !== null){
                    $response["variables"][$parameter->getName()] = $instance;
                }else{
                    http_response_code(404);

                    $response = new JsonResponse()
                        ->setStatusCode(404)
                        ->setOutcome(false)
                        ->setMessage("The requested resource '" . $parameter->getName() . "' doesnt exist.");

                    return json_encode($response->getArray());
                }
            }
        }

        $result = $method->invokeArgs($controller, $response["variables"]);

        $result->set_response_header();
        return $result->execute();
    }

    /**
     * Check if a method requires an HTTP Request parameter
     *
     * @param ReflectionMethod $method Method to check
     * @return string|null Parameter name that should receive the Request, or null if none
     */
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

    /**
     * Load environment variables from .env file
     *
     * Parses the .env file and populates the env property with key-value pairs.
     * Handles empty lines, comments, and quoted values.
     *
     * @return void
     */
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

    /**
     * Execute a console command
     *
     * Registers built-in commands, analyzes the command input,
     * validates the controller and method, and executes the command.
     *
     * @param array $args Command line arguments
     * @return string Command output
     */
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

    /**
     * Register a command file
     *
     * @param string $commandFile Path to command file
     * @return void
     */
    public function loadCommands(string $commandFile): void
    {
        array_push($this->commands, $commandFile);
    }

    /**
     * Resets all the routes currently registered in the application
     *
     * @return void
     */
    public static function reset(): void
    {
        Application::$instance = new Application();
    }

}