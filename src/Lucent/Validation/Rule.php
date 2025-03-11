<?php

namespace Lucent\Validation;

use Lucent\Http\Request;
use ReflectionClass;

abstract class Rule
{

    private string $password_regex = '^(?=.*[a-z])(?=.*[A-Z]).{8,}$^';
    public protected(set) array $rules = [];

    protected ?Request $currentRequest = null;


    public abstract function setup();

    private function regex(string $key, string $value): bool
    {
        return match ($key) {
            "email" => filter_var($value, FILTER_VALIDATE_EMAIL),
            "password" => preg_match($this->password_regex, $value),
            default => false,
        };

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

    private function unique(string $table, string $column, string $value, bool $not = false): bool
    {

        $namespace = 'App\\Models\\';
        $class = new ReflectionClass($namespace . $table);
        $instance = $class->newInstanceWithoutConstructor();

        $model = $instance::where($column, $value)->getFirst();

        if ($model !== null) {
            $this->currentRequest?->cacheModel($table, $model);
        }

        if ($not) {
            return $model !== null;
        } else {
            return $model === null;
        }
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

                //If the key is not set then set it to a blanks string.
                if (!isset($data[$key])) {
                    $data[$key] = "";
                }

                switch ($parts[0]) {
                    case "regex":
                        if (!$this->regex($parts[1], $data[$key])) {
                            $output[$key] = ucfirst($key . " must be a valid " . $this->getVar($rule));
                        }
                        break;
                    case "min":
                        if (!$this->min((int) $parts[1], $data[$key])) {
                            $output[$key] = ucfirst($key . " must be more than " . $this->getVar($rule) . " characters");
                        }
                        break;
                    case "min_num":
                        if (!$this->min_num((int) $parts[1], $data[$key])) {
                            $output[$key] = ucfirst($key . " must be more than " . $this->getVar($rule) . " characters");
                        }
                        break;
                    case "max":
                        if (!$this->max((int) $parts[1], $data[$key])) {
                            $output[$key] = ucfirst($key . " must be less than " . $this->getVar($rule));
                        }
                        break;
                    case "max_num":
                        if (!$this->max_num((int) $parts[1], $data[$key])) {
                            $output[$key] = ucfirst($key . " must be less than " . $this->getVar($rule));
                        }
                        break;
                    case "same":
                        if (!$this->same($data[$key], $data[$parts[1]])) {
                            $output[$key] = $key . " must be the same as " . $this->getVar($rule);
                        }
                        break;
                    case "!unique":
                        if (!isset($data[$key])) {
                            $output[$key] = $parts[1] . " must match " . $rule;
                            break;
                        }
                        if (!$this->unique($parts[1], $key, $data[$key], true)) {
                            $output[$key] = $parts[1] . " must match " . $rule;
                        }
                        break;
                    case "unique":
                        if (!isset($data[$key])) {
                            $output[$key] = $parts[1] . " must match " . $rule;
                            break;
                        }
                        if (!$this->unique($parts[1], $key, $data[$key])) {
                            $output[$key] = $parts[1] . " must match " . $rule;
                        }
                        break;
                }
            }
        }

        return $output;
    }

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
}