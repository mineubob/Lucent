<?php
namespace App\Models;

use Lucent\Model\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;
use Lucent\Facades\UUID;

enum AllTypesEnum
{
    case Foo;
    case Bar;
}

class AllTypes extends Model
{
    #[Column(ColumnType::BINARY, length: 255)]
    public string $binary;

    #[Column(ColumnType::BINARY, length: 255, nullable: true)]
    public ?string $binary_nullable;

    #[Column(ColumnType::TINYINT)]
    public int $tinyint;

    #[Column(ColumnType::TINYINT, nullable: true)]
    public ?int $tinyint_nullable;

    #[Column(ColumnType::DECIMAL)]
    public float $decimal;

    #[Column(ColumnType::DECIMAL, nullable: true)]
    public ?float $decimal_nullable;

    #[Column(ColumnType::INT)]
    public int $int;

    #[Column(ColumnType::INT, nullable: true)]
    public ?int $int_nullable;

    #[Column(ColumnType::INT, name: "int_special", primaryKey: true, autoIncrement: true)]
    public int $int_special;

    #[Column(ColumnType::JSON)]
    public array $json;

    #[Column(ColumnType::JSON, nullable: true)]
    public ?array $json_nullable;

    #[Column(ColumnType::TIMESTAMP)]
    public string $timestamp;

    #[Column(ColumnType::TIMESTAMP, nullable: true)]
    public ?string $timestamp_nullable;

    #[Column(ColumnType::ENUM , values: AllTypesEnum::class)]
    public string $enum;

    #[Column(ColumnType::ENUM , values: ["foo", "bar"], nullable: true)]
    public ?string $enum_nullable;

    #[Column(ColumnType::DATE)]
    public string $date;

    #[Column(ColumnType::DATE, nullable: true)]
    public ?string $date_nullable;

    #[Column(ColumnType::TEXT)]
    public string $text;

    #[Column(ColumnType::TEXT, nullable: true)]
    public ?string $text_nullable;

    #[Column(ColumnType::VARCHAR, length: 255)]
    public string $varchar;

    #[Column(ColumnType::VARCHAR, length: 255, nullable: true)]
    public ?string $varchar_nullable;

    #[Column(ColumnType::FLOAT)]
    public float $float;

    #[Column(ColumnType::FLOAT, nullable: true)]
    public ?float $float_nullable;

    #[Column(ColumnType::DOUBLE)]
    public float $double;

    #[Column(ColumnType::DOUBLE, nullable: true)]
    public ?float $double_nullable;

    #[Column(ColumnType::BOOLEAN)]
    public bool $boolean;

    #[Column(ColumnType::BOOLEAN, nullable: true)]
    public ?bool $boolean_nullable;

    #[Column(ColumnType::CHAR, length: 255)]
    public string $char;

    #[Column(ColumnType::CHAR, length: 255, nullable: true)]
    public ?string $char_nullable;

    #[Column(ColumnType::LONGTEXT)]
    public string $longtext;

    #[Column(ColumnType::LONGTEXT, nullable: true)]
    public ?string $longtext_nullable;

    #[Column(ColumnType::MEDIUMTEXT)]
    public string $mediumtext;

    #[Column(ColumnType::MEDIUMTEXT, nullable: true)]
    public ?string $mediumtext_nullable;

    #[Column(ColumnType::BIGINT)]
    public int $bigint;

    #[Column(ColumnType::BIGINT, nullable: true)]
    public ?int $bigint_nullable;

    #[Column(ColumnType::UUID)]
    public string $uuid;

    #[Column(ColumnType::UUID, nullable: true)]
    public ?string $uuid_nullable;

    /**
     * Check that all column types are set.
     * @throws \RuntimeException
     * @return void
     */
    public static function missingTypeCheck(): void
    {
        /**
         * @var array<Column>
         */
        $providedColumnTypes = [];

        $refClass = new \ReflectionClass(self::class);
        foreach ($refClass->getProperties() as $property) {
            if (!($property instanceof \ReflectionProperty))
                throw new \RuntimeException("Can't get property");

            if ($property->getDeclaringClass()->getName() !== self::class)
                continue;

            $dbColumn = Column::fromProperty($property);
            if ($dbColumn === null)
                throw new \RuntimeException("Can't create column from property {$property->getName()}");

            $type = $dbColumn->type->name;
            if (in_array($type, $providedColumnTypes, true))
                continue;

            $providedColumnTypes[] = $type;
        }

        $allTypes = array_map(fn($type) => $type->name, ColumnType::cases());
        $missingColumnTypes = array_diff($allTypes, $providedColumnTypes);
        if (count($missingColumnTypes) > 0) {
            throw new \RuntimeException("Missing column types: " . implode(", ", $missingColumnTypes));
        }
    }
    public function __construct()
    {
        $ref = new \ReflectionClass($this);

        foreach ($ref->getProperties() as $property) {
            if (!($property instanceof \ReflectionProperty))
                throw new \RuntimeException("Can't get property");

            if ($property->getDeclaringClass()->getName() !== self::class)
                continue;

            $column = Column::fromProperty($property);
            if ($column === null)
                throw new \RuntimeException("Can't create column from property {$property->getName()}");

            $isNullable = $column->nullable && $property->getType()->allowsNull();

            $value = self::generateValueForColumn($column, $isNullable);

            $this->{$property->getName()} = $value;
        }
    }

    private static function generateValueForColumn(Column $column, bool $nullable): mixed
    {
        if ($nullable && mt_rand(0, 1) === 0) {
            return null;
        }

        return match ($column->type) {
            ColumnType::BINARY => match (true) {
                    $column->length !== null => random_bytes($column->length),
                    default => throw new \RuntimeException('Invalid CHAR/VARCHAR definition')
                },
            ColumnType::TINYINT => random_int(0, 127),
            ColumnType::INT => random_int(0, 10000),
            ColumnType::BIGINT => random_int(0, 1000000),
            ColumnType::DECIMAL,
            ColumnType::FLOAT,
            ColumnType::DOUBLE => round(mt_rand() / mt_getrandmax() * 1000, 2),
            ColumnType::BOOLEAN => (bool) random_int(0, 1),
            ColumnType::CHAR,
            ColumnType::VARCHAR => match (true) {
                    $column->length !== null => self::generateRandomString($column->length),
                    default => throw new \RuntimeException('Invalid CHAR/VARCHAR definition')
                },
            ColumnType::TEXT => self::generateRandomString(mt_rand(20, $column->length ?? 255)),
            ColumnType::MEDIUMTEXT => self::generateRandomString(mt_rand(100, $column->length ?? 1024)),
            ColumnType::LONGTEXT => self::generateRandomString(mt_rand(500, $column->length ?? 4096)),
            ColumnType::DATE => date('Y-m-d', strtotime('-' . random_int(0, 365) . ' days')),
            ColumnType::TIMESTAMP => date('Y-m-d H:i:s', time() - random_int(0, 365 * 24 * 60 * 60)),
            ColumnType::JSON => (random_int(0, 1) === 0) ? self::generateRandomJsonObject(0, 2) : self::generateRandomJsonArray(0, 2),
            ColumnType::ENUM => match (true) {
                    is_array($column->values) => $column->values[array_rand($column->values)],
                    default => throw new \RuntimeException('Invalid ENUM definition'),
                },
            ColumnType::UUID => UUID::generate(),
            default => throw new \RuntimeException("Unsupported column type: {$column->type->name}"),
        };
    }

    /**
     * Generate a random string of a defined length.
     * @param int $length Length of the string.
     * @return string
     */
    private static function generateRandomString(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $maxIndex = strlen($characters) - 1;
        $bytes = random_bytes($length);

        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $characters[ord($bytes[$i]) % ($maxIndex + 1)];
        }

        return $str;
    }

    /**
     * Generate a random json object.
     * @param int $depth
     * @param int $maxDepth
     * @return array
     */
    private static function generateRandomJsonObject(int $depth, int $maxDepth): mixed
    {
        $numKeys = random_int(1, 10);
        $obj = [];

        for ($i = 0; $i < $numKeys; $i++) {
            $key = self::generateRandomString(random_int(5, 10));
            $obj[$key] = self::generateRandomJson($depth + 1, $maxDepth);
        }

        return $obj;
    }

    private static function generateRandomJsonArray(int $depth, int $maxDepth): mixed
    {
        $numItems = random_int(1, 10);
        $arr = [];

        for ($i = 0; $i < $numItems; $i++) {
            $arr[] = self::generateRandomJson($depth + 1, $maxDepth);
        }

        return $arr;
    }

    /**
     * Generate JSON primative or object/array (primitives independent of depth)
     * @param int $depth
     * @param int $maxDepth
     * @return mixed
     */
    private static function generateRandomJson(int $depth, int $maxDepth): mixed
    {
        $maxValueType = ($depth >= $maxDepth) ? 4 : 6; // Only primitives at maxDepth
        $valueType = random_int(1, $maxValueType);

        return match ($valueType) {
            1 => self::generateRandomString(random_int(3, 12)),
            2 => random_int(0, 1000),
            3 => round(mt_rand() / mt_getrandmax() * 1000, 2),
            4 => (bool) random_int(0, 1),
            5 => self::generateRandomJsonObject($depth, $maxDepth),
            6 => self::generateRandomJsonArray($depth, $maxDepth),
            default => throw new \RuntimeException("Unknown value type!"),
        };
    }
}