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

    /**
     * Generate passing validation data
     */
    public function passing(string $ruleClass): self
    {
        $rule = new $ruleClass();
        $this->fakeData = [];

        // First pass: generate all non-dependent fields
        foreach ($rule->getRules() as $field => $rules) {
            if (!$this->hasSameRule((array)$rules)) {
                $this->fakeData[$field] = $this->generateValidValueFromRules($field, (array)$rules);
            }
        }

        // Second pass: handle fields with "same" rule
        foreach ($rule->getRules() as $field => $rules) {
            if ($this->hasSameRule((array)$rules)) {
                $this->fakeData[$field] = $this->handleSameRule($field, (array)$rules);
            }
        }

        return $this;
    }

    private function hasSameRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'same:')) {
                return true;
            }
        }
        return false;
    }

    private function handleSameRule(string $field, array $rules): string
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'same:')) {
                $targetField = substr($rule, 5);
                return $this->fakeData[$targetField] ?? '';
            }
        }
        return '';
    }

    /**
     * Generate failing validation data
     */
    public function failing(string $ruleClass): self
    {
        $rule = new $ruleClass();
        $this->fakeData = [];

        foreach ($rule->getRules() as $field => $rules) {
            $this->fakeData[$field] = $this->generateInvalidValueFromRules($field, (array)$rules);
        }

        return $this;
    }

    private function generateValidValueFromRules(string $field, array $rules): string
    {
        $value = '';
        $minLength = 1;
        $maxLength = 255;
        $type = $this->determineFieldType($field, $rules);

        // Process rules to determine constraints
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                [$ruleName, $ruleValue] = array_pad(explode(':', $rule, 2), 2, null);

                switch ($ruleName) {
                    case 'min':
                        $minLength = (int)$ruleValue;
                        break;
                    case 'max':
                        $maxLength = (int)$ruleValue;
                        break;
                }
            }
        }

        // Generate appropriate value based on type and constraints
        switch ($type) {
            case 'email':
                $value = $this->generateEmail();
                break;

            case 'password':
                $value = $this->generatePassword($minLength, $maxLength);
                break;

            case 'date':
                $value = date('Y-m-d');
                break;

            case 'numeric':
                $value = (string)random_int($minLength, $maxLength);
                break;

            default:
                $value = $this->generateString($minLength, $maxLength);
        }

        return $value;
    }

    private function generateInvalidValueFromRules(string $field, array $rules): string
    {
        $type = $this->determineFieldType($field, $rules);

        // Generate invalid data based on type
        switch ($type) {
            case 'email':
                return 'invalid-email';

            case 'password':
                return 'weak'; // Too short and missing required characters

            case 'date':
                return 'invalid-date';

            case 'numeric':
                return 'not-a-number';

            default:
                return ''; // Empty string typically fails most validations
        }
    }

    private function determineFieldType(string $field, array $rules): string
    {
        // Check rules first
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                [$ruleName] = explode(':', $rule, 2);
                if ($ruleName === 'regex') {
                    if (str_contains($rule, 'email')) return 'email';
                    if (str_contains($rule, 'password')) return 'password';
                }
            }
        }

        // Check field name as fallback
        if (str_contains($field, 'email')) return 'email';
        if (str_contains($field, 'password')) return 'password';
        if (str_contains($field, 'date')) return 'date';
        if (str_contains($field, 'number') || str_contains($field, 'amount')) return 'numeric';

        return 'string';
    }

    private function generateEmail(): string
    {
        $domains = ['example.com', 'test.org', 'fake.net', 'demo.io'];
        $username = $this->generateString(5, 10);
        return $username . '@' . $domains[array_rand($domains)];
    }

    private function generatePassword(int $minLength = 8, int $maxLength = 20): string
    {
        // Ensure maxLength is at least 12 for secure passwords but no more than specified
        $maxLength = min(max(12, $minLength), $maxLength);
        $length = random_int($minLength, $maxLength);

        // Define character sets
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*';

        // Start with required characters
        $password = [
            $uppercase[random_int(0, strlen($uppercase) - 1)],  // One uppercase
            $lowercase[random_int(0, strlen($lowercase) - 1)],  // One lowercase
            $numbers[random_int(0, strlen($numbers) - 1)],      // One number
            $special[random_int(0, strlen($special) - 1)]       // One special
        ];

        // Fill remaining length with random characters from all sets
        $allChars = $uppercase . $lowercase . $numbers . $special;
        while (count($password) < $length) {
            $password[] = $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle to prevent predictable pattern
        shuffle($password);
        return implode('', $password);
    }

    private function generateString(int $minLength, int $maxLength): string
    {
        $length = random_int($minLength, $maxLength);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';

        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $str;
    }
}