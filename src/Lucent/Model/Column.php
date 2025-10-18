<?php

namespace Lucent\Model;

use Attribute;
use Lucent\Database\Schema\Reference;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public ?array $values;

    public ?Reference $references;

    public private(set) ?string $classPropertyName;

    /**
     * @param ColumnType                                                        $type           The database type.
     * @param string|null                                                       $name           The column name. If null, defaults to the property name.
     * @param bool|null                                                         $nullable      Whether NULL values are allowed. If null, unspecified.
     * @param int|null                                                          $length         Character length (for string types).
     * @param bool|null                                                         $autoIncrement  Whether the column auto-increments.
     * @param bool|null                                                         $primaryKey     Whether this column is a primary key.
     * @param mixed                                                             $default        The default value for the column.
     * @param class-string<\UnitEnum>|array<string>|null                        $values         Allowed enum values if type is LUCENT_DB_ENUM.
     * @param Reference|class-string<\Lucent\Model\Model>|string|null           $references     Foreign key reference target.
     * @param bool|null                                                         $unique         Whether the column should be unique.
     * @param bool|null                                                         $unsigned       Whether the column is unsigned (for numeric types).
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
        string|array|null $values = null,
        ?string $references = null,
        public ?bool $unique = null,
        public ?bool $autoIncrement = null,
        public ?bool $unsigned = null
    ) {
        $this->values = self::parseValues($values);
        $this->references = self::parseReferences($references);

        $this->validateColumn();
    }

    private static function parseValues(string|array|null $values)
    {
        if ($values === null) {
            return null;
        }

        if (is_array($values)) {
            return $values;
        }

        try {
            $enum = new \ReflectionEnum($values);
        } catch (\ReflectionException $_) {
            throw new \InvalidArgumentException("Class is not an enum: $values");
        }
        $values = [];

        foreach ($enum->getCases() as $case) {
            if ($case instanceof \ReflectionEnumUnitCase) {
                $values[] = $case->getName();
            } else if ($case instanceof \ReflectionEnumBackedCase) {
                $values[] = (string) $case->getBackingValue();
            }
        }

        return $values;
    }

    private static function parseReferences(?string $references): ?Reference
    {
        if ($references === null) {
            return null;
        }

        if ($references instanceof Reference) {
            return $references;
        }

        return Reference::fromString($references);
    }

    private function validateColumn(): void
    {
        if ($this->type == null || !($this->type instanceof ColumnType)) {
            throw new \InvalidArgumentException("Invalid type provided");
        }

        $type_name = $this->type->name;
        switch ($this->type) {
            case ColumnType::CHAR;
            case ColumnType::VARCHAR:
                $this->validateVarcharColumn($type_name);
                break;
            case ColumnType::ENUM:
                $this->validateEnumColumn($type_name);
                break;
        }
    }

    private function validateVarcharColumn(string $typeName): void
    {
        if ($this->length === null || $this->length < 1) {
            throw new \InvalidArgumentException("$typeName must have a length.");
        }
    }

    private function validateEnumColumn(string $typeName): void
    {
        if ($this->values === null || count($this->values) < 1) {
            throw new \InvalidArgumentException("$typeName must have at least one value.");
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