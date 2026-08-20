<?php

namespace Tests\Unit;

use Lucent\Commandline\GenerateDocumentationCommand;
use Lucent\Facades\FileSystem;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\CopiesFixtures;

class ApiGenerationTest extends TestCase
{
    use CopiesFixtures;

    public function test_api_html_generation(): void
    {
        $docsController = new GenerateDocumentationCommand();

        self::copyFixtures([
            'Rule'       => 'SignupRule.php',
            'Controller' => 'RegistrationController.php',
        ]);

        $docsController->generateApi();

        $this->assertTrue(file_exists(FileSystem::rootPath().DIRECTORY_SEPARATOR."storage".DIRECTORY_SEPARATOR."documentation".DIRECTORY_SEPARATOR."api.html"));
    }

    public function test_api_endpoint_detection(): void
    {
        self::copyFixtures([
            'Rule'       => 'SignupRule.php',
            'Controller' => 'RegistrationController.php',
        ]);

        $docsController = new GenerateDocumentationCommand();
        $result = $docsController->scanControllers();

        $this->assertCount(2, $result);
    }

}
