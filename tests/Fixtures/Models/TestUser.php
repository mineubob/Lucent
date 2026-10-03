<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

class TestUser extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 255)]
    protected string $email;

    // Nullable so a sync that ADDS this column to an existing table stays an
    // in-place add that converges in a single apply. A NOT NULL add without a
    // default is supported too — Radiant backfills the existing rows — but
    // only via Blueprint::backfill(), which a model attribute cannot express,
    // and that path converges over two applies rather than one.
    #[Column(ColumnType::String, length: 255, nullable: true)]
    protected string $password_hash;

    #[Column(ColumnType::String, length: 100)]
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
