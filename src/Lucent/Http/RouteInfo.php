<?php

namespace Lucent\Http;

readonly class RouteInfo
{
    /**
     * Create a new RouteInfo instance
     *
     * @param string $controllerClass Fully qualified controller class name
     * @param string $method Controller method being executed
     * @param string $path The matched route path
     * @param string $httpMethod HTTP method (GET, POST, etc.)
     * @param array $parameters Route parameters extracted from the URL
     */
    public function __construct(
        public string $controllerClass,
        public string $method,
        public string $path,
        public string $httpMethod,
        public array  $parameters = []
    ) {}

    /**
     * Get controller short name without a namespace
     *
     * @return string
     */
    public function getControllerName(): string
    {
        $parts = explode('\\', $this->controllerClass);
        return end($parts);
    }
}