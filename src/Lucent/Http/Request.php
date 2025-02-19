<?php
namespace Lucent\Http;

use Lucent\Database\Dataset;
use Lucent\Model;
use Lucent\Validation\BlankRule;

class Request
{
    private array $post = [];
    private array $get = [];
    private array $json = [];
    private array $headers = [];
    private array $validationErrors = [];
    private array $modelCache = [];
    private Session $session;
    private array $urlVars;

    public function __construct()
    {
        $this->initializeRequestData();
        $this->session = new Session();
    }

    private function initializeRequestData(): void
    {
        // Initialize headers
        $this->headers = $this->getHeaders();

        // Handle JSON content type
        if ($this->isJsonRequest()) {
            $jsonInput = file_get_contents('php://input');
            if (!empty($jsonInput)) {
                $this->json = $this->sanitizeUserInput(
                    json_decode($jsonInput, true) ?? []
                );
            }
        }

        $this->post = $this->sanitizeUserInput($_POST);
        $this->get = $this->sanitizeUserInput($_GET);
    }

    private function getHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    public function header(string $key, $default = null): ?string
    {
        // Normalize the header key
        $key = str_replace(' ', '-', ucwords(strtolower($key), '-'));

        return $this->headers[$key] ?? $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');

        if (empty($header)) {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function isJsonRequest(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    public function all(): array
    {
        if ($this->isJsonRequest()) {
            return $this->json;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            return $this->post;
        }

        if ($_SERVER["REQUEST_METHOD"] === "GET") {
            return $this->get;
        }

        return [];
    }

    public function dataset(): Dataset
    {
        return new Dataset($this->all());
    }

    public function except(array $keys): array
    {
        $data = $this->all();
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        return $data;
    }

    public function input(string $key, $default = null): null|string
    {
        $data = $this->all();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public function setInput(string $key, $value): void
    {
        if ($this->isJsonRequest()) {
            $this->json[$key] = $value;
            return;
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->post[$key] = $value;
        }

        if ($_SERVER["REQUEST_METHOD"] === "GET") {
            $this->get[$key] = $value;
        }
    }

    private function sanitizeUserInput(array $input): array
    {
        $filter = function ($var) {
            return ($var !== NULL && $var !== FALSE && (
                    is_array($var) ||
                    is_bool($var) ||
                    is_numeric($var) ||
                    trim($var) !== ""
                ));
        };

        return array_filter($input, $filter);
    }

    public function validate($rules): bool
    {
        $instance = null;
        $this->validationErrors = [];

        if (gettype($rules) === "string") {
            $instance = new $rules();
        } else {
            $instance = new BlankRule();
            $instance->setRules($rules);
        }

        $instance->setCallingRequest($this);
        $this->validationErrors = $instance->validate($this->all());

        return sizeof($this->validationErrors) === 0;
    }

    public function cacheModel(string $key, Model $model): void
    {
        $this->modelCache[$key] = $model;
    }

    public function getCachedModel(string $key): Model
    {
        return $this->modelCache[$key];
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function getUrlVariable(string $key): ?string
    {
        if (!array_key_exists($key, $this->urlVars)) {
            return null;
        }
        return $this->urlVars[$key];
    }

    public function setUrlVars(array $vars): void
    {
        $this->urlVars = $vars;
    }
}