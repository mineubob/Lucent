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


        $this->assertCount(5,$dependencies["StaticAnalysisController.php"]["Lucent\\Filesystem\\File"]);
        $this->assertCount(10,$dependencies["StaticAnalysisController.php"]["Lucent\\Http\\JsonResponse"]);

        foreach ($dependencies["StaticAnalysisController.php"]["Lucent\\Http\\JsonResponse"] as $use){
            if($use["method"]["name"] !== "getAsCSV"){
                $this->assertEmpty($use["issues"]);
            }else{
                $this->assertEquals("method",$use["issues"][0]["scope"]);
                $this->assertEquals("error",$use["issues"][0]["status"]);
                $this->assertEquals("critical",$use["issues"][0]["severity"]);
            }
        }

        //$this->streamPrintJsonWithHighlighting($dependencies);
    }

    public function test_depreciated_methods(){
        $file = $this->generateTestController2();

        $this->assertNotNull($file);

        $tokenizer = new DependencyAnalyser();
        $tokenizer->parseFiles($file);

        $dependencies = $tokenizer->run();

        $this->assertCount(3,$dependencies["StaticAnalysisController2.php"]["Lucent\\AttributeTesting"]);

        foreach ($dependencies["StaticAnalysisController2.php"]["Lucent\\AttributeTesting"] as $use){


            if($use["type"] === "function_call"){
                $this->assertCount(1,$use["issues"]);
                $this->assertEquals("method",$use["issues"][0]["scope"]);
            }

            if($use["type"] === "instantiation"){
                $this->assertCount(0,$use["issues"]);
            }
        }

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