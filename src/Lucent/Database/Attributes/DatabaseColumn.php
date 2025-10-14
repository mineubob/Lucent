<?php
namespace Lucent\Database\Attributes;

use Attribute;
use Deprecated;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseColumn
{
    public private(set) array $column;

    public function __construct(array $properties)
    {
        @trigger_error(
            sprintf('%s is deprecated. Use %s instead.', self::class, \Lucent\Model\Column::class),
            E_USER_DEPRECATED
        );

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
        foreach (array_keys($this->column) as $item) {
            if (array_key_exists($item, $properties)) {
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

    public function shouldSkip(): bool
    {
        return $this->column["AUTO_INCREMENT"];
    }

    public function toModelColumn(): \Lucent\Model\Column
    {
        return new \Lucent\Model\Column(
            type: \Lucent\Model\ColumnType::from($this->column['TYPE']),
            name: $this->column['NAME'],
            nullable: $this->column['ALLOW_NULL'],
            length: $this->column['LENGTH'],
            autoIncrement: $this->column['AUTO_INCREMENT'],
            primaryKey: $this->column['PRIMARY_KEY'],
            default: $this->column['DEFAULT'],
            values: $this->column['VALUES'],
            references: $this->column['REFERENCES'],
            unique: $this->column['UNIQUE'],
            unsigned: $this->column['UNSIGNED']
        );
    }

    public static function fromProperty(ReflectionProperty $property): ?self
    {
        $attributes = $property->getAttributes(self::class);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}