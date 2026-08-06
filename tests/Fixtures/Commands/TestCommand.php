<?php
namespace App\Commands;

class TestCommand
{
   
    public function run() : string
    {
         return "Test command successfully run";
    }
    
        
    public function var($var) : string
    {
         return $var;
    }
    
     public function var2() : string
    {
         return "var2";
    }

}