<?php

namespace Lucent\StaticAnalysis;

use Exception;
use Lucent\Filesystem\File;
use ReflectionClass;

/**
 * Class DependencyAnalyser
 *
 * Analyzes PHP files to identify dependencies, instantiations, and method calls
 * within a specified namespace. Use's static analysis to build a dependency graph.
 *
 * The analyzer works in two passes:
 * 1. First pass: Collects all dependencies (imports/use statements)
 * 2. Second pass: Analyzes class instantiations, method calls, and variable usage
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
     * @var array
     */
    public private(set) array $dependencies;

    /**
     * Cache of reflection classes to avoid repeated instantiation
     *
     * @var array
     */
    private array $reflectionClasses = [];

    /**
     * Collection of files to analyze
     *
     * @var array
     */
    private array $files;

    /**
     * Constructor for the DependencyAnalyser class
     *
     * @param string $namespace The namespace to analyze (defaults to "Lucent")
     */
    public function __construct(string $namespace = "Lucent")
    {
        $this->namespace = $namespace;
        $this->files = [];
        $this->dependencies = [];
    }

    /**
     * Add files to be parsed during analysis
     *
     * @param File|array $file Single file or array of files to analyze
     * @return void
     */
    public function parseFiles(File|array $file): void
    {
        // Add a single file to the list
        if ($file instanceof File) {
            $this->files[] = $file;
        }

        // Add an array of files to the list
        if (getType($file) === "array") {
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
        $dependencies = [];         // Stores the complete dependency graph

        // Process each file independently
        foreach ($this->files as $file) {

            $knownInstantiations = [];  // Tracks variables that hold instances of classes
            $as = [];                  // Maps aliases to fully qualified class names

            // ==========================================
            // FIRST PASS: Detect class imports and dependencies
            // ==========================================
            $analyser->onToken([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], function ($i, $token, $tokens) use (&$file, &$dependencies, &$as) {
                // Check if the qualified name is in our target namespace
                if (str_contains($token[1], $this->namespace)) {
                    // Remove leading backslash if present (normalize class name)
                    if (str_starts_with($token[1], '\\')) {
                        $token[1] = substr($token[1], 1);
                    }

                    // Track class aliases (e.g., "use Namespace\Class as Alias;")
                    if(is_array($tokens[$i+2])) {
                        if ($tokens[$i + 2][1] === "as") {
                            $as[$tokens[$i + 4][1]] = $token[1];
                        }
                    }

                    // Register the dependency for this file
                    $dependencies[$file->getName()][$token[1]] = [];
                }
            });

            // Run first pass to collect dependencies only
            $analyser->run($file->getContents());
            $analyser->clear();

            // ==========================================
            // SECOND PASS: Analyze usage patterns
            // ==========================================

            // 1. Detect variable usage for tracked instantiations
            $analyser->onToken(T_VARIABLE, function ($i, $token, $tokens) use (&$file, &$dependencies, &$knownInstantiations) {
                // Only process variables we've tracked from instantiations
                if (array_key_exists($token[1], $knownInstantiations) && $knownInstantiations[$token[1]]["line"] != $token[2]) {
                    // Check if this is an instantiation line (token is part of an assignment with 'new')
                    // This prevents recording both "use" and "instantiation" for the same line
                    $isInstantiation = false;
                    for ($j = $i + 1; $j < count($tokens) && $j < $i + 5; $j++) {
                        if ($tokens[$j][0] === T_NEW) {
                            $isInstantiation = true;
                            break;
                        }
                    }

                    // Skip recording "use" if this is an instantiation
                    if ($isInstantiation) {
                        return;
                    }

                    $type = "use";     // Default usage type
                    $issues = [];      // Tracks any issues with this usage

                    // Check if this is a return statement (e.g., "return $variable;")
                    if ($tokens[$i-2][0] === T_RETURN) {
                        $type = "return";
                        $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                            "type" => $type,
                            "line" => $token[2],
                            "name" => $token[1],
                            "token_id" => $i,
                            "issues" => $issues
                        ];
                        return;
                    }

                    // Check if this is a method call on the object (e.g., "$variable->method()")

                    if((is_array($tokens[$i+1]) && $tokens[$i+1][1] === "->") || $tokens[$i+1] === "->") {

                        $type = "function_call";

                        // Extract method arguments
                        $arguments["provided"] = ReflectionHelpers::getArguments($tokens, $i+2);

                        // Get method details via reflection
                        $methodDetails = $this->getMethodDetails(
                            $knownInstantiations[$token[1]]["name"],
                            $tokens[$i+2][1]
                        );

                        // Match provided arguments with required parameters
                        $arguments["required"] = $methodDetails["parameters"];
                        foreach ($methodDetails["parameters"] as $parameter) {
                            if (isset($arguments["provided"][$parameter["index"]])) {
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
                        if ($methodDetails["issues"] != []) {
                            $issues = array_merge($issues, $methodDetails["issues"]);
                        }

                        // Record the method call
                        $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                            "type" => $type,
                            "line" => $token[2],
                            "name" => $token[1],
                            "method" => $method,
                            "token_id" => $i,
                            "issues" => $issues
                        ];

                        // Check for method chaining (when a method returns the same class type)
                        if($method["returnType"] === $knownInstantiations[$token[1]]["name"]){
                            $chain = $this->processChain($knownInstantiations[$token[1]]["name"], $token[1], $i, $tokens);

                            foreach ($chain as $call) {
                                $call["issues"] = array_merge($call["issues"], $issues);
                                $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = $call;
                            }
                        }

                        return;
                    }

                    // Check for output operations (echo, print, etc.)
                    $outputType = $this->processOutput($i, $tokens);
                    if($outputType !== null){
                        if(trim($outputType) === ""){
                            $outputType = "unknown";
                        }

                        $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                            "type" => "output",
                            "output_type" => $outputType,
                            "line" => $token[2],
                            "name" => $token[1],
                            "token_id" => $i,
                            "issues" => $issues
                        ];
                        return;
                    }

                    // Record general variable usage
                    $dependencies[$file->getName()][$knownInstantiations[$token[1]]["name"]][] = [
                        "type" => $type,
                        "line" => $token[2],
                        "name" => $token[1],
                        "token_id" => $i,
                        "issues" => $issues
                    ];
                }
            });

            // 2. Detect static method calls with "::" operator
            $analyser->onToken("::", function ($i, $token, $tokens) use (&$file, &$dependencies, &$knownInstantiations) {
                $issues = [];
                $name = $tokens[$i-1][1];

                foreach ($dependencies[$file->getName()] as $className => $value) {
                    // Match by class name (with or without namespace)
                    if (str_ends_with($className, $name)) {
                        // Check if the class exists and collect any issues
                        $issue = $this->checkClass($className);
                        if ($issue !== null) {
                            $issues[] = $issue;
                        }

                        // Check if this is an assignment operation (e.g., "$var = Class::method()")
                        if ($tokens[$i-3][0] === "=") {
                            $variableName = $tokens[$i-5][1];

                            // Get the return type of the method being called
                            $returnType = $this->getMethodDetails($className, $tokens[$i+1][1])["returnType"];
                            // Extract method arguments
                            $arguments["provided"] = ReflectionHelpers::getArguments($tokens, $i+2);

                            // Get method details via reflection
                            $methodDetails = $this->getMethodDetails(
                                $className,
                                $tokens[$i+1][1]
                            );

                            // Match provided arguments with required parameters
                            $arguments["required"] = $methodDetails["parameters"];

                            foreach ($methodDetails["parameters"] as $parameter) {
                                if (isset($arguments["provided"][$parameter["index"]])) {
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
                            if ($methodDetails["issues"] != []) {
                                $issues = array_merge($issues, $methodDetails["issues"]);
                            }
                            // Record the static method call
                            $dependencies[$file->getName()][$className][] = [
                                "type" => "static_function_call",
                                "line" => $token[2],
                                "token_id" => $i,
                                "method" => $method,
                                "issues" => $issues
                            ];

                            // If the method returns an object, track this as a new instantiation
                            if ($returnType !== $className) {
                                if (in_array($returnType, Analyser::$PRIMITIVE_TYPES)) {
                                    // Handle primitive return types
                                    // echo "\n $className->$variableName is function call!\n";
                                    $dependencies[$file->getName()][$className][] = [
                                        "type" => "static_function_call",
                                        "line" => $token[2],
                                        "name" => $variableName,
                                        "token_id" => $i,
                                        "issues" => $issues,
                                        "returnType" => $returnType
                                    ];
                                } else {
                                    // Handle object return types (factory pattern)
                                    $dependencies[$file->getName()][$returnType][] = [
                                        "type" => "instantiation",
                                        "line" => $token[2],
                                        "name" => $variableName,
                                        "token_id" => $i,
                                        "issues" => $issues
                                    ];

                                    // Track this variable for later usage analysis
                                    $knownInstantiations[$variableName] = [
                                        "name" => $returnType,
                                        "line" => $tokens[2],
                                        "token" => $token[0]
                                    ];

                                    //Check if this is a chain
                                    $chain = $this->processChain($returnType, $variableName, $i, $tokens);
                                    foreach ($chain as $call) {
                                        $call["issues"] = array_merge($call["issues"], $issues);
                                        $dependencies[$file->getName()][$returnType][] = $call;
                                    }
                                }
                            }
                        } else {
                            // Record the static method call without assignment
                            $dependencies[$file->getName()][$className][] = [
                                "type" => "static_function_call",
                                "line" => $token[2],
                                "token_id" => $i,
                                "issues" => $issues,
                                "returnType" => "void"
                            ];
                        }
                        break;
                    }
                }
            }, Analyser::MATCH_VALUE);

            // 3. Detect class instantiations with "new" keyword
            $analyser->onToken(T_NEW, function ($i, $token, $tokens) use (&$as, &$dependencies, &$knownInstantiations, &$file) {


                if(!is_array($token)){
                    return;
                }

                $issues = [];
                $name = $tokens[$i+2][1];

                // Remove leading backslash if present (normalize class name)
                if (str_starts_with($name, "\\")) {
                    $name = substr($name, 1);
                }

                // Check each known dependency to see if this instantiation matches
                foreach ($dependencies[$file->getName()] as $className => $value) {
                    // Match by alias, or class name (with or without namespace)
                    if ((isset($as[$name]) && $as[$name] == $className) ||
                        str_ends_with($className, $name) ||
                        $className == $name) {

                        $variableName = $tokens[$i-4][1];

                        // Check if the class exists and collect any issues
                        if (($issue = $this->checkClass($className)) !== null) {
                            $issues[] = $issue;
                        }

                        $found = false;

                        // Check if this instantiation was already recorded
                        foreach ($value as $index => $priorSave) {

                            if ($priorSave['line'] === $token[2] && $priorSave['token_id'] === $token[0]) {
                                $dependencies[$file->getName()][$className][] = [
                                    "type" => "instantiation",
                                    "line" => $tokens[$i+2][2],
                                    "name" => $variableName,
                                    "token_id" => $i,
                                    "issues" => $issues
                                ];
                                $found = true;
                                break;
                            }
                        }

                        // Add new instantiation if not already recorded
                        if (!$found) {
                            $dependencies[$file->getName()][$className][] = [
                                "type" => "instantiation",
                                "line" => $tokens[$i+2][2],
                                "name" => $variableName,
                                "token_id" => $i,
                                "issues" => $issues
                            ];

                            // Track this variable for later usage analysis
                            $knownInstantiations[$variableName] = [
                                "name" => $className,
                                "line" => $tokens[$i+2][2],
                                "token" => $token[0],
                            ];
                        }
                    }
                }
            });

            // Execute the second pass of analysis
            $analyser->run($file->getContents());
        }

        $this->dependencies = $dependencies;

        return $dependencies;
    }

    /**
     * Check if a class exists and is valid
     *
     * Uses reflection to verify the class exists and can be loaded.
     * Also checks if the class is marked as deprecated.
     *
     * @param string $className The fully qualified class name to check
     * @return array|null An array of issues if the class has problems, null otherwise
     */
    public function checkClass(string $className): ?array
    {
        // echo "Analysing class {$className}\n";
        $class = null;

        // Check if we've already created a reflection for this class
        if (array_key_exists($className, $this->reflectionClasses)) {
            $class = $this->reflectionClasses[$className];
        } else {
            try {
                // Attempt to create a new reflection class
                $reflection = new ReflectionClass($className);
                $this->reflectionClasses[$className] = $reflection;
                $class = $reflection;

                // Check if the class is marked as deprecated
                if (str_contains($reflection->getDocComment(), "@deprecated")) {
                    return [
                        "scope" => "class",
                        "status" => "warning",
                        "severity" => "low",
                        "message" => "Deprecated ".ReflectionHelpers::extractDocTagMessage($reflection->getDocComment(), "deprecated"),
                        "similar_classes" => [
                            // TODO add in similar class recommendations.
                        ]
                    ];
                }
            } catch (Exception $e) {
                // Class not found, handled below
            }
        }

        // If the class doesn't exist, return an issue
        if ($class === null) {
            // echo "class $className does not exist\n";
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
     * Get detailed information about a class method using reflection
     *
     * Extracts parameters, return type, attributes, and other metadata.
     * Also checks for deprecated methods and classes.
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name to analyze
     * @return array Method details including parameters, return type, attributes and issues
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

                // Check for deprecated methods via attributes
                if ($attribute->getName() === "Deprecated") {
                    $issues[] = [
                        "scope" => "method",
                        "status" => "warning",
                        "severity" => "low",
                        "message" => "Deprecated ".$attribute->getArguments()["message"],
                        "similar_methods" => []
                    ];
                }
            }

            // Check for deprecated methods via docblock
            if (str_contains($method->getDocComment(), "@deprecated")) {
                $issues[] = [
                    "scope" => "method",
                    "status" => "warning",
                    "severity" => "low",
                    "message" => "Deprecated ".ReflectionHelpers::extractDocTagMessage($method->getDocComment(), "deprecated"),
                    "similar_methods" => []
                ];
            }

            // Inject class warning too if the class is deprecated
            if (str_contains($reflection->getDocComment(), "@deprecated")) {
                $issues[] = [
                    "scope" => "class",
                    "status" => "warning",
                    "severity" => "low",
                    "message" => ReflectionHelpers::extractDocTagMessage($reflection->getDocComment(), "deprecated"),
                    "similar_classes" => []
                ];
            }

            // Extract parameter information
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                // Convert type to string if available
                $type = $parameter->getType();
                $name = $parameter->getPosition();

                if (method_exists($parameter, "getName")) {
                    $name = $parameter->getName();
                }

                $typeString = ReflectionHelpers::getTypeString($type, $parameter);

                $parameters[] = [
                    "name" => $name,
                    "type" => $typeString,
                    "defaultValue" => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    "index" => $parameter->getPosition()
                ];
            }

            // Extract return type information
            $returnType = ReflectionHelpers::getTypeString($method->getReturnType(), $method);

            // Build and return the method details
            return [
                "name" => $method->getName(),
                "parameters" => $parameters,
                "returnType" => $returnType,
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
                "returnType" => "unknown",
                "attributes" => [],
                "docComment" => null,
                "depreciated" => false, // Note: "deprecated" is misspelled in the original
                "issues" => [[
                    "scope" => "method",
                    "status" => "error",
                    "severity" => "critical",
                    "message" => "Method {$methodName} could not be found in class {$className}, it may have been removed in a new update",
                    "similar_methods" => []
                ]]
            ];
        }
    }

    /**
     * Process method chaining patterns
     *
     * Extracts all method calls in a chain (e.g., $obj->method1()->method2()->method3())
     *
     * @param string $className The class name of the object being chained
     * @param string $variableName The variable name of the object
     * @param int $start The token index to start processing from
     * @param array $tokens The token array from the parser
     * @return array List of function calls in the chain
     */
    private function processChain(string $className, string $variableName, int $start, array $tokens): array
    {
        $running = true;
        $i = $start;
        $uses = [];

        while($running){
            if ((is_array($tokens[$i]) && $tokens[$i][1] === "->") ||
                ($tokens[$i] === "->")) {

                // Make sure $tokens[$i+1] is an array before accessing its elements
                if (is_array($tokens[$i+1])) {
                    $methodName = $tokens[$i+1][1];
                    $lineNumber = $tokens[$i+1][2];

                    $method = $this->getMethodDetails($className, $methodName);

                    $uses[] = [
                        "type" => "function_call",
                        "line" => $lineNumber,
                        "name" => $variableName,
                        "method" => $method,
                        "token_id" => $i,
                        "issues" => []
                    ];
                }
            }

            if($tokens[$i] === ";"){
                $running = false;
            }

            $i++;
        }

        return $uses;
    }

    /**
     * Process output operations
     *
     * Detects if a variable is being used in an output operation (echo, print, etc.)
     *
     * @param int $start The token index to start processing from
     * @param array $tokens The token array from the parser
     * @return string|null The type of output operation or null if none found
     */
    private function processOutput(int $start, array $tokens): ?string
    {
        $i = $start;
        $output = null;

        while (true) {
            if ($i <= 0) {
                // Safety check to prevent going before the start of the array
                break;
            }

            // Check if we've reached an opening parenthesis
            if ($tokens[$i] === '(') {
                // Check if the previous token is an output function, and it's an array token
                if ($i > 0 && is_array($tokens[$i-1]) && in_array($tokens[$i-1][1], Analyser::$OUTPUT_TYPES)) {
                    $output = $tokens[$i-1][1];
                }
                break;
            }

            // Check if the previous token is an output function, and it's an array token
            if ($i > 0 && is_array($tokens[$i-1]) && in_array($tokens[$i-1][1], Analyser::$OUTPUT_TYPES)) {
                $output = $tokens[$i-1][1];
                break;
            }

            $i--;
        }

        return $output;
    }

    public function printCompatibilityCheck(): void
    {
        // ANSI color codes as variables, not constants
        $COLOR_RED = "\033[31m";
        $COLOR_YELLOW = "\033[33m";
        $COLOR_BLUE = "\033[36m";
        $COLOR_BOLD = "\033[1m";
        $COLOR_RESET = "\033[0m";

        // Count the issues by file
        $fileIssues = [];
        $totalDeprecated = 0;
        $totalRemoved = 0;

        $dependencies = $this->dependencies;

        // Show header
        echo $COLOR_BOLD . "UPDATE COMPATIBILITY" . $COLOR_RESET . PHP_EOL;
        echo "============================" . PHP_EOL;

        foreach ($dependencies as $fileName => $file) {
            $fileHasIssues = false;
            $fileDeprecations = 0;
            $fileRemovals = 0;

            foreach ($file as $dependencyName => $dependency) {
                foreach ($dependency as $use) {
                    if (!empty($use["issues"])) {
                        // Count issues by type
                        foreach ($use["issues"] as $issue) {
                            if (isset($issue["status"])) {
                                if ($issue["status"] === "error") {
                                    $fileRemovals++;
                                    $totalRemoved++;
                                } elseif ($issue["status"] === "warning") {
                                    $fileDeprecations++;
                                    $totalDeprecated++;
                                }
                            }
                        }

                        $fileHasIssues = true;

                        // Show file name if this is the first issue in the file
                        if (!isset($fileIssues[$fileName])) {
                            echo $COLOR_BOLD . $fileName . $COLOR_RESET . PHP_EOL;
                            $fileIssues[$fileName] = true;
                        }

                        // Show the dependency usage
                        $lineInfo = "  Line " . str_pad($use["line"], 4, ' ', STR_PAD_LEFT) . ": ";
                        echo $lineInfo . $COLOR_BLUE . $dependencyName . $COLOR_RESET;

                        // Show method if applicable
                        if (isset($use["method"]) && isset($use["method"]["name"])) {
                            echo "->" . $use["method"]["name"] . "()";
                        }

                        echo PHP_EOL;

                        // Show each issue with appropriate color and clear labeling
                        foreach ($use["issues"] as $issue) {
                            $color = $COLOR_YELLOW; // Default for warnings
                            $issueType = "DEPRECATED";

                            if (isset($issue["status"]) && $issue["status"] === "error") {
                                $color = $COLOR_RED;
                                $issueType = "REMOVED";
                            }

                            // Format message
                            $message = $issue["message"] ?? "Unknown issue";
                            $since = "";

                            // Extract version info if available in the message
                            if (preg_match('/since\s+version\s+([0-9.]+)/i', $message, $matches)) {
                                $since = " (since v" . $matches[1] . ")";
                            } else if (preg_match('/since\s+v([0-9.]+)/i', $message, $matches)) {
                                $since = " (since v" . $matches[1] . ")";
                            }

                            // Show scope if provided
                            $scopeText = "";
                            if (isset($issue["scope"])) {
                                $scopeText = " " . $issue["scope"];
                            }

                            echo "    " . $color . "⚠ " . $issueType . $scopeText . $since . ": " . $COLOR_RESET . $message . PHP_EOL;
                        }
                    }
                }
            }

            // Show file summary if issues were found
            if ($fileHasIssues) {
                echo PHP_EOL;
            }
        }

        // Show grand total
        if ($totalDeprecated > 0 || $totalRemoved > 0) {
            echo "============================" . PHP_EOL;
            echo $COLOR_BOLD . "SUMMARY: " . $COLOR_RESET;

            if ($totalRemoved > 0) {
                echo $COLOR_RED . $totalRemoved . " removed" . $COLOR_RESET;
                if ($totalDeprecated > 0) {
                    echo ", ";
                }
            }

            if ($totalDeprecated > 0) {
                echo $COLOR_YELLOW . $totalDeprecated . " deprecated" . $COLOR_RESET;
            }

            echo " components found in " . count($fileIssues) . " files" . PHP_EOL;
            echo "Update your code to ensure compatibility with the latest Lucent version" . PHP_EOL;
        } else {
            echo $COLOR_BOLD . "No compatibility issues found! Your code is up to date." . $COLOR_RESET . PHP_EOL;
        }
    }
}