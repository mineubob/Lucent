<?php
namespace App\Middleware;

use App\Models\TestUser;
use Lucent\Http\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $urlVars = $request->getAttribute('urlVars', []);
        if (($urlVars['user'] ?? null) === "1"){
            $request = $request->withAttribute('user', TestUser::where("id",1)->getFirst());
        }

        return $handler->handle($request);
    }

}