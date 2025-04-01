<?php

namespace Lucent\Facades;

use Lucent\Faker\FakeRequest;
use Lucent\Filesystem\File;
use Lucent\Filesystem\Folder;

class Faker
{
    /**
     * Create a fake HTTP request
     *
     * @return FakeRequest
     */
    public static function request(): FakeRequest
    {
        return new FakeRequest();
    }

    /**
     * Generate a random file
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Options for file generation
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function file(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        // Handle directory
        if ($directory instanceof Folder) {
            $dir = $directory;
        } else {
            $dir = new Folder($directory, $absolute);
            if (!$dir->exists()) {
                $dir->create();
            }
        }

        // Default options
        $defaults = [
            'name' => self::randomString(8),
            'extension' => 'txt',
            'size' => rand(1024, 10240), // 1KB to 10KB
            'type' => 'text',
            'content' => null,
        ];

        $options = array_merge($defaults, $options);

        // Generate filename
        $filename = $options['name'] . '.' . $options['extension'];

        // Generate content based on type if not explicitly provided
        if ($options['content'] === null) {
            $content = self::generateContent($options['type'], $options['size']);
        } else {
            $content = $options['content'];
        }

        // Create and return the file
        return new File($dir->path . DIRECTORY_SEPARATOR . $filename, $content, true);
    }

    /**
     * Generate multiple random files at once
     *
     * @param string|Folder $directory Directory where files should be created
     * @param int $count Number of files to generate
     * @param array $options Options for file generation
     * @param bool $absolute Whether the directory path is absolute
     * @return array An array of File objects
     */
    public static function files(string|Folder $directory, int $count = 5, array $options = [], bool $absolute = false): array
    {
        $files = [];

        // Ensure directory exists
        if ($directory instanceof Folder) {
            $dir = $directory;
        } else {
            $dir = new Folder($directory, $absolute);
            if (!$dir->exists()) {
                $dir->create();
            }
        }

        // Generate each file
        for ($i = 0; $i < $count; $i++) {
            // Copy the options to avoid modifying the original
            $fileOptions = $options;

            // Generate unique name if not specified
            if (!isset($fileOptions['name'])) {
                $fileOptions['name'] = self::randomString(8);
            }

            // Select random extension if array provided
            if (isset($fileOptions['extension']) && is_array($fileOptions['extension'])) {
                // Get a random index
                $randomIndex = array_rand($fileOptions['extension']);
                // Use the extension at that random index
                $fileOptions['extension'] = $fileOptions['extension'][$randomIndex];
            }

            // Create the file with appropriate content type based on extension
            $extension = $fileOptions['extension'] ?? 'txt';

            // Determine content type based on extension if not specified
            if (!isset($fileOptions['type'])) {
                switch ($extension) {
                    case 'html':
                        $fileOptions['type'] = 'html';
                        break;
                    case 'json':
                        $fileOptions['type'] = 'json';
                        break;
                    case 'csv':
                        $fileOptions['type'] = 'csv';
                        break;
                    case 'js':
                        $fileOptions['type'] = 'js'; // JavaScript as text
                        break;
                    case 'css':
                        $fileOptions['type'] = 'css'; // CSS as text
                        break;
                    default:
                        $fileOptions['type'] = 'text';
                }
            }

            // Create the file
            $filename = $fileOptions['name'] . '.' . $extension;
            $content = self::generateContent($fileOptions['type'], $fileOptions['size'] ?? rand(1024, 10240));
            $file = new File($dir->path . DIRECTORY_SEPARATOR . $filename, $content, true);

            $files[] = $file;
        }

        return $files;
    }
    /**
     * Generate content based on specified type
     *
     * @param string $type Content type
     * @param int $size Approximate content size in bytes
     * @return string|mixed Generated content
     */
    private static function generateContent(string $type, int $size): mixed
    {
        return match ($type) {
            'json' => self::generateJsonContent(),
            'html' => self::generateHtmlContent(),
            'csv' => self::generateCsvContent(),
            'binary' => self::generateBinaryContent($size),
            'css' => self::generateCssContent(),
            'js' => self::generateJsContent(),
            default => self::generateTextContent($size),
        };
    }

    /**
     * Generate random text content
     *
     * @param int $size Approximate size in bytes
     * @return string Random text content
     */
    public static function generateTextContent(int $size): string
    {
        $content = '';
        $remaining = $size;

        while ($remaining > 0) {
            $length = min($remaining, rand(3, 15));
            $content .= self::randomString($length) . ' ';
            $remaining -= $length + 1;

            if (rand(0, 10) == 0) {
                $content .= "\n";
                $remaining--;
            }
        }

        return $content;
    }

    /**
     * Generate random JSON content
     *
     * @return string JSON content
     */
    public static function generateJsonContent(): string
    {
        $data = [];
        $count = rand(5, 20);

        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'id' => $i + 1,
                'name' => self::randomString(rand(5, 10)),
                'value' => self::randomString(rand(10, 30)),
                'created' => date('Y-m-d H:i:s')
            ];
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Generate random HTML content
     *
     * @return string HTML content
     */
    public static function generateHtmlContent(): string
    {
        $content = "<!DOCTYPE html>\n<html>\n<head>\n";
        $content .= "\t<title>" . self::randomString(10) . "</title>\n";
        $content .= "</head>\n<body>\n";
        $content .= "\t<h1>" . self::randomString(15) . "</h1>\n";

        $paragraphs = rand(3, 10);
        for ($i = 0; $i < $paragraphs; $i++) {
            $content .= "\t<p>" . self::randomString(rand(50, 200)) . "</p>\n";
        }

        $content .= "</body>\n</html>";

        return $content;
    }

    /**
     * Generate random CSV content
     *
     * @return string CSV content
     */
    public static function generateCsvContent(): string
    {
        $content = "id,name,email,date\n";
        $rows = rand(10, 50);

        for ($i = 1; $i <= $rows; $i++) {
            $content .= $i . ',';
            $content .= self::randomString(rand(5, 10)) . ',';
            $content .= strtolower(self::randomString(5)) . '@' . strtolower(self::randomString(5)) . '.com,';
            $content .= date('Y-m-d', strtotime('-' . rand(1, 365) . ' days')) . "\n";
        }

        return $content;
    }

    /**
     * Generate random binary content
     *
     * @param int $size Size in bytes
     * @return string Binary content
     */
    public static function generateBinaryContent(int $size): string
    {
        return random_bytes($size);
    }

    /**
     * Generate a random string of specified length
     *
     * @param int $length Length of the string
     * @return string Random string
     */
    public static function randomString(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $string = '';

        for ($i = 0; $i < $length; $i++) {
            $string .= $characters[rand(0, strlen($characters) - 1)];
        }

        return $string;
    }

    /**
     * Generate a text file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function textFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['type'] = 'text';
        $options['extension'] = $options['extension'] ?? 'txt';
        return self::file($directory, $options, $absolute);
    }

    /**
     * Generate a JSON file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function jsonFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['type'] = 'json';
        $options['extension'] = $options['extension'] ?? 'json';
        return self::file($directory, $options, $absolute);
    }

    /**
     * Generate an HTML file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function htmlFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['type'] = 'html';
        $options['extension'] = $options['extension'] ?? 'html';
        return self::file($directory, $options, $absolute);
    }

    /**
     * Generate a CSV file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function csvFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['type'] = 'csv';
        $options['extension'] = $options['extension'] ?? 'csv';
        return self::file($directory, $options, $absolute);
    }

    /**
     * Generate a binary file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function binaryFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['type'] = 'binary';
        $options['extension'] = $options['extension'] ?? 'bin';
        return self::file($directory, $options, $absolute);
    }

    /**
     * Generate random JavaScript content
     *
     * @return string JavaScript content
     */
    public static function generateJsContent(): string
    {
        $content = "// Generated JavaScript file\n";
        $content .= "// Created on: " . date('Y-m-d H:i:s') . "\n\n";

        // Add some constants
        $content .= "const APP_NAME = '" . self::randomString(8) . "';\n";
        $content .= "const VERSION = '" . rand(1, 5) . "." . rand(0, 9) . "." . rand(0, 9) . "';\n";
        $content .= "const DEBUG = " . (rand(0, 1) ? 'true' : 'false') . ";\n\n";

        // Add a class/object
        $className = self::randomString(6);
        $content .= "class " . ucfirst($className) . " {\n";
        $content .= "  constructor() {\n";
        $content .= "    this.id = '" . self::randomString(8) . "';\n";
        $content .= "    this.name = '" . self::randomString(10) . "';\n";
        $content .= "    this.created = new Date();\n";
        $content .= "    this.items = [];\n";
        $content .= "  }\n\n";

        // Add some methods
        $methods = rand(2, 5);
        for ($i = 0; $i < $methods; $i++) {
            $methodName = lcfirst(self::randomString(rand(5, 10)));
            $content .= "  " . $methodName . "(";

            // Random parameters
            $params = rand(0, 3);
            $paramNames = [];
            for ($j = 0; $j < $params; $j++) {
                $paramNames[] = "param" . ($j + 1);
            }
            $content .= implode(", ", $paramNames) . ") {\n";

            // Method body
            $content .= "    console.log('Executing " . $methodName . "');\n";
            if (rand(0, 1) && !empty($paramNames)) {
                $content .= "    return " . $paramNames[array_rand($paramNames)] . ";\n";
            } else {
                $content .= "    return " . (rand(0, 1) ? 'true' : 'null') . ";\n";
            }
            $content .= "  }\n\n";
        }

        $content .= "}\n\n";

        // Add an initialization
        $content .= "// Initialize the application\n";
        $content .= "const app = new " . ucfirst($className) . "();\n";
        $content .= "console.log('Application initialized', app);\n";

        // Add event listener
        $events = ['click', 'load', 'change', 'submit'];
        $event = $events[array_rand($events)];
        $content .= "\n// Event listeners\n";
        $content .= "document.addEventListener('" . $event . "', function() {\n";
        $content .= "  console.log('Event triggered');\n";
        $content .= "});\n";

        return $content;
    }

    /**
     * Generate random CSS content
     *
     * @return string CSS content
     */
    public static function generateCssContent(): string
    {
        $content = "/* Generated CSS file */\n";
        $content .= "/* Created on: " . date('Y-m-d H:i:s') . " */\n\n";

        // Root variables
        $content .= ":root {\n";
        $content .= "  --primary-color: " . self::randomColor() . ";\n";
        $content .= "  --secondary-color: " . self::randomColor() . ";\n";
        $content .= "  --text-color: " . self::randomColor() . ";\n";
        $content .= "  --background-color: " . self::randomColor() . ";\n";
        $content .= "  --font-size: " . rand(12, 18) . "px;\n";
        $content .= "  --padding: " . rand(5, 20) . "px;\n";
        $content .= "  --margin: " . rand(5, 20) . "px;\n";
        $content .= "  --border-radius: " . rand(3, 12) . "px;\n";
        $content .= "}\n\n";

        // Body styles
        $content .= "body {\n";
        $content .= "  font-family: " . self::randomFontFamily() . ";\n";
        $content .= "  color: var(--text-color);\n";
        $content .= "  background-color: var(--background-color);\n";
        $content .= "  margin: 0;\n";
        $content .= "  padding: 0;\n";
        $content .= "  box-sizing: border-box;\n";
        $content .= "}\n\n";

        // Container
        $content .= ".container {\n";
        $content .= "  max-width: " . rand(960, 1200) . "px;\n";
        $content .= "  margin: 0 auto;\n";
        $content .= "  padding: var(--padding);\n";
        $content .= "}\n\n";

        // Generate some random element styles
        $elements = ['header', 'footer', 'main', 'section', 'article', 'aside', 'nav', 'div', 'p', 'h1', 'h2', 'h3', 'a', 'button', 'input'];
        $selectedElements = array_rand(array_flip($elements), rand(5, 10));

        foreach ($selectedElements as $element) {
            $content .= $element . " {\n";
            $properties = ['margin', 'padding', 'color', 'background-color', 'font-size', 'line-height', 'text-align', 'border', 'border-radius', 'display', 'flex-direction', 'justify-content', 'align-items'];
            $selectedProperties = array_rand(array_flip($properties), rand(3, 6));

            foreach ($selectedProperties as $property) {
                $content .= "  " . $property . ": " . self::getCssPropertyValue($property) . ";\n";
            }

            $content .= "}\n\n";
        }

        // Media query
        $content .= "@media (max-width: " . rand(600, 900) . "px) {\n";
        $content .= "  body {\n";
        $content .= "    font-size: " . rand(10, 16) . "px;\n";
        $content .= "  }\n";
        $content .= "  .container {\n";
        $content .= "    padding: " . rand(5, 15) . "px;\n";
        $content .= "  }\n";
        $content .= "}\n";

        return $content;
    }

    /**
     * Generate a random color in hex format
     *
     * @return string Color in hex format
     */
    private static function randomColor(): string
    {
        return sprintf('#%06x', rand(0, 0xFFFFFF));
    }

    /**
     * Get a random font family
     *
     * @return string Font family
     */
    private static function randomFontFamily(): string
    {
        $families = [
            'Arial, sans-serif',
            '"Helvetica Neue", Helvetica, sans-serif',
            'Georgia, serif',
            '"Times New Roman", Times, serif',
            'Verdana, Geneva, sans-serif',
            '"Courier New", Courier, monospace',
            'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
        ];

        return $families[array_rand($families)];
    }

    /**
     * Get a random CSS property value based on property name
     *
     * @param string $property CSS property name
     * @return string Property value
     */
    private static function getCssPropertyValue(string $property): string
    {
        switch ($property) {
            case 'margin':
            case 'padding':
                return rand(0, 30) . 'px';
            case 'color':
            case 'background-color':
                return 'var(--' . (rand(0, 1) ? 'primary' : 'secondary') . '-color)';
            case 'font-size':
                return rand(10, 24) . 'px';
            case 'line-height':
                return (rand(12, 20) / 10) . '';
            case 'text-align':
                $alignments = ['left', 'right', 'center', 'justify'];
                return $alignments[array_rand($alignments)];
            case 'border':
                return '1px solid ' . self::randomColor();
            case 'border-radius':
                return 'var(--border-radius)';
            case 'display':
                $displays = ['block', 'flex', 'inline-block', 'grid'];
                return $displays[array_rand($displays)];
            case 'flex-direction':
                $directions = ['row', 'column', 'row-reverse', 'column-reverse'];
                return $directions[array_rand($directions)];
            case 'justify-content':
            case 'align-items':
                $alignments = ['flex-start', 'flex-end', 'center', 'space-between', 'space-around'];
                return $alignments[array_rand($alignments)];
            default:
                return 'initial';
        }
    }

    /**
     * Generate a JavaScript file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function jsFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['content'] = $options['content'] ?? self::generateJsContent();
        $options['extension'] = 'js';
        return self::file($directory, $options, $absolute);
    }

    /**
     * Generate a CSS file with random content
     *
     * @param string|Folder $directory Directory where the file should be created
     * @param array $options Additional options
     * @param bool $absolute Whether the directory path is absolute
     * @return File The generated file
     */
    public static function cssFile(string|Folder $directory, array $options = [], bool $absolute = false): File
    {
        $options['content'] = $options['content'] ?? self::generateCssContent();
        $options['extension'] = 'css';
        return self::file($directory, $options, $absolute);
    }
}