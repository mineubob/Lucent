<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The data-step scenario model: a medias table migrating from legacy
 * nullable FK columns (post_id, event_id, profile_id) to a synthetic
 * morph pair (attachable_type, attachable_id).
 *
 * The model declares ONLY the morph pair — a live table carrying the
 * legacy FKs diffs as add(morph pair) + drop(legacy FKs), the exact
 * shape `sync --step` interleaves a backfill into.
 *
 * The morph columns are nullable: a NOT NULL add without a default on a
 * populated table would route through the SQLite rebuild and change the
 * test's shape (the plain in-place adds are the point here — the
 * deferred-leg rebuild case is covered by a dedicated test using a
 * separate model).
 */
#[Table(name: 'medias')]
class Medias extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 100, nullable: true)]
    public private(set) ?string $attachable_type;

    #[Column(ColumnType::BigInt, nullable: true)]
    public private(set) ?int $attachable_id;

    public function __construct(?string $attachable_type, ?int $attachable_id)
    {
        $this->attachable_type = $attachable_type;
        $this->attachable_id = $attachable_id;
    }
}
