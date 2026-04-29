<?php

namespace Lucent\Http;

enum HttpStatus: int
{
    case NOT_FOUND     = 404;
    case SERVER_ERROR  = 500;
    case UNAUTHORIZED  = 401;
    case FORBIDDEN     = 403;
    case BAD_REQUEST   = 400;

    public function title(): string
    {
        return match($this) {
            self::NOT_FOUND    => "Not Found",
            self::SERVER_ERROR => "Internal Server Error",
            self::UNAUTHORIZED => "Unauthorized",
            self::FORBIDDEN    => "Forbidden",
            self::BAD_REQUEST  => "Bad Request",
        };
    }

    public function message(): string
    {
        return match($this) {
            self::NOT_FOUND    => "The page you're looking for cannot be found. It may have been moved, deleted, or never existed.",
            self::SERVER_ERROR => "We're experiencing technical difficulties. Our team has been notified and is working to resolve the issue.",
            self::UNAUTHORIZED => "Authentication required. Please log in to access this resource.",
            self::FORBIDDEN    => "You don't have permission to access this resource.",
            self::BAD_REQUEST  => "An error occurred while processing your request.",
        };
    }

    public static function fromCode(int $code): self
    {
        return self::tryFrom($code) ?? self::SERVER_ERROR;
    }
}