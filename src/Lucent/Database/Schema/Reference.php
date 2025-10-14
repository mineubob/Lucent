<?php

namespace Lucent\Database\Schema;

use ReflectionClass;

class Reference
{
    public function __construct(public protected(set) string $table, public protected(set) string $column)
    {
    }

    /**
     * Converts a string to Reference.
     * @param class-string<\Lucent\Model\Model>|string $potentialReference
     * @return Reference
     */
    public static function fromString(string $potentialReference): self
    {
        if (preg_match('/(\w+)\((\w+)\)/', $potentialReference, $matches) === 1) {
            return new self($matches[1], $matches[2]);
        }

        if (!class_exists($potentialReference)) {
            throw new \InvalidArgumentException("Invalid reference: $potentialReference");
        }

        $refClass = new ReflectionClass($potentialReference);
        if (!$refClass->isSubclassOf(\Lucent\Model\Model::class)) {
            throw new \InvalidArgumentException("$potentialReference is not a model");
        }

        $pk = \Lucent\Model\Model::getDatabasePrimaryKey($refClass);
        return new self($refClass->getShortName(), $pk->name);
    }
}