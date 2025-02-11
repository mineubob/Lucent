<?php

namespace Lucent\Commandline;

use Lucent\Facades\App;
use Lucent\Facades\Faker;
use Lucent\Facades\Json;
use Lucent\Facades\Log;
use Lucent\Http\Attributes\ApiEndpoint;
use Lucent\Http\JsonResponse;
use ReflectionClass;

class DocumentationController
{
    public function generateApi(): string
    {
        $documentation = $this->scanControllers();

        // Load our template
        $template = file_get_contents(LUCENT . 'Templates' . DIRECTORY_SEPARATOR . 'api-docs.php');

        // Replace our template variables
        $template = str_replace(
            [
                '{{endpoints}}',
                '{{date}}',
                '{{version}}'
            ],
            [
                $this->generateEndpointsHtml($documentation),
                date('F j, Y'),
                App::getLucentVersion()
            ],
            $template
        );

        // Save to file
        $outputPath = EXTERNAL_ROOT."storage" .DIRECTORY_SEPARATOR. 'documentation' . DIRECTORY_SEPARATOR;
        if (!file_exists($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        file_put_contents($outputPath . 'api.html', $template);

        return "API documentation generated successfully at " . $outputPath . "api.html";
    }

    public function scanControllers(): array
    {
        $documentation = [];

        // Verify CONTROLLERS constant
        if (!defined('CONTROLLERS')) {
            Log::channel("phpunit")->error("CONTROLLERS constant is not defined");
            return [];
        }

        if (!is_dir(CONTROLLERS)) {
            Log::channel("phpunit")->error("Controllers directory does not exist: " . CONTROLLERS);
            return [];
        }

        Log::channel("phpunit")->info("Starting scan in directory: " . CONTROLLERS);

        // Recursive function to scan directories
        $scanDirectory = function(string $dir, string $namespace) use (&$scanDirectory, &$documentation) {
            Log::channel("phpunit")->info("Scanning directory: " . $dir);
            Log::channel("phpunit")->info("Using namespace: " . $namespace);

            if (!is_dir($dir)) {
                Log::channel("phpunit")->error("Directory does not exist: " . $dir);
                return;
            }

            $files = scandir($dir);
            Log::channel("phpunit")->info("Found files: " . implode(", ", $files));

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $dir . DIRECTORY_SEPARATOR . $file;
                Log::channel("phpunit")->info("Processing path: " . $path);

                if (is_dir($path)) {
                    Log::channel("phpunit")->info("Found subdirectory: " . $file);
                    $subNamespace = $namespace . '\\' . $file;
                    $scanDirectory($path, $subNamespace);
                }
                elseif (str_ends_with($file, '.php')) {
                    Log::channel("phpunit")->info("Found PHP file: " . $file);
                    try {
                        $className = $namespace . '\\' . basename($file, '.php');
                        Log::channel("phpunit")->info("Attempting to reflect class: " . $className);

                        if (!class_exists($className)) {
                            Log::channel("phpunit")->info("Class not found, requiring file: " . $path);
                            require_once $path;
                        }

                        $reflection = new ReflectionClass($className);
                        Log::channel("phpunit")->info("Successfully reflected class: " . $className);

                        foreach ($reflection->getMethods() as $method) {
                            Log::channel("phpunit")->info("Checking method: " . $method->getName());
                            $attributes = $method->getAttributes(ApiEndpoint::class);
                            Log::channel("phpunit")->info("Found " . count($attributes) . " API endpoint attributes");

                            foreach ($attributes as $attribute) {
                                Log::channel("phpunit")->info("Processing attribute for method: " . $method->getName());
                                $endpoint = $attribute->newInstance();
                                $documentation[] = $this->processEndpoint($endpoint);
                            }
                        }
                    } catch (\ReflectionException $e) {
                        Log::channel("phpunit")->error("ReflectionException for {$className}: " . $e->getMessage());
                    } catch (\Exception $e) {
                        Log::channel("phpunit")->error("General Exception while processing {$className}: " . $e->getMessage());
                    }
                }
            }
        };

        // Start scanning from the controllers directory with base namespace
        $scanDirectory(CONTROLLERS, 'App\\Controllers');

        Log::channel("phpunit")->info("Scan complete. Found " . count($documentation) . " endpoints");
        return $documentation;
    }
    private function processEndpoint(ApiEndpoint $endpoint): array
    {
        $examples = [];
        $validationRules = null;

        if ($endpoint->rule) {
            // Generate success example
            $successRequest = Faker::request()->passing($endpoint->rule);

            if ($successRequest->validate($endpoint->rule)) {
                $examples['success'] = new JsonResponse()
                    ->setOutcome(true)
                    ->setMessage('Request successfully executed.')
                    ->addContent('data', $successRequest->all());
            }

            // Generate failure example
            $failRequest = Faker::request()->failing($endpoint->rule);

            if (!$failRequest->validate($endpoint->rule)) {
                $examples['failure'] = new JsonResponse()
                    ->setOutcome(false)
                    ->setStatusCode(400)
                    ->addErrors($failRequest->getValidationErrors());
            }

            // Get validation rules
            $ruleInstance = new ($endpoint->rule);
            $validationRules = $ruleInstance->setup();
        }

        return [
            'path' => $endpoint->path,
            'method' => $endpoint->method,
            'description' => $endpoint->description,
            'parameters' => $endpoint->pathParams,
            'validationRules' => $validationRules,
            'examples' => $examples
        ];
    }

    private function generateEndpointsHtml(array $documentation): string
    {
        $html = '';
        foreach ($documentation as $endpoint) {
            $html .= $this->generateEndpointHtml($endpoint);
        }
        return $html;
    }

    private function generateEndpointHtml(array $endpoint): string
    {
        $urlParams = '';
        if (!empty($endpoint['parameters'])) {
            $urlParams = '<div class="parameters">
                <h3>URL Parameters</h3>';

            foreach ($endpoint['parameters'] as $name => $description) {
                $urlParams .= '<div class="parameter">
                    <span class="parameter-name">' . htmlspecialchars($name) . '</span>
                    <span class="parameter-description">' . htmlspecialchars($description) . '</span>
                </div>';
            }

            $urlParams .= '</div>';
        }

        $validationRules = '';
        if (!empty($endpoint['validationRules'])) {
            $validationRules = '<div class="validation-rules">
                <h3>Validation Rules</h3>
                <ul class="rules-list">';

            foreach ($endpoint['validationRules'] as $field => $rules) {
                $validationRules .= '<li>
                    <span class="rule-name">' . htmlspecialchars($field) . '</span>
                    <span>' . htmlspecialchars(implode(', ', (array)$rules)) . '</span>
                </li>';
            }

            $validationRules .= '</ul></div>';
        }

        $examples = '';
        if (!empty($endpoint['examples'])) {
            $examples = '<div class="response-section">
                <h3>Response Examples</h3>';

            if (isset($endpoint['examples']['success'])) {
                $examples .= '<div class="response">
                    <div class="response-header">Success Response (200)</div>
                    <div class="response-body">
                        <pre>' . json_encode($endpoint['examples']['success']->getArray(), JSON_PRETTY_PRINT) . '</pre>
                    </div>
                </div>';
            }

            if (isset($endpoint['examples']['failure'])) {
                $examples .= '<div class="response">
                    <div class="response-header">Validation Error (400)</div>
                    <div class="response-body">
                        <pre>' . json_encode($endpoint['examples']['failure']->getArray(), JSON_PRETTY_PRINT) . '</pre>
                    </div>
                </div>';
            }

            $examples .= '</div>';
        }

        // Format the path to highlight parameters
        $path = preg_replace(
            '/\{([^}]+)\}/',
            '<span class="parameter">{$1}</span>',
            htmlspecialchars($endpoint['path'])
        );

        return <<<HTML
        <div class="endpoint">
            <div class="endpoint-header">
                <span class="method {$endpoint['method']}">{$endpoint['method']}</span>
                <span class="endpoint-path">{$path}</span>
            </div>
            <div class="endpoint-content">
                <p>{$endpoint['description']}</p>
                {$urlParams}
                {$validationRules}
                {$examples}
            </div>
        </div>
        HTML;
    }

}