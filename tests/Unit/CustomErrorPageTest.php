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

        $this->assertEquals(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertTrue(str_contains($body,"<button>Back</button>"));
        $this->assertTrue(str_contains($body,"<button>Home</button>"));
        $this->assertTrue(str_contains($body,"<h1>Ops! it looks like the page cannot be found!</h1>"));
    }

    private static function generateRoutesFile(): void
    {

        $routesContent = <<<'PHP'
        <?php
        use Lucent\Facades\Route;
        use Lucent\Http\Message\Response;
        use Lucent\Http\Message\Stream;
        
        Route::error(404, (new Response())->withStatus(404)
            ->withBody(Stream::fromString(file_get_contents(VIEWS . '/404.html')))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
        );

        PHP;

        $routesPath = rtrim(TEMP_ROOT, DIRECTORY_SEPARATOR) . '/routes';

        if (!is_dir($routesPath)) {
            mkdir($routesPath, 0755, true);
        }

        file_put_contents($routesPath . '/customErrorPageRoutes.php', $routesContent);

    }

    private static function generateCustomHtmlResponse(): void
    {
        // No custom class needed — Route::error() accepts PSR-7 Response directly
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