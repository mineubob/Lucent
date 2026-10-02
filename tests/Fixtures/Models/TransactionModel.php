<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

class TransactionModel extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 255, nullable: true)]
    protected ?string $description;

    #[Column(ColumnType::Float)]
    public protected(set) float $amount;

    #[Column(ColumnType::Int)]
    protected int $type;

    #[Column(ColumnType::Int)]
    public protected(set) int $date;

    public function __construct(float $amount, int $type, ?string $description = null, ?int $date = null)
    {
        $this->amount = $amount;
        $this->description = $description;
        $this->type = $type;
        $this->date = $date ?? time();
    }
}
