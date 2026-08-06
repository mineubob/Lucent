<?php

namespace Lucent\Http\Client\Handler;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Transport handler for the PSR-18 client.
 *
 * A handler performs the actual HTTP transfer for a fully-resolved request.
 * The client resolves the URI, merges configuration defaults into the options
 * array, and dispatches to a handler based on the per-request `stream` option.
 *
 * Handlers are stateless collaborators — all per-request state arrives via
 * the (merged) options array.
 */
interface HandlerInterface
{
    /**
     * Validate handler-specific options.
     *
     * Called by {@see send()} (and available to the client) after config
     * defaults are merged, so a handler can reject options it cannot honor —
     * e.g. conflicting cURL options, or options unsupported by the transport.
     *
     * @param array<string, mixed> $options Merged per-request options
     * @throws \InvalidArgumentException
     */
    public function validateOptions(array $options): void;

    /**
     * Send a fully-resolved request and return a PSR-7 response.
     *
     * The request URI is already absolute and the options array already
     * contains the merged defaults (timeout, verify_ssl, user_agent,
     * basic_auth, headers, curl, sink, ...).
     *
     * @param array<string, mixed> $options Merged per-request options
     */
    public function send(RequestInterface $request, array $options): ResponseInterface;
}
