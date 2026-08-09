<?php
namespace App\Models;

use Lucent\Model\Model;
use App\Models\SoftDelete;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TestUserTwo extends Model
{
    use SoftDelete;

    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255)]
    protected string $email;

    #[Column(ColumnType::VARCHAR, length: 255)]
    protected string $password_hash;

    #[Column(ColumnType::VARCHAR, length: 100)]
    protected string $full_name;

    public function __construct(string $email, string $password_hash, string $full_name)
    {
        $this->email = $email;
        $this->password_hash = $password_hash;
        $this->full_name = $full_name;
    }

    public function getFullName(): string
    {
        return $this->full_name;
    }

    public function setFullName(string $full_name)
    {
        $this->full_name = $full_name;
    }

    public function getId(): int
    {
        return $this->id;
    }
}