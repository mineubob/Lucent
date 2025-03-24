<?php

namespace Lucent\StaticAnalysis;

/**
 * Class Analyzer
 *
 * A static analysis tool for PHP code that analyzes dependencies and usage patterns
 * within a specified namespace. It parses PHP files to identify class instantiations,
 * method calls, and other dependency relationships.
 *
 * @package Lucent\StaticAnalysis
 */
class Analyser
{
    /**
     * Match by token ID
     * Used when matching against token type constants like T_STRING, T_NAMESPACE, etc.
     */
    const int MATCH_ID = 0;

    /**
     * Match by token value
     * Used when matching against the actual text content of a token
     */
    const int MATCH_VALUE = 1;

    /**
     * Match exact token
     * Used when matching a token array or string exactly
     */
    const int MATCH_EXACT = 2;

    /**
     * Array of registered token callbacks
     *
     * @var array<int, array{type: int, id: mixed, function: callable}>
     */
    private array $callbacks;

    /**
     * Callback for handling unmatched tokens
     *
     * @var callable|null
     */
    private $onUnhandledTokenCallback;



    /**
     * Register a callback to be executed when a specific token is encountered
     *
     * @param int|array|string $id The token ID, array of IDs, or string to match
     * @param callable $callback The function to call when token is matched
     * @param int $matchType The type of matching to perform (MATCH_ID, MATCH_VALUE, or MATCH_EXACT)
     * @return void
     */
    public function onToken(int|array|string $id, callable $callback, int $matchType = self::MATCH_ID): void
    {
        // If token ID is a string, strip any surrounding quotes
        if(is_string($id)){
            $id = preg_replace('/^[\'"]|[\'"]$/', '', $id);
        }

        // If token ID is an array, register callback for each value in the array
        if(gettype($id) === "array"){
            foreach ($id as $value){
                $this->callbacks[] = [
                    "type" => $matchType,
                    "id" => $value,
                    "function" => $callback
                ];
            }

            return;
        }

        // Register callback for a single token ID
        $this->callbacks[] = [
            "type" => $matchType,
            "id" => $id,
            "function" => $callback
        ];
    }

    /**
     * Clear all registered callbacks
     *
     * @return void
     */
    public function clear(): void
    {
        $this->callbacks = [];
        $this->onUnhandledTokenCallback = null;
    }

    /**
     * Register a callback for tokens that don't match any registered handlers
     *
     * @param callable $callback The function to call for unhandled tokens
     * @return void
     */
    public function onUnhandledToken(callable $callback): void
    {
        $this->onUnhandledTokenCallback = $callback;
    }

    /**
     * Parse PHP content and execute registered callbacks for matching tokens
     *
     * @param string $content The PHP code to analyze
     * @return void
     */
    public function run(string $content): void
    {
        // Tokenize the PHP content
        $tokens = token_get_all($content, TOKEN_PARSE);

        // Iterate through each token
        foreach($tokens as $i => $token){
            // Check token against each registered callback
            foreach ($this->callbacks as $callback){
                switch ($callback["type"]){
                    // Match by token ID (e.g., T_STRING, T_NAMESPACE)
                    case self::MATCH_ID:
                        if($token[0] === $callback["id"]){
                            call_user_func($callback["function"], $i, $token, $tokens);
                        }
                        break;

                    // Match exact token object - used for single character tokens like "=" that aren't arrays
                    case self::MATCH_EXACT:
                        if($token == $callback["id"]){
                            call_user_func($callback["function"], $i, $token, $tokens);
                        }
                        break;

                    // Match by token value (the string content)
                    case self::MATCH_VALUE:
                        if($token[1] == $callback["id"]){
                            call_user_func($callback["function"], $i, $token, $tokens);
                        }
                        break;

                    // Handle an unrecognized match type
                    default:
                        if($this->onUnhandledTokenCallback !== null){
                            call_user_func($this->onUnhandledTokenCallback, $i, $token, $tokens);
                        }
                }
            }
        }
    }
}