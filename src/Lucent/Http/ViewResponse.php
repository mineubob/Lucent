<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager
 * Last Updated - 18/11/2023
 */

namespace Lucent\Http;


class ViewResponse extends Response
{

    private string $path;
    private ?string $layout;

    private array $bladeTemplateCache;

    private array $bladeSections;

    public function __construct(string $path){
        parent::__construct();

        $this->bladeTemplateCache = [];
        $this->bladeSections = [];
        $this->path = $path;
    }

    public function pass(array $data): ViewResponse{
        return $this;
    }

    public function setLayout(?string $folder): void
    {
        $this->layout = $folder;
    }

    public function execute(): void
    {

        if(file_exists(VIEWS.$this->path)){

            if(!str_ends_with($this->path,".blade.php")){
                require_once VIEWS.DIRECTORY_SEPARATOR."layouts".DIRECTORY_SEPARATOR.$this->layout.DIRECTORY_SEPARATOR."header.php";
            }

            if(str_ends_with($this->path,".blade.php")){
                $page = file_get_contents(VIEWS.$this->path);
                $layout = $this->getBladeLayout($page);

                $page = str_replace("@extends('".$layout["name"]."')","",$page);

                if($layout !== []){

                    if(str_contains($layout["content"],"@yield('content')")){
                        $page = str_replace("@yield('content')",$page,$layout["content"]);
                    }
                    $page = $this->processBladeVariables($page);
                    eval($this->processBladeHtml("?>".$page));
                }


            }else{
                require_once $this->path;
            }

            if(!str_ends_with($this->path,".blade.php")){
                require_once VIEWS.DIRECTORY_SEPARATOR."layouts".DIRECTORY_SEPARATOR.$this->layout.DIRECTORY_SEPARATOR."footer.php";
            }

        }else{
            var_dump("Error 500, Requested view not found!");
            var_dump($this->path);
            die;
        }
    }

    private function processBladeVariables(string $contents, array $component = ["vars"=>[]]) : string
    {
        $pattern = '/\{\{[^}]*\}\}/';

        return preg_replace_callback($pattern,function ($matches) use ($component) {
            $line = substr($matches[0],3);
            $line = substr($line,0,strlen($line)-2);

            //Check if we have a matching value, if so replace it and return, if not found check the view bag.
            if(array_key_exists($line,$component["vars"])){
                return $component["vars"][$line];
            }else if(array_key_exists($line,View::Bag()->all())){
                return View::Bag()->get($line);
            }

            //Check for OR
            if(str_contains($line," ?? ")){
                $parts = explode(" ?? ",$line);

                if(array_key_exists($parts[0],$component["vars"])){
                    return $component["vars"][$parts[0]];
                }else{
                    return $parts[1];
                }
            }

            //Check for if set statement
            if(str_contains($line," ??! ")){
                $parts = explode(" ??! ",$line);
                $secondParts = explode(" , ",$parts[1]);

                $success = trim($secondParts[0]);
                $failure = trim($secondParts[1]);

                if(array_key_exists($parts[0],$component["vars"])){
                    return str_replace('"','',$success);
                }else if(array_key_exists($parts[0],View::Bag()->all())){
                    return str_replace('"','',$success);
                }else{
                    return str_replace('"','',$failure);
                }
            }

            if(str_starts_with($line,"view.bag.")){
                return View::Bag()->get($line);
            }

            return $line;
        },$contents);
    }

    private function processBladeHtml(string $contents): string
    {
        $pattern = '/<x-.*\/>/';

        return preg_replace_callback($pattern,function ($matches) {
            $component = $this->getBladeComponent(htmlentities($matches[0]));

            if(!array_key_exists($component["name"],$this->bladeTemplateCache)){
                $this->bladeTemplateCache[$component["name"]] = file_get_contents($component["path"]);
            }

            $new = $this->bladeTemplateCache[$component["name"]];

            return $this->processBladeVariables($new,$component);

        },$contents);
    }


    private function getBladeComponent(string $input): array{
        $component = [];

        //Trim the <x- and /> from the start and end.
        $input = substr($input,0,strlen($input)-5);
        $input = substr($input,6);
        $component["vars"] = [];

        //Check if we have any variables to pass or not.
        if(!str_contains($input,"  ")){
            $component["name"] = $input;
        }else {
            $array = explode("  ", $input);
            $component["name"] = $array[0];
            array_shift($array);
            foreach ($array as $var){
                $kv = explode("=",$var);
                $component["vars"][$kv[0]] = str_replace("&quot;",'',$kv[1]);
            }

        }

        $component["path"] = VIEWS."Blade".DIRECTORY_SEPARATOR."Components".DIRECTORY_SEPARATOR.$component["name"].".blade.php";

        return $component;
    }

    function getBladeLayout($input) : ?array
    {
        $component = [];

        preg_match('/@extends\(\'[^\']*\'\)/', $input, $output_array);

        $name = str_replace("@extends('",'',$output_array[0]);
        $name = str_replace("')",'',$name);
        $component["name"] = $name;
        $component["path"] = VIEWS."Blade".DIRECTORY_SEPARATOR.str_replace('.',DIRECTORY_SEPARATOR,$name).".blade.php";
        $component["content"] = file_get_contents($component["path"]);

        foreach (View::Bag()->all() as $key => $value){
            if(is_string($value) or is_numeric($value)) {
                $component["content"] = str_replace('{{$' . $key . '}}', $value, $component["content"]);
            }
        }

        return $component;
    }

    private function processBladeDirectives(string $contents): string
    {



        return $contents;
    }
}