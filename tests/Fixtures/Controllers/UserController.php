<?php
namespace App\Controllers;

use App\Models\SluggedModel;
use App\Models\TestUser;
use Lucent\Http\Message\Response;
use Lucent\Support\Attributes\Bind;

class UserController
{
    public function getById($id) : Response
    {
        return (new Response())->withJsonEnvelope(['id' => $id], 'OK', true, 200);
    }

    // #[Bind] — resolves by primary key from the {user} route variable.
    public function getModelById(#[Bind] TestUser $user) : Response
    {
        return (new Response())->withJsonEnvelope(['full_name' => $user->getFullName()], 'OK', true, 200);
    }

    // #[Bind(resolve: 'slug')] — resolves by a non-PK column.
    public function getBySlug(#[Bind(resolve: 'slug')] SluggedModel $model) : Response
    {
        return (new Response())->withJsonEnvelope(['name' => $model->name], 'OK', true, 200);
    }

    // #[Bind] with an invokable scope class — only resolves when the row
    // matches the scope's constraint (PHP attributes only accept constant
    // expressions, so the scope is an invokable class-string).
    public function getScoped(
        #[Bind(scope: \App\Middleware\JaneDoeScope::class)] TestUser $user
    ) : Response {
        return (new Response())->withJsonEnvelope(['full_name' => $user->getFullName()], 'OK', true, 200);
    }

    // #[Bind] with a [Class::class, 'method'] array scope — arrays of
    // constants are legal attribute arguments.
    public function getScopedArray(
        #[Bind(scope: [\App\Middleware\JaneDoeScope::class, 'apply'])] TestUser $user
    ) : Response {
        return (new Response())->withJsonEnvelope(['full_name' => $user->getFullName()], 'OK', true, 200);
    }

    // A Model type-hint WITHOUT #[Bind] — never auto-bound; the container
    // cannot resolve it, so the request fails (the IDOR fix).
    public function getUnbound(TestUser $user) : Response
    {
        return (new Response())->withJsonEnvelope(['full_name' => $user->getFullName()], 'OK', true, 200);
    }
}
