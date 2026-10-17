<?php
namespace App\Models;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The deferred-leg scenario model: declares a NOT NULL column without a
 * default alongside a column referencing an FK'd table shape. Adding a
 * NOT NULL-no-default column to a populated table routes through the
 * SQLite table rebuild (which toggles PRAGMA foreign_keys outside any
 * transaction), deferring the whole plan past the lock — the shape the
 * data-step deferred-leg test exercises.
 */
#[Table(name: 'rebuild_targets')]
class RebuildTarget extends Model
{
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::String, length: 100)]
    public private(set) string $label;

    // NOT NULL without default — the add routes through the SQLite
    // rebuild when the table has rows.
    #[Column(ColumnType::String, length: 100, default: 'pending')]
    public private(set) string $status;

    public function __construct(string $label, string $status = 'pending')
    {
        $this->label = $label;
        $this->status = $status;
    }
}
