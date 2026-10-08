<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model with a slug column — used by the #[Bind] tests to exercise
 * non-PK binding.
 */
class SluggedModel extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 100)]
    public private(set) string $slug;

    #[Column(ColumnType::String, length: 100)]
    public private(set) string $name;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
    }
}
