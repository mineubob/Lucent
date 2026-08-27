<?php

namespace Tests\Feature;

use App\Services\InjectionGreeter;
use App\Services\InjectionGreeterInterface;
use Lucent\Application;
use Lucent\Facades\App;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\Concerns\CopiesFixtures;
use Tests\Support\Concerns\MakeRequest;
use Tests\Support\Concerns\RefreshApplication;
use Tests\Support\TestCase;

class ContainerInjectionTest extends TestCase
{
    use CopiesFixtures;
    use MakeRequest;
    use RefreshApplication;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::copyFixtures([
            'Controller' => 'ContainerInjectionController.php',
            'Service'    => ['InjectionGreeterInterface.php', 'InjectionGreeter.php'],
            'Route'      => 'containerInjection.php',
        ]);

        self::refreshApplication();

        // Register the service under its interface identifier, matching what
        // the controller constructor and method type-hint.
        Application::getInstance()->container()
            ->instance(InjectionGreeterInterface::class, new InjectionGreeter());

        Application::getInstance()->boot();
    }

    protected function request(): ResponseInterface
    {
        return $this->get('/container/inject');
    }

    public function test_container_injects_service_into_controller_constructor_and_method(): void
    {
        $response = $this->request();

        $this->assertSame(200, $response->getStatusCode());

        $decoded = json_decode((string) $response->getBody(), true);
        $content = $decoded['content'] ?? [];

        $this->assertSame('hello-from-container', $content['constructor'] ?? null);
        $this->assertSame('hello-from-container', $content['method'] ?? null);
    }
}
