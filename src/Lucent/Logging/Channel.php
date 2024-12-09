<?php

namespace Lucent\Logging;

class Channel
{
    private string $driver;
    private string $path;

    public function __construct(string $driver, string $path){
        $this->driver = $driver;
        $this->path = $path;
    }

    public function info($message): void
    {
        if($this->driver === "local_file"){
            $file = fopen(EXTERNAL_ROOT."Logs".DIRECTORY_SEPARATOR.$this->path,"a");
            fwrite($file,"[INFO] ".date('Y-m-d H:i:s')." | ".$message."\n");
            fclose($file);
        }

    }

    public function error($message) : void
    {
        if($this->driver === "local_file"){
            $file = fopen(EXTERNAL_ROOT."Logs".DIRECTORY_SEPARATOR.$this->path,"a");
            fwrite($file,"[ERROR] ".date('Y-m-d H:i:s')." | ".$message."\n");
            fclose($file);
        }
    }

}