<?php

namespace Lucent\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Thrown when a container entry exists but cannot be resolved without error.
 *
 * Examples: a factory returns a value of the wrong type, or a class-string
 * cannot be instantiated.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
