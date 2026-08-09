<?php
namespace App\Models;

use Lucent\Model\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TestCustomer extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public protected(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255)]
    public protected(set) string $mobile;

    public function __construct(string $mobile)
    {
        $this->mobile = $mobile;
    }
}