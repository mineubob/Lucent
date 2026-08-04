<?php

namespace Lucent\Faker;

use Lucent\Http\Message\ServerRequest;
use Psr\Http\Message\UriInterface;

/**
 * Fake PSR-7 ServerRequest for testing and documentation generation.
 *
 * Wraps ServerRequest with a mutable fake data layer,
 * mirroring the pattern of FakeRequest for the new PSR-7 API.
 *
 * @method FakeServerRequest setInput(string $key, mixed $value)
 * @method array all()
 */
final class FakeServerRequest extends ServerRequest
{
    private array $fakeData = [];

    /**
     * Create a new FakeServerRequest.
     *
     * @param string $method  HTTP method
     * @param string|UriInterface|null $uri  URI string or object
     * @param array $serverParams  Server parameters
     * @param array $queryParams  Query parameters
     * @param array $body  Parsed body parameters
     * @param array $cookies  Cookie parameters
     * @param array $headers  Headers as [name => value, ...]
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string|null $uri = null,
        array $serverParams = [],
        array $queryParams = [],
        array $body = [],
        array $cookies = [],
        array $headers = [],
    ) {
        $uriString = is_string($uri) ? $uri : null;
        $uriObject = $uri instanceof UriInterface ? $uri : null;
        parent::__construct($method, $uriObject ?? \Lucent\Http\Message\Uri::fromString($uriString ?? '/'), $serverParams);

        $this->fakeData = $body;

        if ($queryParams !== []) {
            $this->queryParams = $queryParams;
        }

        if ($cookies !== []) {
            $this->cookieParams = $cookies;
        }

        foreach ($headers as $name => $value) {
            $this->withHeaderInternal($name, is_array($value) ? $value : [$value]);
        }
    }

    /**
     * Get all input data (fake data, then parsed body, then query params).
     *
     * @return array
     */
    public function all(): array
    {
        return $this->fakeData ?: ($this->getParsedBody() ?? $this->getQueryParams());
    }

    /**
     * Set a single input value.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setInput(string $key, mixed $value): self
    {
        $this->fakeData[$key] = $value;
        return $this;
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
        return $this->fakeData[$key] ?? $default;
    }

    // ─── FakeRequest-compatible API ─────────────────────────────────────

    /**
     * Generate passing validation data.
     *
     * @param string $ruleClass
     * @return $this
     */
    public function passing(string $ruleClass): self
    {
        $ruleInstance = new $ruleClass();
        $this->fakeData = [];

        $rules = $ruleInstance->setup();

        // First pass: handle all non-dependent fields
        foreach ($rules as $field => $fieldRules) {
            if (!$this->hasDependentRule((array) $fieldRules)) {
                $this->fakeData[$field] = $this->generateValidValue($field, (array) $fieldRules);
            }
        }

        // Second pass: handle dependent rules like 'same'
        foreach ($rules as $field => $fieldRules) {
            if ($this->hasDependentRule((array) $fieldRules)) {
                $this->fakeData[$field] = $this->handleDependentRules($field, (array) $fieldRules);
            }
        }

        return $this;
    }

    /**
     * Generate failing validation data.
     *
     * @param string $ruleClass
     * @return $this
     */
    public function failing(string $ruleClass): self
    {
        $ruleInstance = new $ruleClass();
        $this->fakeData = [];

        $rules = $ruleInstance->setup();

        foreach ($rules as $field => $fieldRules) {
            $this->fakeData[$field] = $this->generateInvalidValue($field, (array) $fieldRules);
        }

        return $this;
    }

    // ─── Internal helpers (mirrored from FakeRequest) ───────────────────

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