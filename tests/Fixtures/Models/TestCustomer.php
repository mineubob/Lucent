<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

class TestCustomer extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public protected(set) ?int $id;

    #[Column(ColumnType::String, length: 255)]
    public protected(set) string $mobile;

    public function __construct(string $mobile)
    {
        $this->mobile = $mobile;
    }
}
