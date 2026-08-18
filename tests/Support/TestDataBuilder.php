<?php

namespace Tests\Support;

use Lucent\Validation\Rule;

/**
 * Builds test data for validation tests.
 *
 * Replaces the old FakeServerRequest's validation-specific methods
 * (setInput, all, input, validate, getValidationErrors, passing, failing).
 * This is a pure data builder — it does not extend ServerRequest.
 * For request fabrication in tests, use ServerRequest::create().
 */
class TestDataBuilder
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, string> Validation errors from the most recent validation */
    private array $validationErrors = [];

    /**
     * Set a single input value.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setInput(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Get all input data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Get a single input value.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * No-op for backward compatibility with the old FakeRequest API.
     *
     * @return void
     */
    public function reInitializeRequestData(): void
    {
    }

    /**
     * Validate the data against rules.
     *
     * @param Rule|string|array $rules
     * @return bool
     */
    public function validate(Rule|string|array $rules): bool
    {
        $this->validationErrors = Rule::validateData($this->data, $rules);
        return $this->validationErrors === [];
    }

    /**
     * Get validation errors from the most recent validation.
     *
     * @return array<string, string>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    // ─── Auto-generated test data ───────────────────────────────────────

    /**
     * Generate passing validation data for a rule class.
     *
     * @param string $ruleClass
     * @return $this
     */
    public function passing(string $ruleClass): self
    {
        $ruleInstance = new $ruleClass();
        $this->data = [];

        $rules = $ruleInstance->setup();

        // First pass: handle all non-dependent fields
        foreach ($rules as $field => $fieldRules) {
            if (!$this->hasDependentRule((array) $fieldRules)) {
                $this->data[$field] = $this->generateValidValue($field, (array) $fieldRules);
            }
        }

        // Second pass: handle dependent rules like 'same'
        foreach ($rules as $field => $fieldRules) {
            if ($this->hasDependentRule((array) $fieldRules)) {
                $this->data[$field] = $this->handleDependentRules($field, (array) $fieldRules);
            }
        }

        return $this;
    }

    /**
     * Generate failing validation data for a rule class.
     *
     * @param string $ruleClass
     * @return $this
     */
    public function failing(string $ruleClass): self
    {
        $ruleInstance = new $ruleClass();
        $this->data = [];

        $rules = $ruleInstance->setup();

        foreach ($rules as $field => $fieldRules) {
            $this->data[$field] = $this->generateInvalidValue($field, (array) $fieldRules);
        }

        return $this;
    }

    // ─── Internal helpers ───────────────────────────────────────────────

    private function hasDependentRule(array $rules): bool
    {
        $dependentRules = ['same'];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $ruleName = explode(':', $rule)[0];
                if (in_array($ruleName, $dependentRules)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function handleDependentRules(string $field, array $rules): string
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            [$ruleName, $param] = array_pad(explode(':', $rule), 2, '');

            if ($ruleName === 'same') {
                return $this->data[$param] ?? '';
            }
        }
        return '';
    }

    private function parseRules(array $rules): array
    {
        $constraints = [
            'type' => 'string',
            'min' => 1,
            'max' => 255,
            'regex' => null,
        ];

        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            [$ruleName, $param] = array_pad(explode(':', $rule), 2, '');

            switch ($ruleName) {
                case 'min':
                case 'min_num':
                    if ($ruleName === 'min_num') {
                        $constraints['type'] = 'numeric';
                    }
                    $constraints['min'] = max((int) $param, $constraints['min']);
                    break;

                case 'max':
                case 'max_num':
                    if ($ruleName === 'max_num') {
                        $constraints['type'] = 'numeric';
                    }
                    $constraints['max'] = min((int) $param, $constraints['max']);
                    break;

                case 'regex':
                    $constraints['regex'] = $param;
                    if (str_contains($param, 'email')) {
                        $constraints['type'] = 'email';
                    } elseif (str_contains($param, 'password')) {
                        $constraints['type'] = 'password';
                    }
                    break;
            }
        }

        return $constraints;
    }

    private function generateValidValue(string $field, array $rules): string
    {
        $constraints = $this->parseRules($rules);
        $fieldType = $this->determineFieldType($field, $constraints);

        return match ($fieldType) {
            'email' => $this->generateEmail(),
            'password' => $this->generatePassword($constraints['min'], $constraints['max']),
            'numeric' => (string) random_int($constraints['min'], $constraints['max']),
            'date' => date('Y-m-d'),
            default => $this->generateString($constraints['min'], $constraints['max']),
        };
    }

    private function generateInvalidValue(string $field, array $rules): string
    {
        $constraints = $this->parseRules($rules);
        $fieldType = $this->determineFieldType($field, $constraints);

        return match ($fieldType) {
            'email' => 'not-an-email',
            'password' => 'weak',
            'numeric' => 'not-a-number',
            'date' => 'not-a-date',
            default => '',
        };
    }

    private function determineFieldType(string $field, array $constraints): string
    {
        if ($constraints['type'] !== 'string') {
            return $constraints['type'];
        }

        if (str_contains($field, 'email')) {
            return 'email';
        }
        if (str_contains($field, 'password')) {
            return 'password';
        }
        if (str_contains($field, 'date')) {
            return 'date';
        }
        if (str_contains($field, 'number') || str_contains($field, 'amount')) {
            return 'numeric';
        }

        return 'string';
    }

    private function generateEmail(): string
    {
        $domains = ['example.com', 'test.org', 'fake.net'];
        $username = $this->generateString(5, 10);
        return $username . '@' . $domains[array_rand($domains)];
    }

    private function generateString(int $min, int $max): string
    {
        $length = random_int($min, min($max, 50));
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $result;
    }

    private function generatePassword(int $min, int $max): string
    {
        $length = max($min, 8);
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '0123456789';
        $special = '!@#$%^&*';

        return
            $upper[random_int(0, strlen($upper) - 1)]
            . $lower[random_int(0, strlen($lower) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)]
            . $special[random_int(0, strlen($special) - 1)]
            . $this->generateString(max(0, $length - 4), max(0, $length - 4));
    }
}
