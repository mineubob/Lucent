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

    public function setOutcome(bool $outcome) : Response
    {
        $this->outcome = $outcome;
        return $this;
    }

    public function getOutcome(){
        return $this->outcome;
    }

    public function setMessage(string $message) : Response
    {
        $this->message = $message;
        return $this;
    }

    public function setStatusCode(int $statusCode) : Response
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getStatusCode(): int{
        return $this->statusCode;
    }

    public function addContent(string $key, $content): Response
    {
        $this->content[$key] = $content;
        return $this;
    }

    public function addError(string $key, $error) : Response{
        $this->outcome = false;
        $this->statusCode = 400;
        $this->errors[$key] = $error;
        return $this;
    }

    public function addErrors(array $errors ,$message = ""): Response
    {
        $this->outcome = false;
        $this->statusCode = 400;
        foreach ($errors as $error){
            $this->errors[$error] = $message;

        }
        return $this;
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