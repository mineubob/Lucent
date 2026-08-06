<?php
namespace App\Models;

use Lucent\Model\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TransactionModel extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255, nullable: true)]
    protected ?string $description;

    #[Column(ColumnType::DECIMAL)]
    public protected(set) float $amount;

    #[Column(ColumnType::INT)]
    protected int $type;

    #[Column(ColumnType::INT)]
    public protected(set) int $date;

    public function __construct(float $amount, int $type, ?string $description = null, ?int $date = null)
    {
        $this->amount = $amount;
        $this->description = $description;
        $this->type = $type;
        $this->date = $date ?? time();
    }
}