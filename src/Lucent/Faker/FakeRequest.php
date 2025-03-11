<?php

namespace Lucent\Faker;

use Lucent\Http\Request;

class FakeRequest extends Request
{
    private array $fakeData = [];

    public function all(): array
    {
        return $this->fakeData;
    }

    public function setInput(string $key, mixed $value): void
    {
       $this->fakeData[$key] = $value;
    }

    /**
     * Generate passing validation data
     */
    public function passing(string $ruleClass): self
    {
        $ruleInstance = new $ruleClass();
        $this->fakeData = [];

        // Get rules setup from the Rule class
        $rules = $ruleInstance->setup();

        // First pass: handle all non-dependent fields
        foreach ($rules as $field => $fieldRules) {
            if (!$this->hasDependentRule($fieldRules)) {
                $this->fakeData[$field] = $this->generateValidValue($field, (array) $fieldRules);
            }
        }

        // Second pass: handle dependent rules like 'same'
        foreach ($rules as $field => $fieldRules) {
            if ($this->hasDependentRule($fieldRules)) {
                $this->fakeData[$field] = $this->handleDependentRules($field, (array) $fieldRules);
            }
        }

        return $this;
    }

    /**
     * Generate failing validation data
     */
    public function failing(string $ruleClass): self
    {
        $ruleInstance = new $ruleClass();
        $this->fakeData = [];

        // Get rules setup from the Rule class
        $rules = $ruleInstance->setup();

        foreach ($rules as $field => $fieldRules) {
            $this->fakeData[$field] = $this->generateInvalidValue($field, (array) $fieldRules);
        }

        return $this;
    }

    private function hasDependentRule($rules): bool
    {
        $rules = (array) $rules;
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
            if (!is_string($rule))
                continue;

            [$ruleName, $param] = array_pad(explode(':', $rule), 2, '');

            if ($ruleName === 'same') {
                return $this->fakeData[$param] ?? '';
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
            'regex' => null
        ];

        foreach ($rules as $rule) {
            if (!is_string($rule))
                continue;

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
            default => $this->generateString($constraints['min'], $constraints['max'])
        };
    }

    private function generateInvalidValue(string $field, array $rules): string
    {
        $constraints = $this->parseRules($rules);
        $fieldType = $this->determineFieldType($field, $constraints);

        return match ($fieldType) {
            'email' => 'not-an-email',
            'password' => 'weak',  // Deliberately too short/simple
            'numeric' => 'not-a-number',
            'date' => 'not-a-date',
            default => ''  // Empty string fails most validations
        };
    }

    private function determineFieldType(string $field, array $constraints): string
    {
        // First check constraints
        if ($constraints['type'] !== 'string') {
            return $constraints['type'];
        }

        // Then check field name patterns
        if (str_contains($field, 'email'))
            return 'email';
        if (str_contains($field, 'password'))
            return 'password';
        if (str_contains($field, 'date'))
            return 'date';
        if (str_contains($field, 'number') || str_contains($field, 'amount'))
            return 'numeric';

        return 'string';
    }

    private function generateEmail(): string
    {
        $domains = ['example.com', 'test.org', 'fake.net'];
        $username = $this->generateString(5, 10);
        return $username . '@' . $domains[array_rand($domains)];
    }

    private function generatePassword(int $minLength = 8, int $maxLength = 20): string
    {
        $length = random_int(max(8, $minLength), min(20, $maxLength));

        // Character sets
        $chars = [
            'upper' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'lower' => 'abcdefghijklmnopqrstuvwxyz',
            'numeric' => '0123456789',
            'special' => '!@#$%^&*'
        ];

        // Ensure at least one of each type
        $password = [
            $chars['upper'][random_int(0, strlen($chars['upper']) - 1)],
            $chars['lower'][random_int(0, strlen($chars['lower']) - 1)],
            $chars['numeric'][random_int(0, strlen($chars['numeric']) - 1)],
            $chars['special'][random_int(0, strlen($chars['special']) - 1)]
        ];

        // Fill remaining length
        $allChars = implode('', $chars);
        while (count($password) < $length) {
            $password[] = $allChars[random_int(0, strlen($allChars) - 1)];
        }

        shuffle($password);
        return implode('', $password);
    }

    private function generateString(int $minLength, int $maxLength): string
    {
        $length = random_int($minLength, $maxLength);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
    }
}