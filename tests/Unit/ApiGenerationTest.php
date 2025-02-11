<?php

namespace Unit;

use Lucent\Commandline\DocumentationController;
use PHPUnit\Framework\TestCase;

class ApiGenerationTest extends TestCase
{

    public function test_api_html_generation(): void
    {
        $docsController = new DocumentationController();

        $this->generateTestRule();
        $this->generateTestController();

        $docsController->generateApi();

        $this->assertTrue(file_exists(EXTERNAL_ROOT."storage".DIRECTORY_SEPARATOR."documentation".DIRECTORY_SEPARATOR."api.html"));
    }

    public function test_api_endpoint_detection(): void
    {
        $this->generateTestRule();
        $this->generateTestController();

        $docsController = new DocumentationController();
        $result = $docsController->scanControllers();

        $this->assertCount(2, $result);
    }


    private function generateTestRule(): void
    {
        $ruleContent = <<<'PHP'
        <?php
        namespace App\Rules;
        
        use Lucent\Validation\Rule;
        
        class SignupRule extends Rule
        {
        
            public function setup(): array
            {
                return [
                    "email" => [
                        "regex:email",
                        "max:255"
                    ],
                    "full_name" => [
                        "min:2",
                        "min:2",
                        "max:100"
                    ],
                    "password" => [
                        "regex:password",
                        "min:8",
                        "max:255"
                    ],
                    "password_confirmation" => [
                        "same:password"
                    ]
                ];
            }
        }
        PHP;


        $appPath = TEMP_ROOT. "app";
        $rulePath = $appPath . DIRECTORY_SEPARATOR . "rules";

        if (!is_dir($rulePath)) {
            mkdir($rulePath, 0755, true);
        }

        file_put_contents(
            $rulePath.DIRECTORY_SEPARATOR.'SignupRule.php',
            $ruleContent
        );

    }


    public function generateTestController(): void
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\Http\Attributes\ApiEndpoint;
        use Lucent\Http\Request;
        use App\Rules\SignupRule;

        class RegistrationController
        {
            #[ApiEndpoint(
                description: 'New account registration',
                path: '/auth/register',
                method: 'POST',
                rule: SignupRule::class,
            )]
            public function register(Request $request)
            {
            }
        
            #[ApiEndpoint(
                description: 'Session validation',
                path: '/auth/validate/{session}',
                method: 'GET',
                pathParams: [
                    "session" => "The users current authentication session key."
                ]
            )]
            public function validate($session)
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
            $controllerPath.DIRECTORY_SEPARATOR.'RegistrationController.php',
            $controllerContent
        );
    }
}