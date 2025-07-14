<?php
namespace Lucent\Database\Attributes;

use Attribute;

#[Attribute]
class DatabaseColumn
{

    public private(set) array $column;

    public function __construct(array $properties)
    {
        //Define our column defaults
        $this->column = [
            "NAME" => null,
            "ALLOW_NULL" => true,
            "LENGTH" => 255,
            "AUTO_INCREMENT" => false,
            "TYPE" => null,
            "PRIMARY_KEY" => false,
            "DEFAULT" => null,
            "VALUES" => [],
            "UNIQUE_KEY_TO" => null,
            "ON_UPDATE" => null,
            "REFERENCES" => null,
            "UNIQUE" => null,
            "UNSIGNED" => false
        ];

        //Loop over all our properties and translate them into our column
        foreach (array_keys($this->column) as $item){
            if(array_key_exists($item,$properties)){
                $this->column[$item] = $properties[$item];
            }
        }
    }

    public function setName(string $name): void
    {
        $this->column["NAME"] = $name;
    }

    public function getName(): string
    {
        return $this->column["NAME"];
    }

    public function shouldSkip() : bool
    {
        return $this->column["AUTO_INCREMENT"];
    }

}