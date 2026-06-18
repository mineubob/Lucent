<?php

namespace Lucent\Database;

interface DatabaseLogger
{
    public function info(string $message): void;
    public function warning(string $message): void;
    public function error(string $message): void;
    public function critical(string $message): void;
}