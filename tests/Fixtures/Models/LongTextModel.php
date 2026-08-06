<?php
namespace App\Models;

use Lucent\Model\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class LongTextModel extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::LONGTEXT)]
    protected string $email;

    #[Column(ColumnType::TEXT)]
    protected string $text;

    #[Column(ColumnType::MEDIUMTEXT)]
    protected string $mText;

    #[Column(ColumnType::VARCHAR, length: 100)]
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