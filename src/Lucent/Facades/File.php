<?php

namespace Lucent\Facades;

class File
{
    private static string $root_path;

    public static function rootPath() : string{
        return self::$root_path;
    }


    public static function overrideRootPath(string $path) : void
    {
        self::$root_path = $path;
    }

}