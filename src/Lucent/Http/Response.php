<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 14/11/2023
 */

namespace Lucent\Http;

abstract class Response
{

    private bool $outcome;

    private string $message;
    private int $statusCode;

    private array $content = [];
    private array $errors = [];


    public function __construct(){

        $this->outcome = true;
        $this->message =  "Request successfully executed.";
        $this->statusCode = 200;
    }

    public function setOutcome(bool $outcome){
        $this->outcome = $outcome;
    }

    public function getOutcome(){
        return $this->outcome;
    }

    public function setMessage(string $message){
        $this->message = $message;
    }

    public function setStatusCode(int $statusCode){
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int{
        return $this->statusCode;
    }

    public function addContent(string $key, $content): void
    {
        $this->content[$key] = $content;
    }

    public function addError(string $key, $error){
        $this->outcome = false;
        $this->statusCode = 400;
        $this->errors[$key] = $error;
    }

    public function addErrors(array $errors ,$message = ""): void
    {
        $this->outcome = false;
        $this->statusCode = 400;
        foreach ($errors as $error){
            $this->errors[$error] = $message;

        }
    }

    public function getArray(): array
    {
        $array["outcome"] = $this->outcome;
        $array["status"] = $this->statusCode;
        $array["message"] = $this->message;
        $array["content"] = $this->content;
        $array["errors"] = $this->errors;


        return $array;
    }

    public abstract function execute();


}