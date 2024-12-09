<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - nextstats-auth
 * Last Updated - 6/11/2023
 */

namespace Lucent\Http;


use Lucent\Database\Dataset;
use Lucent\Model;
use Lucent\Validation\BlankRule;

class Request
{

    private array $post = [];
    private array $get = [];
    private array $validationErrors = [];

    private array $modelCache = [];

    private Session $session;

    private array $urlVars;


    public function __construct(){
        $this->post = $this->sanitizeUserInput($_POST);
        $this->get = $this->sanitizeUserInput($_GET);
        $this->session = new Session();
    }

    public function all() : array
    {
        if($_SERVER["REQUEST_METHOD"] === "POST"){
            return $this->post;
        }

        if($_SERVER["REQUEST_METHOD"] === "GET"){
            return $this->get;
        }

        return [];
    }

    public function dataset() : Dataset
    {
        return new Dataset($this->all());
    }

    public function except(array $keys) : array
    {
        $new = $this->post;
        foreach ($keys as $key){
            unset($new[$key]);
        }

        return $new;
    }

    public function input(string $key, $default = null) : null|string
    {
        if($_SERVER["REQUEST_METHOD"] === "POST"){
            if(array_key_exists($key,$this->post)) return $this->post[$key];
        }

        if($_SERVER["REQUEST_METHOD"] === "GET"){
            if(array_key_exists($key,$this->get)) return $this->get[$key];
        }

        return $default;
    }

    public function setInput(string $key, $value) : void
    {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $this->post[$key] = $value;
        }

        if ($_SERVER["REQUEST_METHOD"] === "GET") {
            $this->get[$key] = $value;
        }
    }

    private function sanitizeUserInput(array $input) : array
    {
        $filter =  function ($var){
            return ($var !== NULL && $var !== FALSE && trim($var) !== "");
        };

        return array_filter($input,$filter);
    }

    public function validate($rules): bool
    {
        $instance = null;
        $this->validationErrors = [];

        if(gettype($rules) === "string"){
            $instance = new $rules();
        }else{
            $instance = new BlankRule();
            $instance->setRules($rules);
        }

        $instance->setCallingRequest($this);
        $this->validationErrors = $instance->validate($this->all());

        return sizeof($this->validationErrors) === 0;
    }

    public function cacheModel(string $key, Model $model) : void
    {
        $this->modelCache[$key] = $model;
    }

    public function getCachedModel(string $key) : Model
    {
        return $this->modelCache[$key];
    }

    public function getValidationErrors() : array
    {
        return $this->validationErrors;
    }

    public function session(): Session{
        return $this->session;
    }

    public function getUrlVariable(string $key) : ?string{
        if(!array_key_exists($key,$this->urlVars)){
            return null;
        }
        return $this->urlVars[$key];
    }

    public function setUrlVars(array $vars){
        $this->urlVars = $vars;
    }
}