<?php

namespace Lucent\Database;

class Dataset
{

    private array $data;

    public function __construct(array $data){
        $this->data =$data;
    }

    public function get(string $key,$default = null){
        if(array_key_exists($key,$this->data)){
            return $this->data[$key];
        }else{
            return $default;
        }
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function integer(string $key, int $default = -1) : int
    {
        if(array_key_exists($key,$this->data)){
            return (int)$this->data[$key];
        }else{
            return $default;
        }
    }

    public function except(array $keys): Dataset
    {
        $new = $this->data;
        foreach ($keys as $key){
            unset($new[$key]);
        }

        $this->data = $new;

        return $this;
    }

    public function array() : array
    {
        return $this->data;
    }

}