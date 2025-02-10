<?php

namespace Lucent\Commandline;

use Lucent\Facades\App;
use Lucent\Facades\Faker;
use Lucent\Facades\Json;
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

    private function scanControllers(): array
    {
        $documentation = [];

        $controllers = glob(CONTROLLERS . '*.php');
        foreach ($controllers as $file) {
            $className = 'App\\Controllers\\' . basename($file, '.php');
            $reflection = new ReflectionClass($className);

            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(ApiEndpoint::class);

                foreach ($attributes as $attribute) {
                    $endpoint = $attribute->newInstance();
                    $documentation[] = $this->processEndpoint($endpoint);
                }
            }
        }

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
                $examples['success'] = Json::response()
                    ->setOutcome(true)
                    ->setMessage('Request successfully executed.')
                    ->addContent('data', $successRequest->all());
            }

            // Generate failure example
            $failRequest = Faker::request()->failing();
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