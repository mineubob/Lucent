<?php
namespace App\Controllers;

use App\Services\InjectionGreeterInterface;
use Lucent\Http\Message\Response;

class ContainerInjectionController
{
    private InjectionGreeterInterface $greeter;

    /**
     * The greeter is resolved from the container via constructor injection.
     */
    public function __construct(InjectionGreeterInterface $greeter)
    {
        $this->greeter = $greeter;
    }

    /**
     * The greeter is also resolved via method-parameter injection.
     */
    public function greet(InjectionGreeterInterface $greeter) : Response
    {
        return (new Response())->withJsonEnvelope(
            ['constructor' => $this->greeter->greet(), 'method' => $greeter->greet()],
            'OK',
            true,
            200
        );
    }
}
