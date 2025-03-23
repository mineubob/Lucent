<?php

namespace Lucent\StaticAnalysis;

use Exception;
use Lucent\Filesystem\File;
use ReflectionClass;

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
     * The namespace to analyze for dependencies
     *
     * @var string
     */
    private string $namespace;

    /**
     * Cache of reflection classes to avoid repeated instantiation
     *
     * @var array<string, ReflectionClass>
     */
    private array $reflectionClasses = [];

    /**
     * Array of dependencies indexed by filename and class name
     *
     * @var array
     */
    public private(set) array $dependencies = [];

    /**
     * Constructor for the Analyser class
     *
     * @param string $namespace The namespace to analyze (defaults to "Lucent")
     */
    public function __construct(string $namespace = "Lucent"){
        $this->namespace = $namespace;
    }

    /**
     * Parse a file to analyze dependencies
     *
     * @param File $file The file object to analyze
     * @return void
     */
    public function parseFile(File $file): void{
        $tokens = token_get_all($file->getContents(), TOKEN_PARSE);
        $this->analyseForDependencies($tokens, $file->getName());
    }

    /**
     * Analyze tokens for dependencies within the specified namespace
     *
     * @param array $tokens Array of tokens from token_get_all
     * @param string $fileName Name of the file being analyzed
     * @return void
     */
    private function analyseForDependencies(array $tokens, string $fileName): void
    {
        foreach ($tokens as $token){
            if($token[0] == T_NAME_QUALIFIED || $token[0] == T_NAME_FULLY_QUALIFIED) {
                if(str_contains($token[1], $this->namespace)) {
                    if(str_starts_with($token[1], '\\')){
                        $token[1] = substr($token[1], 1);
                    }
                    $this->dependencies[$fileName][$token[1]] = [];
                }
            }
        }

        $this->dependencies[$fileName] = $this->getUses($this->dependencies[$fileName], $tokens);
    }

    /**
     * Analyze tokens to find class instantiations, method calls, and usage patterns
     *
     * @param array $dependencies The current dependencies array
     * @param array $tokens Array of tokens from token_get_all
     * @return array Updated dependencies array with usage details
     */
    private function getUses(array $dependencies, array $tokens) : array
    {
        $knownInstantiations = [];

        foreach ($tokens as $i => $token){
            $issues = [];

            switch ($token[0]){
                case T_NEW:
                    $name = $tokens[$i+2][1];

                    if(str_starts_with($name, "\\")) {
                        $name = substr($name, 1);
                    }

                    foreach($dependencies as $className => $value){
                        if(str_ends_with($className, $name) || $className === $name){
                            $variableName = $tokens[$i-4][1];
                            if(($issue = $this->checkClass($className)) !== null) $issues[] = $issue;

                            $found = false;

                            //Check if this is already saved as a use.
                            foreach ($value as $index => $priorSave){
                                if($priorSave['line'] === $token[2]){
                                    $dependencies[$className][$index] = ["type"=>"instantiation","line"=>$tokens[$i+2][2],"name"=> $variableName,"issues"=>$issues];
                                    $found = true;
                                    break;
                                }
                            }

                            if(!$found){
                                $dependencies[$className][] = ["type"=>"instantiation","line"=>$tokens[$i+2][2],"name"=> $variableName,"issues"=>$issues];
                                $knownInstantiations[$variableName] = ["name"=>$className,"line"=>$tokens[$i+2][2]];
                            }
                        }
                    }
                    break;

                case T_VARIABLE:
                    if(array_key_exists($token[1], $knownInstantiations) && $knownInstantiations[$token[1]]["line"] != $token[2]){
                        $type = "use";

                        //if this is a return, set it.
                        if($tokens[$i-2][0] === T_RETURN){
                            $type = "return";
                            $dependencies[$knownInstantiations[$token[1]]["name"]][] = ["type"=>$type,"line"=> $token[2],"name"=>$token[1],"issues"=>$issues];
                            break;
                        }

                        if($tokens[$i+1][1] === "->") {
                            $type = "function_call";

                            $arguments["provided"] = $this->getArguments($tokens,$i+2);
                            $methodDetails = $this->getMethodDetails($knownInstantiations[$token[1]]["name"],$tokens[$i+2][1]);
                            $arguments["required"] = $methodDetails["parameters"];

                            foreach($methodDetails["parameters"] as $parameter){
                                if(isset($arguments["provided"][$parameter["index"]])) {
                                    $arguments["provided"][$parameter["index"]]["type"] = $parameter["type"];
                                }
                            }

                            $method = [
                                "name" => $methodDetails["name"],
                                "arguments"=>$arguments,
                                "returnType" => $methodDetails["returnType"],
                                "attributes" => $methodDetails["attributes"],
                            ];

                            if($methodDetails["issues"] != []){
                                $issues[] = $methodDetails["issues"];
                            }

                            $dependencies[$knownInstantiations[$token[1]]["name"]][] = ["type"=>$type,"line"=> $token[2],"name"=>$token[1], "method"=>$method,"issues"=>$issues];
                            break;
                        }

                        $dependencies[$knownInstantiations[$token[1]]["name"]][] = ["type"=>$type,"line"=> $token[2],"name"=>$token[1],"issues"=>$issues];
                    }
                    break;

                default:
                    if($token[1] == "::"){
                        $name = $tokens[$i-1][1];

                        foreach($dependencies as $className => $value){
                            if(str_ends_with($className, $name)){
                                $issue = $this->checkClass($className);

                                if($issue !== null){
                                    $issues[] = $issue;
                                }

                                if($tokens[$i-3][0] === "="){
                                    $variableName = $tokens[$i-5][1];

                                    $returnType = $this->getMethodDetails($className,$tokens[$i+1][1])["returnType"];

                                    $dependencies[$className][] = ["type"=>"static","line"=>$token[2],"issues"=>$issues,"returnType"=>$returnType];

                                    if($returnType !== $className){
                                        $dependencies[$returnType][] = ["type"=>"instantiation","line"=>$token[2],"name"=> $variableName,"issues"=>$issues];
                                        $knownInstantiations[$variableName] = ["name"=>$returnType,"line"=>$tokens[2]];
                                    }
                                }else{
                                    $dependencies[$className][] = ["type"=>"static","line"=>$token[2],"issues"=>$issues,"returnType"=>$returnType];
                                }

                                break;
                            }
                        }
                    }
            }
        }

        return $dependencies;
    }

    /**
     * Check if a class exists and is valid
     *
     * @param string $className The fully qualified class name to check
     * @return array|null An array of issues if the class cannot be found, null otherwise
     */
    public function checkClass(string $className): ?array
    {
        //echo "Analysing class {$className}\n";
        $class = null;
        if(array_key_exists($className, $this->reflectionClasses)){
            $class = $this->reflectionClasses[$className];
        }else{
            try {
                $reflection = new ReflectionClass($className);
                $this->reflectionClasses[$className] = $reflection;
                $class = $reflection;
            }catch (Exception $e){
                //class is not found, do nothing.
            }
        }

        if($class === null){
            return [
                "scope" => "class",
                "status" => "error",
                "severity" => "critical",
                "message" => "Class {$className} could not be found, it may have been removed in a new update",
                "similar_classes" => [
                    //TODO add in similar class recommendations.
                ]
            ];
        }

        return null;
    }

    /**
     * Extract method arguments from token array
     *
     * @param array $tokens Array of tokens from token_get_all
     * @param int $startingIndex Index where arguments begin in the token array
     * @return array List of arguments with their values, types, and positions
     */
    private function getArguments(array $tokens, int $startingIndex): array
    {
        $arguments = [];
        $i = $startingIndex+2;
        $index = 0;

        while($tokens[$i] != ")"){
            if($tokens[$i] != ","){
                $arguments[] = ["value"=> preg_replace('/^\"(.*)"$/', '$1',$tokens[$i][1]),"type"=>gettype($tokens[$i][1]),"index"=>$index];
                $index++;
            }
            $i++;
        }

        return $arguments;
    }

    /**
     * Get detailed information about a class method using reflection
     *
     * @param string $className The fully qualified class name
     * @param string $methodName The method name to analyze
     * @return array|null Method details including parameters, return type, and attributes
     */
    public function getMethodDetails(string $className, string $methodName): ?array
    {
        $issues = [];

        try {
            $reflection = new ReflectionClass($className);

            $method = $reflection->getMethod($methodName);

            // Convert attributes to a serializable format
            $attributesList = [];
            foreach ($method->getAttributes() as $attribute) {
                $attributesList[] = [
                    'name' => $attribute->getName(),
                    'arguments' => $attribute->getArguments()
                ];

                if($attribute->getName() === "Deprecated"){
                    $issues = [
                        "scope"=> "method",
                        "status"=> "warning",
                        "severity"=> "low",
                        "message"=> $attribute->getArguments()["message"],
                        "similar_methods"=> []
                    ];
                }
            }

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

            // Convert return type to string if available
            $returnType = $method->getReturnType();
            $returnTypeString = $returnType ? $returnType->getName() : null;

            return [
                "name" => $method->getName(),
                "parameters" => $parameters,
                "returnType" => $returnTypeString,
                "attributes" => $attributesList,
                "docComment" => $method->getDocComment(),
                "depreciated" => false,
                "issues" => $issues
            ];
        } catch (Exception $e) {
            // Consider logging the exception for debugging
            // error_log($e->getMessage());
            return [
                "name" => $methodName,
                "parameters" => [],
                "attributes" => [],
                "returnType" => "void",
                "docComment" => null,
                "depreciated" => false,
                "issues" => [
                    "scope"=> "method",
                    "status"=> "error",
                    "severity"=> "critical",
                    "message"=> "Method {$methodName} could not be found in class {$className}, it may have been removed in a new update",
                    "similar_methods"=> []
                ]
            ];
        }
    }
}