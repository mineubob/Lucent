<?php

namespace Unit;

use Lucent\Application;
use Lucent\Facades\App;
use PHPUnit\Framework\TestCase;


class CustomErrorPageTest extends TestCase
{


    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Register routes
        Application::reset();

        define('VIEWS',TEMP_ROOT."App".DIRECTORY_SEPARATOR."Views");

        if (!is_dir(VIEWS)) {
            mkdir(VIEWS, 0755, true);
        }

        self::generateCustomHtmlResponse();
        self::generateCustom404Page();
        self::generateRoutesFile();

        App::registerRoutes("/routes/customErrorPageRoutes.php");
    }

    public function test_setting_error_page() : void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REQUEST_URI"] = "/";

        $response = App::handleHttpRequest();

        $this->assertEquals($response->statusCode, 200);
        $this->assertTrue(str_contains($response->body(),"<button>Back</button>"));
        $this->assertTrue(str_contains($response->body(),"<button>Home</button>"));
        $this->assertTrue(str_contains($response->body(),"<h1>Ops! it looks like the page cannot be found!</h1>"));
    }

    private static function generateRoutesFile(): void
    {

        $routesContent = <<<'PHP'
        <?php
        use App\Extensions\Http\ViewResponse;
        use Lucent\Facades\Route;
            
        Route::error(404,new ViewResponse('/404.html'));

        PHP;

        $routesPath = rtrim(TEMP_ROOT, DIRECTORY_SEPARATOR) . '/routes';

        if (!is_dir($routesPath)) {
            mkdir($routesPath, 0755, true);
        }

        file_put_contents($routesPath . '/customErrorPageRoutes.php', $routesContent);

    }

    private static function generateCustomHtmlResponse(): void
    {

        $classContent = <<<'PHP'
        <?php
        /**
         * Copyright Jack Harris
         * Peninsula Interactive - policyManager
         * Last Updated - 18/11/2023
         */
        
        namespace App\Extensions\Http;
        
        use Lucent\Http\HttpResponse;
        
        class ViewResponse extends HttpResponse
        {
        
            private string $path;        
        
            public function __construct(string $path){
                parent::__construct("",200);
        
                $this->path = $path;
            }
        
            public function body(): string|null
            {
                if(!file_exists(VIEWS.$this->path)){
                    $this->statusCode = 500;
                    return "500 FILE NOT FOUND";
                }
        
                return file_get_contents(VIEWS.$this->path);
            }

            public function set_response_header(): void
            {
        
                header("Content-Type: text/html; charset=utf-8");
            }
        }
        PHP;

        $path =  TEMP_ROOT.DIRECTORY_SEPARATOR."App".DIRECTORY_SEPARATOR. "Extensions" . DIRECTORY_SEPARATOR ."Http".DIRECTORY_SEPARATOR;

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path . '/ViewResponse.php', $classContent);

    }

    private static function generateCustom404Page(): void
    {

        $content = <<<HTML
            <h1>Ops! it looks like the page cannot be found!</h1>
            <button>Home</button>
            <button>Back</button>
        HTML;

        $path = TEMP_ROOT.DIRECTORY_SEPARATOR."App".DIRECTORY_SEPARATOR . "Views" . DIRECTORY_SEPARATOR;

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        file_put_contents($path . '/404.html', $content);

    }



}