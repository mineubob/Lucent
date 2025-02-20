<?php

namespace Lucent\Http;

class JsonResponse extends Response
{

    public function __construct()
    {
        parent::__construct();
    }

    #[\Override]
    public function set_response_header()
    {
        parent::set_response_header();

        header('Content-Type: application/json; charset=utf-8');
    }

    public function execute(): string
    {
        return json_encode($this->getArray());
    }
}
