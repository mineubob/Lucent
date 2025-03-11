<?php
namespace Lucent\Http;

use InvalidArgumentException;
use Lucent\Database\Dataset;
use Lucent\Model;
use Lucent\Validation\BlankRule;
use Lucent\Validation\Rule;

/**
 * Request class for handling HTTP requests
 *
 * Provides methods for accessing request data, headers, validation,
 * and managing the request lifecycle.
 */
class Request
{
    /**
     * Additional context data associated with this request.
     *
     * This can be used to store arbitrary key-value pairs of information about the request.
     *
     * @var array
     */
    public array $context = [];

    /** @var array POST data from the request */
    private array $post = [];

    /** @var array GET data from the request */
    private array $get = [];

    /** @var array JSON data parsed from request body */
    private array $json = [];

    /** @var array Request headers */
    private array $headers = [];

    /** @var array Validation errors from the most recent validation */
    private array $validationErrors = [];

    /** @var array Cache for models retrieved during validation */
    private array $modelCache = [];

    /** @var array URL variables extracted from route parameters */
    private array $urlVars;

    /**
     * Constructor for the Request class
     *
     * Initializes request data and creates a new session
     */
    public function __construct()
    {
        $this->initializeRequestData();
    }

    /**
     * Initializes request data from various sources
     *
     * Processes headers, JSON input, POST and GET data
     */
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

    /**
     * Extracts and normalizes request headers from $_SERVER
     *
     * @return array Normalized request headers
     */
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

    /**
     * Get a header value by key with optional default
     *
     * @param string $key The header name to retrieve
     * @param mixed|null $default The default value if header is not found
     * @return string|null The header value or default if not found
     */
    public function header(string $key, mixed $default = null): ?string
    {
        // Normalize the header key
        $key = str_replace(' ', '-', ucwords(strtolower($key), '-'));

        return $this->headers[$key] ?? $default;
    }

    /**
     * Get all request headers
     *
     * @return array All request headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Extract the Bearer token from the Authorization header
     *
     * @return string|null The Bearer token or null if not found
     */
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

    /**
     * Check if the request has a JSON content type
     *
     * @return bool True if request has JSON content type
     */
    private function isJsonRequest(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    /**
     * Get all input data from the request
     *
     * Merges data from appropriate source based on request method
     *
     * @return array All request input data
     */
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

    /**
     * Get request data as a Dataset object
     *
     * @return Dataset Dataset containing all request data
     */
    public function dataset(): Dataset
    {
        return new Dataset($this->all());
    }

    /**
     * Get all request data except specified keys
     *
     * @param array $keys Keys to exclude from the result
     * @return array Request data without the excluded keys
     */
    public function except(array $keys): array
    {
        $data = $this->all();
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        return $data;
    }

    /**
     * Get a specific input value by key
     *
     * @param string $key The input key to retrieve
     * @param mixed|null $default Default value if key not found
     * @return string|null The input value or default if not found
     */
    public function input(string $key, mixed $default = null): null|string
    {
        $data = $this->all();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    /**
     * Set an input value in the request
     *
     * @param string $key The key to set
     * @param mixed $value The value to set
     */
    public function setInput(string $key, mixed $value): void
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

    /**
     * Sanitizes user input data by stripping tags, newlines, and other malicious content.
     *
     * @param array $input The input data to sanitize
     *
     * @return array The sanitized input data
     */
    private function sanitizeUserInput(array $input): array
    {
        $filter = function ($var) {
            // First check if the value meets inclusion criteria
            if ($var === NULL || $var === FALSE || (
                    !is_array($var) &&
                    !is_bool($var) &&
                    !is_numeric($var) &&
                    trim((string)$var) === ""
                )) {
                return false;
            }
            
            // Then sanitize the value if it's a string
            if (is_string($var)) {
                $var = htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
                $var = addslashes($var);
            } else if (is_array($var)) {
                // Handle arrays recursively
                $var = $this->sanitizeUserInput($var);
            }
            // No need to modify booleans or numbers
            
            return $var;
        };
        
        return array_map($filter, array_filter($input, function($var) {
            return $var !== NULL && $var !== FALSE && (
                is_array($var) ||
                is_bool($var) ||
                is_numeric($var) ||
                trim((string)$var) !== ""
            );
        }));
    }

    /**
     * Validate request data against rules
     *
     * @param array|Rule|string $rules Validation rules as array, Rule instance, or Rule class name
     * @return bool Whether validation passed
     * @throws InvalidArgumentException When an invalid rules format is provided
     */
    public function validate(array|Rule|string $rules): bool
    {
        $this->validationErrors = [];

        if (is_string($rules)) {
            // Handle rule class name
            if (!class_exists($rules)) {
                throw new InvalidArgumentException("Validation rule class not found: $rules");
            }

            $instance = new $rules();

            if (!($instance instanceof Rule)) {
                throw new InvalidArgumentException("Class $rules is not a valid Rule instance");
            }
        } elseif (is_array($rules)) {
            // Handle an array of rules
            $instance = new BlankRule();
            $instance->setRules($rules);
        } elseif ($rules instanceof Rule) {
            // Handle Rule instance
            $instance = $rules;
        } else {
            // This should never happen due to the type hint, but adding for defensive programming
            throw new InvalidArgumentException("Invalid rule format provided");
        }

        // Set the calling request and perform validation
        $instance->setCallingRequest($this);
        $this->validationErrors = $instance->validate($this->all());

        return count($this->validationErrors) === 0;
    }

    /**
     * Cache a model instance for later retrieval
     *
     * @param string $key The key to store the model under
     * @param Model $model The model instance to cache
     */
    #[\Deprecated("This method is deprecated and will be removed in future versions. Please use the context instead.")]
    public function cacheModel(string $key, Model $model): void
    {
        $this->modelCache[$key] = $model;
    }

    /**
     * Retrieve a cached model by key
     *
     * @param string $key The key of the cached model
     * @return Model The cached model
     */
    #[\Deprecated("This method is deprecated and will be removed in future versions. Please use the context instead.")]
    public function getCachedModel(string $key): Model
    {
        return $this->modelCache[$key];
    }

    /**
     * Get validation errors from the most recent validation
     *
     * @return array Validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Get a URL variable from route parameters
     *
     * @param string $key The variable name
     * @return string|null The URL variable value or null if not found
     */
    public function getUrlVariable(string $key): ?string
    {
        if (!array_key_exists($key, $this->urlVars)) {
            return null;
        }
        return $this->urlVars[$key];
    }

    /**
     * Set URL variables from route parameters
     *
     * @param array $vars The URL variables to set
     */
    public function setUrlVars(array $vars): void
    {
        $this->urlVars = $vars;
    }
}
