<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use Lucent\Facades\UUID;

class TestUserPkBug extends Model
{
    #[Column(ColumnType::Uuid, primaryKey: true, autoIncrement: false)]
    public private(set) string $id;

    #[Column(ColumnType::String, length: 255)]
    protected string $email;

    #[Column(ColumnType::String, length: 255)]
    protected string $password_hash;

    #[Column(ColumnType::String, length: 100)]
    protected string $full_name;

    public function __construct(string $email, string $password_hash, string $full_name)
    {
        $this->id = UUID::generate();
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
}
