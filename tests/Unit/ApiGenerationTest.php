<?php

namespace Unit;

use Lucent\Commandline\DocumentationController;
use Lucent\Facades\File;
use PHPUnit\Framework\TestCase;

class ApiGenerationTest extends TestCase
{

    public function test_api_html_generation(): void
    {
        $docsController = new DocumentationController();

        $this->generateTestRule();
        $this->generateTestController();

        $docsController->generateApi();

        $this->assertTrue(file_exists(File::rootPath()."storage".DIRECTORY_SEPARATOR."documentation".DIRECTORY_SEPARATOR."api.html"));
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


        $appPath = TEMP_ROOT. "App";
        $rulePath = $appPath . DIRECTORY_SEPARATOR . "Rules";

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
        use Lucent\Http\Attributes\ApiResponse;use Lucent\Http\JsonResponse;use Lucent\Http\Request;
        use App\Rules\SignupRule;

        class RegistrationController
        {
            #[ApiEndpoint(
                description: 'New account registration',
                path: '/auth/register',
                rule: SignupRule::class,
                method: 'POST'
            )]
            #[ApiResponse(
                outcome: true,
                message: "Successfully created your new account, please check your email to confirm accounts activation.",
                content: ["redirect","/home"]
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
            #[ApiResponse(
                outcome: true,
                message: "OK",
                status: 200
            )]
            #[ApiResponse(
                outcome: false,
                message: "Ops! your login may have expired, please login again.",
                status: 401
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