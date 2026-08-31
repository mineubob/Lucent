<?php
declare(strict_types=1);


namespace Lucent\Commandline;

use Lucent\Facades\App;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Log;
use Lucent\Filesystem\File;
use Lucent\Filesystem\Folder;
use Lucent\Http\Attributes\ApiEndpoint;
use Lucent\Http\Attributes\ApiResponse;
use ReflectionClass;

class GenerateDocumentationCommand
{

    public static string $command = "generate api-docs";

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
        $outputPath = FileSystem::rootPath() . DIRECTORY_SEPARATOR . "storage" . DIRECTORY_SEPARATOR . 'documentation' . DIRECTORY_SEPARATOR;
        if (!file_exists($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        file_put_contents($outputPath . 'api.html', $template);

        return "API documentation generated successfully at " . $outputPath . "api.html";
    }
    public function scanControllers(): array
    {
        $documentation = [];

        $app = new Folder("/App");

        if (!$app->exists()) {
            Log::channel("lucent.commandline")->error("Fatal error app folder doesnt exist...");
            return [];
        }

        foreach ($app->getFiles(true) as $file) {

            if ($file->getExtension() == ".php") {
                $this->scanPhpFile($file, $documentation);
            }
        }

        Log::channel("lucent.commandline")->info("Scan complete. Found " . count($documentation) . " endpoints");
        return $documentation;
    }

    private function scanPhpFile(File $file, &$documentation): void
    {
        try {
            $className = $this->toNamespace($file->path);

            if (!class_exists($className)) {
                Log::channel("lucent.commandline")->debug("Class not found, requiring file: " . $file->path);
                require_once $file->path;
            }

            $reflection = new ReflectionClass($className);

            foreach ($reflection->getMethods() as $method) {

                $endpointAttributes = $method->getAttributes(ApiEndpoint::class);

                //skip the endpoint if it has no data
                if (empty($endpointAttributes)) {
                    continue;
                }

                //get the endpoint and responses
                $endpoint = $endpointAttributes[0]->newInstance();
                $responses = [];

                $responseAttributes = $method->getAttributes(ApiResponse::class);

                foreach ($responseAttributes as $attribute) {
                    $responses[] = $attribute->newInstance();
                }

                $documentation[] = $this->processEndpoint($endpoint, $responses);
            }
        } catch (\ReflectionException $e) {
            Log::channel("lucent.commandline")->critical("ReflectionException " . $e->getMessage());
        }
    }

    private function toNamespace(string $filePath): string
    {
        $rootPath = FileSystem::rootPath();

        // remove FileSystem::rootPath() if present
        if (str_starts_with($filePath, $rootPath)) {
            $filePath = substr($filePath, strlen($rootPath));
        }

        // remove leading directory separator
        $filePath = ltrim($filePath, DIRECTORY_SEPARATOR);

        // remove .php extension and convert directory separators to namespace separators
        return str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $filePath
        );
    }

    private function processEndpoint(ApiEndpoint $endpoint, array $responses): array
    {
        $examples = [];
        $validationRules = null;

        // Process API responses
        foreach ($responses as $response) {
            $body = [
                'message' => $response->message,
                'outcome' => $response->outcome,
                'status' => $response->status,
                'content' => $response->content ?? [],
                'errors' => [],
            ];

            // Convert sequential arrays to associative if they appear to be key-value pairs
            if (!empty($response->content) && is_array($response->content) && count($response->content) % 2 === 0) {
                $pairs = array_chunk($response->content, 2);
                $content = [];
                foreach ($pairs as $pair) {
                    if (is_string($pair[0])) {
                        $content[$pair[0]] = $pair[1];
                        continue;
                    }
                    $content[] = $pair;
                }
                $body['content'] = $content;
            }

            if (!empty($response->errors)) {
                $body['errors'] = $response->errors;
            }

            $examples[$response->status] = $body;
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

            // Sort examples by status code
            ksort($endpoint['examples']);

            foreach ($endpoint['examples'] as $status => $response) {
                $responseType = $this->getResponseType($status);
                $responseData = $response;

                // Format the response data
                $formattedResponse = $this->formatResponseData($responseData);

                $examples .= '<div class="response">
                    <div class="response-header">' . $responseType . ' (' . $status . ')</div>
                    <div class="response-body">
                        <pre>' . $formattedResponse . '</pre>
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

    private function formatResponseData(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getResponseType(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'Success',
            $status === 400 => 'Validation Error',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not Found',
            $status >= 400 && $status < 500 => 'Client Error',
            $status >= 500 => 'Server Error',
            default => 'Unknown'
        };
    }
}
