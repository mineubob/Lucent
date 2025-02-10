<?php

namespace Lucent\Database;

use Lucent\Facades\App;
use mysqli;

class DB
{
    private static $lastId = -1;

    public static function connection(): mysqli
    {
        $username = App::env("DB_USERNAME");
        $password = App::env("DB_PASSWORD");
        $host = App::env("DB_HOST");
        $port = App::env("DB_PORT");
        $database = App::env("DB_DATABASE");

        return new mysqli($host,$username,$password,$database,$port);
    }

    public static function insert(string $query) : bool
    {
        $sql = DB::connection();
        $outcome  = $sql->query($query);
        DB::$lastId = $sql->insert_id;
        return $outcome;
    }

    public static function getLastInsertId(): string|int
    {
        return DB::$lastId;
    }

}