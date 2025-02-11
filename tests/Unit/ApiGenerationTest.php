<?php

namespace Unit;

use Lucent\Commandline\DocumentationController;
use PHPUnit\Framework\TestCase;

class ApiGenerationTest extends TestCase
{

    public function test_api_html_generation(): void
    {
        $docsController = new DocumentationController();

        $this->generateTestController();

        $docsController->generateApi();

        $this->assertTrue(file_exists(EXTERNAL_ROOT."storage".DIRECTORY_SEPARATOR."documentation".DIRECTORY_SEPARATOR."api.html"));
    }

    public function test_api_endpoint_detection(): void
    {
        $this->generateTestController();

        $docsController = new DocumentationController();
        $result = $docsController->scanControllers();

        $this->assertCount(2, $result);
    }


    public function generateTestController(): void
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\Http\Attributes\ApiEndpoint;
        
        class MultiEndpointController
        {
            #[ApiEndpoint(
                description: 'First endpoint',
                path: '/test1',
                method: 'GET'
            )]
            public function test1()
            {
            }
        
            #[ApiEndpoint(
                description: 'Second endpoint',
                path: '/test2',
                method: 'POST'
            )]
            public function test2()
            {
            }
        }
        PHP;


        $appPath = TEMP_ROOT. "app";
        $controllerPath = $appPath . DIRECTORY_SEPARATOR . "controllers";

        if (!is_dir($controllerPath)) {
            mkdir($controllerPath, 0755, true);
        }

        file_put_contents(
            $controllerPath.DIRECTORY_SEPARATOR.'MultiEndpointController.php',
            $controllerContent
        );
    }
}