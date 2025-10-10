<?php

namespace Lucent\Model;

use Attribute;
use ReflectionProperty;

enum ColumnType: string
{
    case BINARY = "binary";
    case TINYINT = "tinyint";
    case DECIMAL = "decimal";
    case INT = "int";
    case JSON = "json";
    case TIMESTAMP = "timestamp";
    case ENUM = "enum";
    case DATE = "date";
    case TEXT = "text";
    case VARCHAR = "varchar";
    case FLOAT = "float";
    case DOUBLE = "double";
    case BOOLEAN = "boolean";
    case CHAR = "bar";
    case LONGTEXT = "longtext";
    case MEDIUMTEXT = "mediumtext";
    case BIGINT = "bigint";
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public private(set) ?string $classPropertyName;

    /**
     * @param ColumnType            $type           The database type.
     * @param string|null           $name           The column name. If null, defaults to the property name.
     * @param bool|null             $nullable      Whether NULL values are allowed. If null, unspecified.
     * @param int|null              $length         Character length (for string types).
     * @param bool|null             $autoIncrement  Whether the column auto-increments.
     * @param bool|null             $primaryKey     Whether this column is a primary key.
     * @param mixed                 $default        The default value for the column.
     * @param array<string>|null    $values         Allowed enum values if type is LUCENT_DB_ENUM.
     * @param string|null           $references     Foreign key reference target.
     * @param bool|null             $unique         Whether the column should be unique.
     * @param bool|null             $unsigned       Whether the column is unsigned (for numeric types).
     * 
     * @throws \InvalidArgumentException If the type is not a valid database type.
     */
    public function __construct(
        public ColumnType $type,
        public ?string $name = null,
        public ?bool $nullable = null,
        public ?int $length = null,
        public ?bool $primaryKey = null,
        public mixed $default = null,
        public ?array $values = null,
        public ?string $references = null,
        public ?bool $unique = null,
        public ?bool $autoIncrement = null,
        public ?bool $unsigned = null
    ) {
        $this->validateColumn();
    }

    private function validateColumn(): void
    {

        if ($this->type == null || !($this->type instanceof ColumnType)) {
            throw new \InvalidArgumentException("Invalid type provided");
        }

        $type_name = $this->type->name;
        switch ($this->type) {
            case ColumnType::VARCHAR:
                if ($this->length === null) {
                    throw new \InvalidArgumentException("$type_name must have a length.");
                }
                break;
        }
    }

    public static function fromProperty(ReflectionProperty $property): ?self
    {
        $attributes = $property->getAttributes(self::class);
        if (empty($attributes)) {
            // FIXME: This is a temporary conversion till DatabaseColumn is remove.
            $dbColumn = \Lucent\Database\Attributes\DatabaseColumn::fromProperty($property);
            if ($dbColumn !== null) {
                $instance = $dbColumn->toModelColumn();
                $instance->classPropertyName = $property->getName();

                if ($instance->name == null) {
                    $instance->name = $property->getName();
                }

                return $instance;
            }

            return null;
        }

        $instance = $attributes[0]->newInstance();
        $instance->classPropertyName = $property->getName();

        if ($instance->name == null) {
            $instance->name = $property->getName();
        }

        return $instance;
    }
}