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
            // Clean up the message - remove leading/trailing whitespace and asterisks
            $message = $matches[1];

            // Remove any line breaks and following asterisks/spaces
            $message = preg_replace('/\n\s*\*\s*/', ' ', $message);

            // Remove any remaining whitespace and normalize spaces
            $message = preg_replace('/\s+/', ' ', $message);

            return trim($message);
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
        $args = [];
        $i = $startingIndex;
        $currentArgument = null;
        $argumentIndex = 0;
        $inParenthesis = 0;
        $reachedOpenParen = false;

        // Find the opening parenthesis first
        while ($i < count($tokens)) {
            if ($tokens[$i] === '(') {
                $reachedOpenParen = true;
                break;
            }
            $i++;
        }

        if (!$reachedOpenParen) {
            return [];
        }

        $i++; // Skip the opening parenthesis

        // Now collect arguments until we reach the closing parenthesis
        while ($i < count($tokens)) {
            $token = $tokens[$i];

            // Check if we're at an opening parenthesis (nested calls)
            if ($token === '(') {
                $inParenthesis++;
            }

            // Check if we're at a closing parenthesis
            else if ($token === ')') {
                if ($inParenthesis === 0) {
                    // If we have a current argument, add it
                    if ($currentArgument !== null) {
                        $args[$argumentIndex] = $currentArgument;
                    }
                    break; // End of arguments
                }
                $inParenthesis--;
            }

            // Check if we're at a comma (argument separator)
            else if ($token === ',' && $inParenthesis === 0) {
                if ($currentArgument !== null) {
                    $args[$argumentIndex] = $currentArgument;
                    $argumentIndex++;
                    $currentArgument = null;
                }
            }

            // Otherwise, collect token as part of the current argument
            else {
                // Add the token to the current argument
                if ($currentArgument === null) {
                    $currentArgument = [
                        "type" => is_array($token) ? $token[0] : null,
                        "value" => is_array($token) ? $token[1] : $token, // Here's where line 101 was likely failing
                        "line" => is_array($token) && isset($token[2]) ? $token[2] : null
                    ];
                }
            }

            $i++;
        }

        return $args;
    }
}