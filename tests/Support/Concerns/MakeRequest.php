<?php

namespace Tests\Support\Concerns;

use Lucent\Http\Message\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds PSR-7 ServerRequest instances for tests and dispatches them
 * through the application.
 *
 * Instead of mutating $_SERVER and calling App::handleHttpRequest() with no
 * arguments, tests use makeRequest() to construct an explicit request and
 * get()/post()/etc. to dispatch it. This keeps request construction out of
 * the global state and mirrors how executeHttpRequest() builds a request
 * from globals in production.
 */
trait MakeRequest
{
    /**
     * Build a PSR-7 ServerRequest for the given method and URI.
     *
     * @param string $method HTTP method (GET, POST, ...)
     * @param string $uri Request target, e.g. '/users/1'
     * @param array $serverParams Optional $_SERVER-style params
     * @return ServerRequestInterface
     */
    protected function makeRequest(string $method, string $uri, array $serverParams = []): ServerRequestInterface
    {
        return ServerRequest::create($method, $uri, server: $serverParams);
    }

    /**
     * Dispatch a request through the application and return the response.
     *
     * @param ServerRequestInterface $request The request to handle
     * @return ResponseInterface
     */
    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        return \Lucent\Facades\App::handleHttpRequest($request);
    }

    /**
     * Build and dispatch a GET request.
     *
     * @param string $uri Request target, e.g. '/users/1'
     * @return ResponseInterface
     */
    protected function get(string $uri): ResponseInterface
    {
        return $this->handle($this->makeRequest('GET', $uri));
    }

    /**
     * Build and dispatch a POST request.
     *
     * @param string $uri Request target, e.g. '/users'
     * @return ResponseInterface
     */
    protected function post(string $uri): ResponseInterface
    {
        return $this->handle($this->makeRequest('POST', $uri));
    }

    /**
     * Build and dispatch a PUT request.
     *
     * @param string $uri Request target, e.g. '/users/1'
     * @return ResponseInterface
     */
    protected function put(string $uri): ResponseInterface
    {
        return $this->handle($this->makeRequest('PUT', $uri));
    }

    /**
     * Build and dispatch a DELETE request.
     *
     * @param string $uri Request target, e.g. '/users/1'
     * @return ResponseInterface
     */
    protected function delete(string $uri): ResponseInterface
    {
        return $this->handle($this->makeRequest('DELETE', $uri));
    }
}