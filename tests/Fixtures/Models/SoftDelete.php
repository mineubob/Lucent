<?php
namespace App\Models;

use Lucent\Model\Column;
use Lucent\Model\ColumnType;

trait SoftDelete
{
    #[Column(ColumnType::INT, nullable: true)]
    public private(set) ?int $deleted_at = null;

    /**
     * Override the base delete method with a soft delete implementation
     *
     * @param mixed $propertyName The primary key property name
     * @return bool Success
     */
    public function delete(?string $propertyName = null): bool
    {
        return $this->softDelete($propertyName);
    }

    /**
     * Delete the model by setting the deleted_at timestamp
     *
     * @param string $propertyName The primary key property name
     * @return bool Success
     */
    public function softDelete(?string $propertyName = null): bool
    {
        $this->deleted_at = time();
        // Passing null lets the base Model::save() resolve the actual primary
        // key, so models with a non-"id" PK work correctly.
        return $this->save($propertyName);
    }

    /**
     * Restore a soft deleted model
     *
     * @return bool Success
     */
    public function restore(): bool
    {
        $this->deleted_at = null;
        return $this->save();
    }
}