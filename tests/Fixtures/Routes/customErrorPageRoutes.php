<?php
use Lucent\Facades\Route;
use Lucent\Http\Message\Response;
use Lucent\Http\Message\Stream;

Route::error(404, (new Response())->withStatus(404)
    ->withBody(Stream::fromString(file_get_contents(VIEWS . '/404.html')))
    ->withHeader('Content-Type', 'text/html; charset=utf-8')
);