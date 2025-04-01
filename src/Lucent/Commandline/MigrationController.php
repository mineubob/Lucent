<?php

namespace Lucent\Commandline;


use Lucent\Database\Migration;
use Lucent\Facades\FileSystem;

class MigrationController
{

    private Migration $migration;

    public function __construct(){
        $this->migration = new Migration();
    }

    public function make(string $class): string
    {

        if(!file_exists(FileSystem::rootPath().DIRECTORY_SEPARATOR.$class.".php")){

            return "Invalid model class name provided, ".FileSystem::rootPath().DIRECTORY_SEPARATOR.$class.".php"." was not found";
        }

        $className = str_replace('/', '\\', $class); // Converts to "Models\User"

        //Execute our migration
        if($this->migration->make($className)){
            return "Successfully performed database migration";
        }

        return "Failed to perform database migration..";
    }


}