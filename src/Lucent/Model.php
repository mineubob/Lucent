<?php

namespace Lucent;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Facades\Log;
use ReflectionClass;

class Model
{

    private int $autoId = -1;
    protected Dataset $dataset;

    public function delete($property = "id"): bool
    {

        $query = "DELETE FROM ".$this->getSimpleClassName()." WHERE ".$property."=";

        $reflection = new ReflectionClass($this);

        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $value = $property->getValue($this);

        $query .= "'".$value."'";

        if(Database::query($query)){
            return true;
        }else{
            Log::channel("db")->error("Failed to delete model with query ".$query);
            return false;
        }
    }

    public function create() : bool
    {

        $query = "INSERT INTO ".$this->getSimpleClassName();
        $properties = [];

        $reflection = new ReflectionClass($this);

        foreach ($reflection->getProperties() as $property){

            $attributes =  $property->getAttributes(DatabaseColumn::class);

            if(count($attributes) > 0){

                $value = $property->getValue($this);
                if($value !== null){
                    $skip = false;

                    foreach ($attributes as $attribute){
                        $instance = $attribute->newInstance();
                        $skip = $instance->shouldSkip();
                    }

                    if(!$skip){
                        $properties[$property->name] = $property->getValue($this);
                    }

                }
            }
        }

        $columns = " (";
        $values = " VALUES (";

        foreach ($properties as $key => $value){
            $columns .= $key.", ";
            $values .= "'".$value."', ";
        }

        $columns = substr($columns,0,strlen($columns)-2);
        $columns .= ")";

        $query .= $columns;

        $values = substr($values,0,strlen($values)-2);
        $values .= ")";

        $query .= $values;

        $result = DB::insert($query);
        $this->autoId = DB::getLastInsertId();

        return $result;
    }

    public static function where(string $column, string $value): ModelCollection
    {
        $collection = new ModelCollection(static::class);
        return $collection->where($column,$value);
    }

    public static function limit(int $count) : ModelCollection
    {
        $collection = new ModelCollection(static::class);
        return $collection->limit($count);

    }

    public static function offset(int $count) : ModelCollection
    {
        $collection = new ModelCollection(static::class);
        return $collection->offset($count);

    }

    private function getSimpleClassName(): string
    {
        $array = explode("\\", static::class);
        return end($array);
    }

    public function getAutoId() :int{
        return $this->autoId;
    }

}