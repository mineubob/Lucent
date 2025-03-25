<?php

namespace Unit;

use Lucent\Facades\File;
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

        $this->streamPrintJsonWithHighlighting($dependencies);


        foreach ($dependencies["StaticAnalysisController.php"]["Lucent\\Http\\JsonResponse"] as $use){
            if($use["method"]["name"] !== "getAsCSV"){
                $this->assertEmpty($use["issues"]);
            }else{
                $this->assertEquals("method",$use["issues"][0]["scope"]);
                $this->assertEquals("error",$use["issues"][0]["status"]);
                $this->assertEquals("critical",$use["issues"][0]["severity"]);
            }
        }


        $this->assertCount(5,$dependencies["StaticAnalysisController.php"]["Lucent\\Filesystem\\File"]);
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

        $this->streamPrintJsonWithHighlighting($dependencies);
    }

    public function test_chaining_methods(){
        $file = $this->generateTestController4();

        $this->assertNotNull($file);

        $tokenizer = new DependencyAnalyser();

        $tokenizer->parseFiles($file);
        $dependencies = $tokenizer->run();

        $this->assertCount(4,$dependencies["StaticAnalysisController4.php"]["Lucent\\ModelCollection"]);
        $this->assertCount(1,$dependencies["StaticAnalysisController4.php"]["Lucent\\Model"]);

        $this->streamPrintJsonWithHighlighting($dependencies);
    }

    public function test_variable_output_types() : void
    {
        $file = $this->generateTestController5();
        $this->assertNotNull($file);
        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($file);
        $dependencies = $tokenizer->run();
        $this->streamPrintJsonWithHighlighting($dependencies);
    }

    public function generateTestController(): \Lucent\Filesystem\File
    {
        $controllerContent = <<<'PHP'
        <?php
        namespace App\Controllers;
        
        use Exception;
        use Lucent\Facades\File;
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
                 
                 $file = File::create("/storage/test.txt","a test file!");
                 $file->exists();
                 $file->getAsCSV(",");
                 
                 $file = new \Lucent\Filesystem\File("/storage/test.txt");
                 
                 $file2 = new FileObject("/storage/test.txt");
                 
                 $file3 = new \Lucent\Filesystem\TextFile();

                 return $response;
            }
        }
        PHP;

        return File::create("App/Controllers/StaticAnalysisController.php",$controllerContent);
    }

    public function generateTestController2(): \Lucent\Filesystem\File
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

        return File::create("App/Controllers/StaticAnalysisController2.php",$controllerContent);
    }

    public function generateTestController4(): \Lucent\Filesystem\File
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

        return File::create("App/Controllers/StaticAnalysisController4.php",$controllerContent);
    }

    public function generateTestController5(): \Lucent\Filesystem\File
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

        return File::create("App/Controllers/StaticAnalysisController5.php",$controllerContent);
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


}