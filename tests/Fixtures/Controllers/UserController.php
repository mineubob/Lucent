<?php
namespace App\Controllers;

use Lucent\Http\Message\Response;
use App\Models\TestUser;

class UserController
{
    public function getById($id) : Response
    {
        return (new Response())->withJsonEnvelope(['id' => $id], 'OK', true, 200);
    }
    
    public function getModelById(TestUser $user) : Response
    {
        return (new Response())->withJsonEnvelope(['full_name' => $user->getFullName()], 'OK', true, 200);
    }

}