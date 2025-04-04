<?php

namespace Unit;

use Lucent\Filesystem\File;
use Lucent\Filesystem\Folder;
use Lucent\StaticAnalysis\DependencyAnalyser;
use PHPUnit\Framework\TestCase;

class DependencyAnalyserTest extends TestCase
{

    public function test_non_existent_classes_and_methods(){

        $file = $this->generateTestController();

        $this->assertNotNull($file);

        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($file);

        $dependencies = $tokenizer->run();

        foreach ($dependencies["DependencyAnalysisController.php"]["Lucent\\Http\\JsonResponse"] as $use){
            if($use["method"]["name"] !== "getAsCSV"){
                $this->assertEmpty($use["issues"]);
            }else{
                $this->assertEquals("method",$use["issues"][0]["scope"]);
                $this->assertEquals("error",$use["issues"][0]["status"]);
                $this->assertEquals("critical",$use["issues"][0]["severity"]);
            }
        }

        //$this->streamPrintJsonWithHighlighting($dependencies);
        $this->print($dependencies);

        $this->assertCount(3,$dependencies["StaticAnalysisController.php"]["Lucent\\Filesystem\\File"]);
        $this->assertCount(10,$dependencies["StaticAnalysisController.php"]["Lucent\\Http\\JsonResponse"]);

    }

    public function test_depreciated_methods(){
        $file = $this->generateTestController2();

        $this->assertNotNull($file);

        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($file);

        $dependencies = $tokenizer->run();

        $this->assertCount(3,$dependencies["StaticAnalysisController2.php"]["Lucent\\AttributeTesting"]);

        foreach ($dependencies["StaticAnalysisController2.php"]["Lucent\\AttributeTesting"] as $use){


            if($use["type"] === "function_call" && $use["method"]["name"] === "divide"){
                $this->assertCount(3,$use["issues"]);
            }

            if($use["type"] === "function_call" && $use["method"]["name"] === "multiply"){
                $this->assertCount(2,$use["issues"]);
            }

        }

        $this->print($dependencies);

    }

    public function test_chaining_methods(){
        $file = $this->generateTestController4();

        $this->assertNotNull($file);

        $tokenizer = new DependencyAnalyser();

        $tokenizer->parseFiles($file);
        $dependencies = $tokenizer->run();

        //$this->streamPrintJsonWithHighlighting($dependencies);
        //$this->assertCount(4,$dependencies["StaticAnalysisController4.php"]["Lucent\\ModelCollection"]);
        $this->assertCount(1,$dependencies["StaticAnalysisController4.php"]["Lucent\\Model"]);
        $this->print($dependencies);

    }

    public function test_variable_output_types() : void
    {
        $file = $this->generateTestController5();
        $this->assertNotNull($file);
        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($file);
        $dependencies = $tokenizer->run();
        $this->print($dependencies);

    }

    public function test_multiple_files(): void
    {
        $root = new Folder("/App");

        $files = $root->search()->extension("php")->recursive()->onlyFiles()->collect();

        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($files);
        $dependencies = $tokenizer->run();

        $this->print($dependencies);
        $this->streamPrintJsonWithHighlighting($dependencies);

        $this->assertTrue((count($dependencies) >0));
    }

    function test_no_issues() : void
    {
        $file = $this->generateTestController6();

        $this->assertNotNull($file);

        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($file);
        $dependencies = $tokenizer->run();

        $this->print($dependencies);
    }

    public function generateTestController(): File
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Exception;
        use Lucent\Facades\FileSystem;
        use Lucent\Filesystem\File;
        use Lucent\Filesystem\File as FileObject;
        use Lucent\Http\JsonResponse;

        class StaticAnalysisController
        {
           
            public function one($input) : JsonResponse
            {
            
                 $response = new JsonResponse();
                 
                 if($input === "ping"){
                     $response->setMessage("pong");
                 }else{
                     $response->setOutcome(false);
                     $response->setStatusCode(400);
                     $response->setMessage("Message not passed as url parameter.");
                     $response->addContent("test","123");
                     throw new Exception("Message not passed as url parameter.");
                 }                 
                 return $response;
            }
        
          
            public function two() : JsonResponse
            {
                 $response = new JsonResponse();
                 
                 $response->setMessage("Hello from test 2");
                 
                 $file = new File("/storage/test.txt","a test file!");
                 $file->exists();
                 $file->getAsCSV(",");
                 
                 $file = new File("/storage/test.txt");
                 
                 $file2 = new FileObject("/storage/test.txt");
                 
                 $file3 = new \Lucent\Filesystem\TextFile();

                 return $response;
            }
        }
        PHP;

        return new File("/App/Controllers/StaticAnalysisController.php",$controllerContent);
    }

    public function generateTestController2(): File
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\AttributeTesting;
        use Lucent\Http\JsonResponse;

        class StaticAnalysisController2
        {
           
            public function one($input) : JsonResponse
            {
            
                 $response = new JsonResponse();
                 
                 $test = new AttributeTesting();
                 $test->divide(10,5);
                 
                 $result = $test->multiply(2.5*5);
                           
                 return $response;
            }

        }
        PHP;

        return new File("/App/Controllers/StaticAnalysisController2.php",$controllerContent);
    }

    public function generateTestController4(): File
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\AttributeTesting;
        use Lucent\Http\JsonResponse;
        use Lucent\Model;

        class StaticAnalysisController4
        {
           
            public function index() : JsonResponse
            {
            
                 $response = new JsonResponse();
                 
                $object = new AttributeTesting();
                
                $object = $object->chain("test")->chain("123")->divide(10,2);
                
                var_dump($object);
                
                $user = Model::where("organisation_id",123)->where("user_id",456)->getFirst();

                echo($user->getName());                   
                 return $response;
            }
             

        }
        PHP;

        return new File("/App/Controllers/StaticAnalysisController4.php",$controllerContent);
    }

    public function generateTestController5(): File
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\AttributeTesting;
        use Lucent\Http\JsonResponse;

        class StaticAnalysisController5
        {
           
            public function index() : JsonResponse
            {
                $testObject = new AttributeTesting();
                
               // Create a temporary file for file output functions
                $tempFile = fopen($logFile, 'w');
                
                // Start output buffering to capture output
                ob_start();
                
                // 1. Basic output language constructs
                echo $testObject;                     // Basic echo
                print $testObject;                    // Basic print
                
                // 2. Variable information display functions
                var_dump($testObject);                // Shows detailed type information
                print_r($testObject);                 // Human-readable output
                var_export($testObject, true);        // Valid PHP code output
                debug_zval_dump($testObject);         // Shows reference count info
                
                // 3. Formatted output functions
                printf("Object: %s\n", $testObject);              // Formatted output
                $formatted = sprintf("Object: %s\n", $testObject); // Returns formatted string
                fprintf($tempFile, "To file: %s\n", $testObject);  // To file
                vprintf("Object with array: %s\n", [$testObject]); // Variable args
                vsprintf("Object with array: %s\n", [$testObject]); // Variable args, returns string
                
                // 4. File output functions
                fwrite($tempFile, $testObject . "\n");             // Binary-safe file write
                fputs($tempFile, $testObject . "\n");              // Alias of fwrite
                file_put_contents($logFile . ".obj", $testObject); // Write to file
                
                // Create a CSV file with object data
                if (is_object($testObject) && method_exists($testObject, '__toString')) {
                    $csvData = [['id', 'object'], [1, $testObject]];
                    $csvFile = fopen($logFile . ".csv", 'w');
                    foreach ($csvData as $row) {
                        fputcsv($csvFile, $row);                  // Format line as CSV and write
                    }
                    fclose($csvFile);
                }
                
                // 5. Error and logging functions
                error_log("Object: " . $testObject, 3, $logFile . ".error"); // Write to log
                trigger_error("User error with object: " . $testObject, E_USER_NOTICE);  // Generate user error
                user_error("Another error with object: " . $testObject, E_USER_NOTICE);  // Alias of trigger_error
                
                // System logging
                syslog(LOG_INFO, "Syslog message with object: " . $testObject);  // System log message
                
                // 6. Buffer and system output functions
                // First output the object
                echo "Buffer test: " . $testObject;
                ob_flush();                        // Flush output buffer
                flush();                           // Flush system buffers
                
                // 7. File content output (using a temp file with the object serialized)
                $tempObjFile = $logFile . '.serialized';
                file_put_contents($tempObjFile, serialize($testObject));
                readfile($tempObjFile);                // Output file contents
                
                // Command execution with object
                passthru("echo " . escapeshellarg((string)$testObject)); // Execute command and output
                
                // 8. Syntax highlighting
                $codeWithObject = '<?php $obj = ' . var_export($testObject, true) . '; echo $obj; ?>';
                highlight_string($codeWithObject);  // Highlight code containing the object
                
                // Using object with additional output functions
                fseek($tempFile, 0); // Rewind the file pointer
                fwrite($tempFile, serialize($testObject)); // Write serialized object to file
                fseek($tempFile, 0); // Rewind again
                fpassthru($tempFile);              // Output remaining data from file pointer
                
                // CLI-specific output (if you're analyzing CLI scripts)
                if (php_sapi_name() === 'cli') {
                    cli_set_process_title("Object: " . (string)$testObject);  // Set process title
                    readline_output_character((string)$testObject[0] ?? "*"); // Output first char of string
                }
                
                // Clean up
                fclose($tempFile);
                @unlink($tempObjFile);
                
                // Get and clean the output buffer
                $output = ob_get_clean();
                echo $output;  // Display the captured output
            }
             

        }
        PHP;

        return new File("/App/Controllers/StaticAnalysisController5.php",$controllerContent);
    }

    public function generateTestController6(): File
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Lucent\AttributeTesting;
        use Lucent\Http\JsonResponse;use Lucent\Model;

        class StaticAnalysisController6
        {
           
            public function index() : JsonResponse
            {
                
                $user = Model::where("user_id",123)->where("activated",true)->first();
                
                if($user === null){
                    echo "User not found";
                }else{
                   echo var_dump($user);
                }
                
            }
             

        }
        PHP;

        return new File("/App/Controllers/StaticAnalysisController6.php",$controllerContent);
    }



    function streamPrintJsonWithHighlighting($data) : void
    {
        // Convert to pretty JSON
        $jsonString = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines = explode("\n", $jsonString);

        // Colors
        $colors = [
            'key'      => "\033[32m", // Green
            'string'   => "\033[33m", // Yellow
            'number'   => "\033[36m", // Cyan
            'boolean'  => "\033[35m", // Purple
            'bracket'  => "\033[37m", // White
            'reset'    => "\033[0m"   // Reset
        ];

        // Process line by line
        foreach ($lines as $line) {
            // Check for brackets/braces
            $line = preg_replace('/([{}\[\]])/', "{$colors['bracket']}$1{$colors['reset']}", $line);

            // Check for property keys
            $line = preg_replace('/("([^"]*)"):/', "{$colors['key']}\"$2\"{$colors['reset']}:", $line);

            // Check for string values
            $line = preg_replace('/: "([^"]*)"(,?)$/', ": {$colors['string']}\"$1\"{$colors['reset']}$2", $line);
            $line = preg_replace('/: "([^"]*)"(,?)(\s+)/', ": {$colors['string']}\"$1\"{$colors['reset']}$2$3", $line);

            // Check for numbers
            $line = preg_replace('/: (\d+)(,?)$/', ": {$colors['number']}$1{$colors['reset']}$2", $line);
            $line = preg_replace('/: (\d+)(,?)(\s+)/', ": {$colors['number']}$1{$colors['reset']}$2$3", $line);

            // Check for booleans/null
            $line = preg_replace('/: (true|false|null)(,?)$/', ": {$colors['boolean']}$1{$colors['reset']}$2", $line);
            $line = preg_replace('/: (true|false|null)(,?)(\s+)/', ": {$colors['boolean']}$1{$colors['reset']}$2$3", $line);

            echo $line . PHP_EOL;
        }
    }

    /**
     * Display Lucent dependency issues with a focus on deprecation and removal warnings
     *
     * @param array $dependencies The dependency array from the analyzer
     * @return void
     */
    function print(array $dependencies): void
    {
        // ANSI color codes as variables, not constants
        $COLOR_RED = "\033[31m";
        $COLOR_YELLOW = "\033[33m";
        $COLOR_BLUE = "\033[36m";
        $COLOR_BOLD = "\033[1m";
        $COLOR_RESET = "\033[0m";

        // Count the issues by file
        $fileIssues = [];
        $totalDeprecated = 0;
        $totalRemoved = 0;

        // Show header
        echo $COLOR_BOLD . "UPDATE COMPATIBILITY" . $COLOR_RESET . PHP_EOL;
        echo "============================" . PHP_EOL;

        foreach ($dependencies as $fileName => $file) {
            $fileHasIssues = false;
            $fileDeprecations = 0;
            $fileRemovals = 0;

            foreach ($file as $dependencyName => $dependency) {
                foreach ($dependency as $use) {
                    if (!empty($use["issues"])) {
                        // Count issues by type
                        foreach ($use["issues"] as $issue) {
                            if (isset($issue["status"])) {
                                if ($issue["status"] === "error") {
                                    $fileRemovals++;
                                    $totalRemoved++;
                                } elseif ($issue["status"] === "warning") {
                                    $fileDeprecations++;
                                    $totalDeprecated++;
                                }
                            }
                        }

                        $fileHasIssues = true;

                        // Show file name if this is the first issue in the file
                        if (!isset($fileIssues[$fileName])) {
                            echo $COLOR_BOLD . $fileName . $COLOR_RESET . PHP_EOL;
                            $fileIssues[$fileName] = true;
                        }

                        // Show the dependency usage
                        $lineInfo = "  Line " . str_pad($use["line"], 4, ' ', STR_PAD_LEFT) . ": ";
                        echo $lineInfo . $COLOR_BLUE . $dependencyName . $COLOR_RESET;

                        // Show method if applicable
                        if (isset($use["method"]) && isset($use["method"]["name"])) {
                            echo "->" . $use["method"]["name"] . "()";
                        }

                        echo PHP_EOL;

                        // Show each issue with appropriate color and clear labeling
                        foreach ($use["issues"] as $issue) {
                            $color = $COLOR_YELLOW; // Default for warnings
                            $issueType = "DEPRECATED";

                            if (isset($issue["status"]) && $issue["status"] === "error") {
                                $color = $COLOR_RED;
                                $issueType = "REMOVED";
                            }

                            // Format message
                            $message = $issue["message"] ?? "Unknown issue";
                            $since = "";

                            // Extract version info if available in the message
                            if (preg_match('/since\s+version\s+([0-9.]+)/i', $message, $matches)) {
                                $since = " (since v" . $matches[1] . ")";
                            } else if (preg_match('/since\s+v([0-9.]+)/i', $message, $matches)) {
                                $since = " (since v" . $matches[1] . ")";
                            }

                            // Show scope if provided
                            $scopeText = "";
                            if (isset($issue["scope"])) {
                                $scopeText = " " . $issue["scope"];
                            }

                            echo "    " . $color . "⚠ " . $issueType . $scopeText . $since . ": " . $COLOR_RESET . $message . PHP_EOL;
                        }
                    }
                }
            }

            // Show file summary if issues were found
            if ($fileHasIssues) {
                echo PHP_EOL;
            }
        }

        // Show grand total
        if ($totalDeprecated > 0 || $totalRemoved > 0) {
            echo "============================" . PHP_EOL;
            echo $COLOR_BOLD . "SUMMARY: " . $COLOR_RESET;

            if ($totalRemoved > 0) {
                echo $COLOR_RED . $totalRemoved . " removed" . $COLOR_RESET;
                if ($totalDeprecated > 0) {
                    echo ", ";
                }
            }

            if ($totalDeprecated > 0) {
                echo $COLOR_YELLOW . $totalDeprecated . " deprecated" . $COLOR_RESET;
            }

            echo " components found in " . count($fileIssues) . " files" . PHP_EOL;
            echo "Update your code to ensure compatibility with the latest Lucent version" . PHP_EOL;
        } else {
            echo $COLOR_BOLD . "No compatibility issues found! Your code is up to date." . $COLOR_RESET . PHP_EOL;
        }
    }




}