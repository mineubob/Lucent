<?php

namespace Lucent\Http;

class JsonResponse extends Response
{

    public function __construct()
    {
        parent::__construct();
    }

    public function execute(): false|string
    {
        return json_encode($this->getArray());
    }
}
