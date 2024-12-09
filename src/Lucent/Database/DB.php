<?php

namespace Lucent\Database;

use Lucent\Facades\App;
use mysqli;

class DB
{
    private static $lastId = -1;

    public static function connection(): mysqli
    {
        $username = App::Env("DB_USERNAME");
        $password = App::Env("DB_PASSWORD");
        $host = App::Env("DB_HOST");
        $port = App::Env("DB_PORT");
        $database = App::Env("DB_DATABASE");

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