<?php

namespace Lucent\Validation;

use Lucent\Facades\Regex;
use Lucent\Http\Request;
use ReflectionClass;
use ReflectionMethod;
use ReflectionType;

abstract class Rule
{

    protected array $customRegexPatterns = [];

    protected(set) array $rules = [];

    protected(set) array $messages = [
        "min" => ":attribute must be at least :min characters",
        "max" => ":attribute may not be greater than :max characters",
        "min_num" => ":attribute must be greater than :min",
        "max_num" => ":attribute may not be less than :max",
        "same" => ":attribute and :other must match",
        "regex" => ":attribute does not match the required format"
    ];

    protected ?Request $currentRequest = null;

    public abstract function setup() : array;

    private function regex(string $key, string $value): bool
    {
        $patterns = $this->getRegexPatterns();

        if(!isset($patterns[$key])){
            throw new \InvalidArgumentException("Regex {$key} does not exists");
        }

        if($patterns[$key]["message"] !== null){
            $this->messages["regex"] = $patterns[$key]["message"];
        }

        return preg_match($patterns[$key]["pattern"], $value);
    }

    private function min(int $i, string $value): bool
    {
        return strlen($value) >= $i;
    }

    private function min_num(int $i, string $value): bool
    {
        if (!is_numeric($value))
            return false;

        return intval($value, 10) >= $i;
    }

    private function max(int $i, string $value): bool
    {
        return strlen($value) <= $i;
    }

    private function max_num(int $i, string $value): bool
    {
        if (!is_numeric($value))
            return false;

        return intval($value, 10) <= $i;
    }

    private function same(string $first, string $second): bool
    {
        return $first === $second;
    }

    private function unique(string $table, string $column, string $value): bool
    {

        $namespace = 'App\\Models\\';
        $class = new ReflectionClass($namespace . $table);
        $instance = $class->newInstanceWithoutConstructor();

        $model = $instance::where($column, $value)->getFirst();

        if ($model !== null) {
            $this->currentRequest->context[$table] = $model;
        }

        return $model === null;
    }

    public function validate(array $data): array
    {
        $output = [];

        if ($this->rules === []) {
            $this->rules = $this->setup();
        }

        foreach ($this->rules as $key => $rules) {

            //Check if only a single rule has been provided as a string, if so transform it into an array.
            if (gettype($rules) === "string") {
                $new = [];
                array_push($new, $rules);
                $rules = $new;
            }

            foreach ($rules as $rule) {

                $parts = explode(":", $rule);
                $methodName = $parts[0];
                $isNegatedRule = false;

                //If the key is not set then set it to a blanks string.
                if (!isset($data[$key])) {
                    $data[$key] = "";
                }

                //Check if we are passing a '!', if so set negated to true
                if(str_starts_with($methodName, "!")){
                    $methodName = substr($methodName, 1);
                    $isNegatedRule = true;
                }

                if($methodName === "unique" || $methodName === "!unique"){
                    $parts[] = $key;
                }

                if (method_exists($this, $methodName)) {

                    $method = new ReflectionMethod($this, $methodName);
                    $params = $method->getParameters();

                    $parts = array_slice($parts, 1);
                    $parts = array_merge($parts,[$data[$key]]);

                    $args = [];

                    foreach ($params as $index => $param) {
                        if (isset($parts[$index])) {
                            $value = $this->processVariable($parts[$index],$data);
                            $args[] = $this->castToType($value, $param->getType());
                        } else {
                            // If parameter has default value and no corresponding value provided
                            if ($param->isDefaultValueAvailable()) {
                                $args[] = $param->getDefaultValue();
                            }
                        }
                    }

                    // Store the result of method invocation to avoid calling it twice
                    $outcome = $method->invokeArgs($this, $args);

                    // If not is true, we want to flip the outcome
                    if ((!$outcome && !$isNegatedRule) || ($outcome && $isNegatedRule)) {
                        if(array_key_exists($method->getName(), $this->messages)) {
                            $output[$key] = $this->messages[$method->getName()];
                        } else {
                            $output[$key] = $key . " failed " . str_replace('_', ' ', $method->getName()) . " validation rule";
                        }
                    }

                } else {
                    // Unknown validation rule - throw exception
                    throw new \InvalidArgumentException("Unknown validation rule '{$parts[0]}' in field '{$key}'");
                }
            }
        }

        return $output;
    }

    //This is used by the documentation generator to get a list of all the rules
    public function getRules(): array
    {
        return $this->setup();
    }

    private function getVar(string $rule): string
    {
        if (str_contains($rule, ":")) {
            return explode(":", $rule)[1];
        } else {
            return $rule;
        }
    }

    public function setCallingRequest(Request $request): void
    {
        $this->currentRequest = $request;
    }

    private function castToType($value, ?ReflectionType $type): mixed
    {
        if (!$type) {
            return $value;
        }

        $typeName = $type->getName();

        return match($typeName) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'string' => (string)$value,
            'array' => (array)$value,
            default => $value
        };
    }

    private function processVariable($value, array $data) {

        if(str_starts_with($value, "@")) {

            $fieldName = substr($value, 1);

            return $data[$fieldName] ?? null;

        } else {

            return $value;
        }
    }

    public function getRegexPatterns(): array
    {
        return array_merge(Regex::all(), $this->customRegexPatterns);
    }

    public function addRegexPattern(string $name, string $pattern, ?string $message = null): void
    {
        $this->customRegexPatterns[$name] = ["pattern"=>$pattern, "message"=>$message];
    }
}