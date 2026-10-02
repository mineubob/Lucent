<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

class LongTextModel extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::Text)]
    protected string $email;

    #[Column(ColumnType::Text)]
    protected string $text;

    #[Column(ColumnType::Text)]
    protected string $mText;

    #[Column(ColumnType::String, length: 100)]
    protected string $full_name;

    public function __construct(string $email, string $text, string $mText, string $full_name)
    {
        $this->email = $email;
        $this->text = $text;
        $this->mText = $mText;
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
