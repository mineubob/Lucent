<?php

namespace Lucent\StaticAnalysis;

use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

class ReflectionHelpers
{

    /**
     * Get a string representation of a parameter type
     *
     * @param ReflectionType|ReflectionUnionType|ReflectionIntersectionType|null $type The parameter type from reflection
     * @param ReflectionParameter|ReflectionMethod $caller
     * @return string A string representation of the type
     */
    public static function getTypeString(null|ReflectionType|ReflectionUnionType|ReflectionIntersectionType $type, ReflectionParameter|ReflectionMethod $caller): string {

        $returnType = "unknown";

        // For union types use "|" separator
        if ($type instanceof ReflectionUnionType) {
            $returnType = implode('|', $type->getTypes());
        }
        // For intersection types use "&" separator
        else if ($type instanceof ReflectionIntersectionType) {
            $returnType = implode('&', $type->getTypes());
        }

        if ($type === null) {
            $returnType =  "void";
        }

        if ($type instanceof ReflectionNamedType) {
            $returnType =  $type->getName();
        }

        if($returnType === "static" || $returnType === "self"){
            $returnType = $caller->getDeclaringClass()->getName();
        }

        if($returnType === null){
            $returnType = "void";
        }

        return $returnType;
    }

    /**
     * Extracts a specified tag's message from a PHPDoc block.
     *
     * @param string $docBlock The PHPDoc block as a string
     * @param string $tagName The PHPDoc tag to extract (without the @ symbol)
     * @return string|null The extracted message or null if not found
     */
    public static function extractDocTagMessage(string $docBlock, string $tagName): ?string
    {
        // Use regex to find the specified tag and capture the message
        $pattern = '/@' . preg_quote($tagName, '/') . '\s+(.*?)(\n\s*\*|\n\s*\/|\s*$)/s';

        if (preg_match($pattern, $docBlock, $matches)) {
            // Clean up the message by removing extra whitespace
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract method arguments from token array
     *
     * Parses tokens to extract argument values, types, and positions
     *
     * @param array $tokens Array of tokens from token_get_all
     * @param int $startingIndex Index where arguments begin in the token array
     * @return array List of arguments with their values, types, and positions
     */
    public static function getArguments(array $tokens, int $startingIndex): array
    {
        $arguments = [];
        $i = $startingIndex + 2; // Skip past method name and opening parenthesis
        $index = 0;

        // Continue until closing parenthesis
        while($tokens[$i] != ")"){
            if($tokens[$i] != ","){
                // Extract argument value, stripping quotes if present
                $value = preg_replace('/^\"(.*)"$/', '$1', $tokens[$i][1]);
                if($value != null) {
                    $arguments[] = [
                        "value" => $value,
                        "type" => gettype($tokens[$i][1]),
                        "index" => $index
                    ];
                }
                $index++;
            }
            $i++;
        }

        return $arguments;
    }
}
