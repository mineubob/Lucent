<?php

namespace Tests\Feature;

use Lucent\Facades\App;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\MakeRequest;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\FixtureLoader;


class CustomErrorPageTest extends TestCase
{
    use MakeRequest;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Register routes
        self::refreshApplication();

        define('VIEWS',TEMP_ROOT."App".DIRECTORY_SEPARATOR."Views");

        if (!is_dir(VIEWS)) {
            mkdir(VIEWS, 0755, true);
        }

        FixtureLoader::copyView('404.html');
        FixtureLoader::copyRoutes('customErrorPageRoutes.php');

        App::registerRoutes("/routes/customErrorPageRoutes.php");
    }

    public function test_setting_error_page() : void
    {
        $response = $this->get('/');

        $this->assertEquals(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertTrue(str_contains($body,"<button>Back</button>"));
        $this->assertTrue(str_contains($body,"<button>Home</button>"));
        $this->assertTrue(str_contains($body,"<h1>Ops! it looks like the page cannot be found!</h1>"));
    }



}