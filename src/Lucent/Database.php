<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - nextstats-web
 * Last Updated - 30/10/2023
 */

namespace Lucent;

use Lucent\Facades\App;
use Lucent\Facades\Log;
use mysqli;
use mysqli_result;

class Database
{

    public static function query(string $query): bool|mysqli_result
    {
        Log::channel("db")->info($query);
        return Database::getConnection()->query($query);
    }

    public static function fetch(string $query): array
    {
        Log::channel("db")->info($query);
        $results = Database::getConnection()->query($query)->fetch_assoc();
        if($results !== null){
            return $results;
        }else{
            return [];
        }
    }

    public static function fetchAll(string $query) : array
    {
        Log::channel("db")->info($query);
        $query = Database::getConnection()->query($query);
        $results = $query->fetch_all();
        $fields = $query->fetch_fields();

        $output = [];

        foreach ($results as $result){
            $row = [];
            $columnId = 0;
            foreach ($result as $column){
                $row[$fields[$columnId] -> name] = $column;
                $columnId++;
            }

            array_push($output,$row);
        }

        return $output;
    }

    private static function getConnection(): mysqli
    {

        $username = App::env("DB_USERNAME");
        $password = App::env("DB_PASSWORD");
        $host = App::env("DB_HOST");
        $port = App::env("DB_PORT");
        $database = App::env("DB_DATABASE");

        return new mysqli($host,$username,$password,$database,$port);
    }

}