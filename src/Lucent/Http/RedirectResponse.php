<?php

namespace Lucent\Http;

class RedirectResponse extends HttpResponse
{
    /**
     * @deprecated Use Lucent\Http\Message\Response::withRedirect() instead.
     */
    public function __construct(string $url, int $status = 302, array $headers = []){
        parent::__construct("Redirecting to {$url}", $status, $headers);

        $this->headers["Content-Type"] = "text/plain; charset=utf-8";
        $this->headers["Location"] = $url;
    }

}