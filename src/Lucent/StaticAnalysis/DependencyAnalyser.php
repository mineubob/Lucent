<?php

namespace Lucent\StaticAnalysis;

use Exception;
use Lucent\Filesystem\File;
use ReflectionClass;

/**
 * Class DependencyAnalyser
 *
 * Analyzes PHP files to identify dependencies, instantiations, and method calls
 * within a specified namespace. Uses static analysis to build a dependency graph.
 *
 * @package Lucent\StaticAnalysis
 */
class DependencyAnalyser
{
    /**
     * The namespace to analyze for dependencies
     *
     * @var string
     */
    private string $namespace;

    /**
     * Collection of detected dependencies indexed by file and class name
     *
     * @var array<string, array>
     */
    public private(set) array $dependencies;

    /**
     * Cache of reflection classes to avoid repeated instantiation
     *
     * @var array<string, ReflectionClass>
     */
    private array $reflectionClasses = [];

    /**
     * Collection of files to analyze
     *
     * @var array<int, File>
     */
    private array $files;

    /**
     * Constructor for the DependencyAnalyser class
     *
     * @param string $namespace The namespace to analyze (defaults to "Lucent")
     */
    public function __construct(string $namespace = "Lucent"){
        $this->namespace = $namespace;
        $this->files = [];
        $this->dependencies = [];
    }

    /**
     * Add files to be parsed during analysis
     *
     * @param File|array<File> $file Single file or array of files to analyze
     * @return void
     */
    public function parseFiles(File|array $file): void
    {
        // Add a single file to the list
        if($file instanceof File){
            $this->files[] = $file;
        }

        // Add an array of files to the list
        if(getType($file) === "array"){
            $this->files = array_merge($this->files, $file);
        }
    }

    /**
     * Run the dependency analysis on all added files
     *
     * Analyzes files in two passes:
     * 1. Identify all dependencies (imports/use statements)
     * 2. Track instantiations and method calls for those dependencies
     *
     * @return array The complete dependency map with usage details
     */
    public function run(): array
    {
        $analyser = new Analyser();
        $knownInstantiations = [];
        $dependencies = [];
        $as = []; // Maps aliases to fully qualified class names

        foreach ($this->files as $file) {
            // First pass: detect class imports and dependencies
            $analyser->onToken([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], function ($i, $token, $tokens) use (&$file, &$dependencies, &$as) {
                // Check if the qualified name is in our target namespace
                if (str_contains($token[1], $this->namespace)) {
                    // Remove leading backslash if present
                    if (str_starts_with($token[1], '\\')) {
                        $token[1] = substr($token[1], 1);
                    }

                    // Track class aliases (e.g., "use Namespace\Class as Alias;")
                    if($tokens[$i+2][1] === "as"){
                        $as[$tokens[$i+4][1]] = $token[1];
                    }

                    // Register the dependency for this file
                    $dependencies[$file->getName()][$token[1]] = [];
                }
            });

            // Run first pass to collect dependencies only
            $analyser->run($file->getContents());
            $analyser->clear();

            // Second pass: analyze usage patterns

            // Detect class instantiations with "new" keyword
            $analyser->onToken(T_NEW, function ($i, $token, $tokens) use (&$as, &$dependencies, &$knownInstantiations, &$file) {
                $issues = [];
                $name = $tokens[$i+2][1];

                // Remove leading backslash if present
                if(str_starts_with($name, "\\")) {
                    $name = substr($name, 1);
                }

                // Check each known dependency to see if this instantiation matches
                foreach($dependencies[$file->getName()] as $className => $value){
                    // Match by alias, or class name (with or without namespace)
                    if((isset($as[$name]) && $as[$name] == $className) ||
                        str_ends_with($className, $name) ||
                        $className == $name){

                        $variableName = $tokens[$i-4][1];

                        // Check if the class exists and collect any issues
                        if(($issue = $this->checkClass($className)) !== null) $issues[] = $issue;

                        $found = false;

                        // Check if this instantiation was already recorded
                        foreach ($value as $index => $priorSave){
                            if($priorSave['line'] === $token[2]){
                                $dependencies[$file->getName()][$className][$index] = [
                                    "type" => "instantiation",
                                    "line" => $tokens[$i+2][2],
                                    "name" => $variableName,
                                    "issues" => $issues
                                ];
                                $found = true;
                                break;
                            }
                        }

                        // Add new instantiation if not already recorded
                        if(!$found){
                            $dependencies[$file->getName()][$className][] = [
                                "type" => "instantiation",
                                "line" => $tokens[$i+2][2],
                                "name" => $variableName,
                                "issues" => $issues
                            ];

                            // Track this variable for later usage analysis
                            $knownInstantiations[$variableName] = [
                                "name" => $className,
                                "line" => $tokens[$i+2][2]
                            ];
                        }
                    }
                }
            });

            // Detect static method calls with "::" operator
            $analyser->onToken("::", function ($i, $token, $tokens) use(&$file, &$dependencies, &$knownInstantiations) {
                $issues = [];
                $name = $tokens[$i-1][1];

                foreach($dependencies[$file->getName()] as $className => $value){
                    // Match by class name (with or without namespace)
                    if(str_ends_with($className, $name)){
                        // Check if the class exists and collect any issues
                        $issue = $this->checkClass($className);
                        if($issue !== null){
                            $issues[] = $issue;
                        }

                        // Check if this is an assignment operation
                        if($tokens[$i-3][0] === "="){
                            $variableName = $tokens[$i-5][1];

                            // Get the return type of the method being called
                            $returnType = $this->getMethodDetails($className, $tokens[$i+1][1])["returnType"];

                            // Record the static method call
                            $dependencies[$file->getName()][$className][] = [
                                "type" => "static",
                                "line" => $token[2],
                                "issues" => $issues,
                                "returnType" => $returnType
                            ];

                            // If the method returns an object, track this as a new instantiation
                            if($returnType !== $className){
                                $dependencies[$file->getName()][$returnType][] = [
                                    "type" => "instantiation",
                                    "line" => $token[2],
                                    "name" => $variableName,
                                    "issues" => $issues
                                ];

                                // Track this variable for later usage analysis
                                $knownInstantiations[$variableName] = [
                                    "name" => $returnType,
                                    "line" => $tokens[2]
                                ];
                            }
                        } else {
                            // Record the static method call without assignment
                            $dependencies[$file->getName()][$className][] = [
                                "type" => "static",
                                "line" => $token[2],
                                "issues" => $issues,
                                "returnType" => "void"
                            ];
                        }
                        break;
                    }
                }
            }, Analyser::MATCH_VALUE);

            // Detect variable usage for tracked instantiations
            $analyser->onToken(T_VARIABLE, function ($i, $token, $tokens) use(&$file, &$dependencies, &$knownInstantiations) {
                // Only process variables we've tracked from instantiations
                if(array_key_exists($token[1], $knownInstantiations) && $knownInstantiations[$token[1]]["line"] != $token[2]){
                    $type = "use";
                    $issues = [];

                    // Check if this is a return statement
                    if($tokens[$i-2][0] === T_RETURN){
                        $type = "return";
                        $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                            "type" => $type,
                            "line" => $token[2],
                            "name" => $token[1],
                            "issues" => $issues
                        ];
                        return;
                    }

                    // Check if this is a method call on the object
                    if($tokens[$i+1][1] === "->") {
                        $type = "function_call";

                        // Extract method arguments
                        $arguments["provided"] = $this->getArguments($tokens, $i+2);

                        // Get method details via reflection
                        $methodDetails = $this->getMethodDetails(
                            $knownInstantiations[$token[1]]["name"],
                            $tokens[$i+2][1]
                        );

                        // Match provided arguments with required parameters
                        $arguments["required"] = $methodDetails["parameters"];
                        foreach($methodDetails["parameters"] as $parameter){
                            if(isset($arguments["provided"][$parameter["index"]])) {
                                $arguments["provided"][$parameter["index"]]["type"] = $parameter["type"];
                            }
                        }

                        // Build method call information
                        $method = [
                            "name" => $methodDetails["name"],
                            "arguments" => $arguments,
                            "returnType" => $methodDetails["returnType"],
                            "attributes" => $methodDetails["attributes"],
                        ];

                        // Add any method-specific issues
                        if($methodDetails["issues"] != []){
                            $issues[] = $methodDetails["issues"];
                        }

                        // Record the method call
                        $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                            "type" => $type,
                            "line" => $token[2],
                            "name" => $token[1],
                            "method" => $method,
                            "issues" => $issues
                        ];
                        return;
                    }

                    // Record general variable usage
                    $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                        "type" => $type,
                        "line" => $token[2],
                        "name" => $token[1],
                        "issues" => $issues
                    ];
                }
            });

            // Execute the second pass of analysis
            $analyser->run($file->getContents());
        }

        return $dependencies;
    }

    /**
     * Check if a class exists and is valid
     *
     * Uses reflection to verify the class exists and can be loaded
     *
     * @param string $className The fully qualified class name to check
     * @return array|null An array of issues if the class cannot be found, null otherwise
     */
    public function checkClass(string $className): ?array
    {
        echo "Analysing class {$className}\n";
        $class = null;

        // Check if we've already created a reflection for this class
        if(array_key_exists($className, $this->reflectionClasses)){
            $class = $this->reflectionClasses[$className];
        } else {
            try {
                // Attempt to create a new reflection class
                $reflection = new ReflectionClass($className);
                $this->reflectionClasses[$className] = $reflection;
                $class = $reflection;
            } catch (Exception $e){
                // Class not found, handled below
            }
        }

        // If the class doesn't exist, return an issue
        if($class === null){
            echo "class $className does not exist\n";
            return [
                "scope" => "class",
                "status" => "error",
                "severity" => "critical",
                "message" => "Class {$className} could not be found, it may have been removed in a new update",
                "similar_classes" => [
                    // TODO add in similar class recommendations.
                ]
            ];
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
    private function getArguments(array $tokens, int $startingIndex): array
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

    /**
     * Get detailed information about a class method using reflection
     *
     * Extracts parameters, return type, attributes, and other metadata
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name to analyze
     * @return array Method details including parameters, return type, and attributes
     */
    public function getMethodDetails(string $className, string $methodName): array
    {
        $issues = [];

        try {
            // Create reflection objects for the class and method
            $reflection = new ReflectionClass($className);
            $method = $reflection->getMethod($methodName);

            // Convert attributes to a serializable format
            $attributesList = [];
            foreach ($method->getAttributes() as $attribute) {
                $attributesList[] = [
                    'name' => $attribute->getName(),
                    'arguments' => $attribute->getArguments()
                ];

                // Check for deprecated methods
                if($attribute->getName() === "Deprecated"){
                    $issues = [
                        "scope" => "method",
                        "status" => "warning",
                        "severity" => "low",
                        "message" => $attribute->getArguments()["message"],
                        "similar_methods" => []
                    ];
                }
            }

            // Extract parameter information
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                // Convert type to string if available
                $type = $parameter->getType();
                $typeString = $type ? $type->getName() : "mixed";

                $parameters[] = [
                    "name" => $parameter->getName(),
                    "type" => $typeString,
                    "defaultValue" => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    "index" => $parameter->getPosition()
                ];
            }

            // Extract return type information
            $returnType = $method->getReturnType();
            $returnTypeString = $returnType ? $returnType->getName() : null;

            // Build and return the method details
            return [
                "name" => $method->getName(),
                "parameters" => $parameters,
                "returnType" => $returnTypeString,
                "attributes" => $attributesList,
                "docComment" => $method->getDocComment(),
                "depreciated" => false, // Note: "deprecated" is misspelled in the original
                "issues" => $issues
            ];
        } catch (Exception $e) {
            // Method not found, return error information
            return [
                "name" => $methodName,
                "parameters" => [],
                "attributes" => [],
                "returnType" => "void",
                "docComment" => null,
                "depreciated" => false, // Note: "deprecated" is misspelled in the original
                "issues" => [
                    "scope" => "method",
                    "status" => "error",
                    "severity" => "critical",
                    "message" => "Method {$methodName} could not be found in class {$className}, it may have been removed in a new update",
                    "similar_methods" => []
                ]
            ];
        }
    }
}