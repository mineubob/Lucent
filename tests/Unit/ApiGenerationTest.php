<?php

namespace Tests\Unit;

use Lucent\Commandline\GenerateDocumentationCommand;
use Lucent\Facades\FileSystem;
use Lucent\Filesystem\File;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixtureLoader;

class ApiGenerationTest extends TestCase
{

    public function test_api_html_generation(): void
    {
        $docsController = new GenerateDocumentationCommand();

        FixtureLoader::copyRule('SignupRule.php');
        FixtureLoader::copyController('RegistrationController.php');

        $docsController->generateApi();

        $this->assertTrue(file_exists(FileSystem::rootPath().DIRECTORY_SEPARATOR."storage".DIRECTORY_SEPARATOR."documentation".DIRECTORY_SEPARATOR."api.html"));
    }

    public function test_api_endpoint_detection(): void
    {
        FixtureLoader::copyRule('SignupRule.php');
        FixtureLoader::copyController('RegistrationController.php');

        $docsController = new GenerateDocumentationCommand();
        $result = $docsController->scanControllers();

        $this->assertCount(2, $result);
    }


}
