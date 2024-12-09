<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 7/11/2023
 */

namespace Lucent\Database;

use Lucent\Database;
use Lucent\Database\Attributes\DatabaseColumn;
use ReflectionClass;

class Migration
{

    private array $types;
    private ?string $primaryKey;

    private array $callbacks = [];

    public function __construct()
    {
        $this->types[1] = "binary";
        $this->types[2] = "tinyint";
        $this->types[3] = "decimal";
        $this->types[4] = "int";
        $this->types[5] = "json";
        $this->types[6] = "timestamp";
        $this->types[7] = "enum";
        $this->types[8] = "date";
        $this->types[10] = "text";
        $this->types[12] = "varchar";
    }

    public function make($class): bool
    {

        $reflection = new ReflectionClass($class);

        $name = $reflection->getShortName();

        $query = "DROP TABLE IF EXISTS ".$name;
        if(!Database::query($query)){
            return false;
        }

        $query = "CREATE TABLE `".$name."` (";

        foreach ($reflection->getProperties( )as $property){

            $attributes =  $property->getAttributes(DatabaseColumn::class);

            foreach ($attributes as $attribute){
                $instance = $attribute->newInstance();
                $instance->setName($property->name);
                $query .= $this->buildColumnString($instance->column);
            }
        }

        foreach ($this->callbacks as $callback){
            $query .= $callback.",";
        }

        $query .= "PRIMARY KEY (`".$this->primaryKey."`)";
        $query .= ");";


        return Database::query($query);
    }

    private function getClassShortName($reflection): string
    {

        return trim($reflection->getName(),$reflection->getNamespaceName());
    }

    private function buildColumnString(array $column): string
    {
        $string = match ($column["TYPE"]) {
            LUCENT_DB_DECIMAL => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]] . "(20,2)",

            LUCENT_DB_JSON, LUCENT_DB_TIMESTAMP, LUCENT_DB_DATE => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]],

            LUCENT_DB_ENUM => "`" . $column["NAME"] . "` " .$this->types[$column["TYPE"]].$this->buildValues($column["VALUES"]),

            default => "`" . $column["NAME"] . "` " . $this->types[$column["TYPE"]] . "(" . $column["LENGTH"] . ")",
        };

        if(!$column["ALLOW_NULL"]){
            $string .= " NOT NULL";
        }

        if($column["PRIMARY_KEY"] === true){
            $this->primaryKey = $column["NAME"];
        }

        if($column["AUTO_INCREMENT"]){
            $string .= " AUTO_INCREMENT";
        }

        if($column["DEFAULT"] !== null){
            if($column["DEFAULT"] !== LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP){
                $string .= " DEFAULT '".$column["DEFAULT"]."'";
            }else{
                $string .= " DEFAULT ".$column["DEFAULT"];
            }
        }

        if($column["ON_UPDATE"] !== null){
            $string .= " ON UPDATE ".$column["ON_UPDATE"];
        }


        if($column["UNIQUE"] !== null){
            $callback = "UNIQUE (".$column["NAME"].")";
            array_push($this->callbacks,$callback);
        }

        if($column["UNIQUE_KEY_TO"] !== null){
            $callback = "UNIQUE KEY unique_".$column["NAME"]."_to_".$column["UNIQUE_KEY_TO"]." (".$column["UNIQUE_KEY_TO"].",".$column["NAME"].")";
            array_push($this->callbacks,$callback);
        }

        if($column["REFERENCES"] !== null){
            $callback = "FOREIGN KEY (".$column["NAME"].") REFERENCES ".$column["REFERENCES"];
            array_push($this->callbacks,$callback);
        }

        return $string.",";
    }


    private function buildValues(array $values): string
    {
        $output = "(";
        foreach ($values as $value){
            $output .= "'".$value."',";
        }
        $output= rtrim($output,",");

       return  $output .= ")";
    }


}