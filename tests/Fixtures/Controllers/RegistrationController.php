<?php
namespace App\Controllers;

use Lucent\Http\Attributes\ApiEndpoint;
use Lucent\Http\Attributes\ApiResponse;
use Lucent\Http\Message\ServerRequest;
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
    public function register(ServerRequest $request)
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
    
    private function someHelperMethod() :void
    {
    
    }
}