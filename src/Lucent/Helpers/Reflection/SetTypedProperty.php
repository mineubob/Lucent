<?php

namespace Lucent\Helpers\Reflection;

use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use TypeError;

/**
 * Sets a ReflectionProperty's value with automatic casting / coercion
 * according to its declared type.
 */
function setTypedProperty(object $object, ReflectionProperty $prop, mixed $value): void
{
    $type = $prop->getType();

    // Untyped or mixed → assign directly
    if ($type === null || ($type instanceof ReflectionNamedType && $type->getName() === 'mixed')) {
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
        return;
    }

    // Handle union types
    if ($type instanceof ReflectionUnionType) {
        foreach ($type->getTypes() as $innerType) {
            try {
                $coerced = coerceValueToType($innerType, $value);
                $prop->setAccessible(true);
                $prop->setValue($object, $coerced);
                return;
            } catch (TypeError) {
                // try next type
            }
        }
        throw new TypeError("Value does not match any allowed union type for \${$prop->getName()}");
    }

    // Single type
    $coerced = coerceValueToType($type, $value);
    $prop->setAccessible(true);
    $prop->setValue($object, $coerced);
}

/**
 * Coerces a value to the given ReflectionNamedType.
 */
function coerceValueToType(ReflectionNamedType $type, mixed $value): mixed
{
    $typeName = $type->getName();
    $allowsNull = $type->allowsNull();

    if ($value === null) {
        if ($allowsNull) {
            return null;
        }
        throw new TypeError("Cannot assign null to non-nullable type {$typeName}");
    }

    // Built-in scalar types
    if ($type->isBuiltin()) {
        return match ($typeName) {
            'int'    => (int)$value,
            'float'  => (float)$value,
            'string' => (string)$value,
            'bool'   => (bool)$value,
            'array'  => (array)$value,
            'object' => (object)$value,
            'mixed'  => $value,
            'callable' => is_callable($value) ? $value
                : throw new TypeError("Value is not callable"),
            default  => throw new TypeError("Unsupported builtin type: {$typeName}"),
        };
    }

    // Handle enums
    if (enum_exists($typeName)) {
        $refEnum = new ReflectionEnum($typeName);
        if ($refEnum->isBacked()) {
            foreach ($refEnum->getCases() as $case) {
                if ($case->getBackingValue() == $value) {
                    return $case->getValue();
                }
            }
        } else {
            foreach ($refEnum->getCases() as $case) {
                if ($case->getName() === $value) {
                    return $case->getValue();
                }
            }
        }
        throw new TypeError("Invalid enum value for {$typeName}");
    }

    // Handle class/interface types
    if (class_exists($typeName) || interface_exists($typeName)) {
        if ($value instanceof $typeName) {
            return $value;
        }
        // Optionally attempt to build object from array
        if (is_array($value)) {
            return new $typeName(...$value);
        }
        throw new TypeError("Value is not instance of {$typeName}");
    }

    throw new TypeError("Unsupported type: {$typeName}");
}
