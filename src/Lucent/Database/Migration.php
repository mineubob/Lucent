<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 7/11/2023
 */

namespace Lucent\Database;

use Lucent\Database;
use Lucent\Facades\Log;
use Lucent\Model\Model;
use ReflectionClass;


class Migration
{

    public function make($class): bool
    {
        return Database::disabling(LUCENT_DB_FOREIGN_KEY_CHECKS, function () use ($class) {
            $reflection = new ReflectionClass($class);

            // Get the new column structure
            $columns = $this->analyzeNewStructure($reflection);

            $table = Schema::table($reflection->getShortName(), function ($table) use ($columns) {
                //Foreach of our column attributes, transform them into table columns
                foreach ($columns as $column) {
                    $type = $column->type->value;

                    /**
                     * @var \Lucent\Database\Schema\Column|\Lucent\Database\Schema\NumericColumn
                     */
                    $tableColumn = $table->$type($column->name);

                    if ($column->nullable == true) {
                        $tableColumn->nullable();
                    }

                    if ($column->length !== null) {
                        $tableColumn->length($column->length);
                    }

                    if ($column->primaryKey == true) {
                        $tableColumn->primaryKey();
                    }

                    if ($column->default !== null) {
                        $tableColumn->default($column->default);
                    }

                    if ($column->values !== null && count($column->values) > 0) {
                        $tableColumn->values($column->values);
                    }

                    if ($column->references !== null) {
                        $tableColumn->references($column->references);
                    }

                    if ($column->unique == true) {
                        $tableColumn->unique();
                    }

                    if ($tableColumn instanceof \Lucent\Database\Schema\NumericColumn) {
                        if ($column->autoIncrement) {
                            $tableColumn->autoIncrement();
                        }

                        if ($column->unsigned) {
                            $tableColumn->unsigned();
                        }
                    }
                }
            });

            // Backup existing data if table exists
            $data = [];

            if ($table->exists()) {
                $data = Database::select("SELECT * FROM " . $table->name);

                // Drop the existing table
                if (!$table->drop()) {
                    Log::channel("lucent.db")->error("[Migration] Failed to drop table {$table->name}");
                    return false;
                }
            }


            if (!$table->create(false)) {
                Log::channel("lucent.db")->error("[Migration] Failed to create table {$table->name}");
                return false;
            }

            // Restore data if we have any
            if (count($data) > 0) {
                foreach ($data as $row) {
                    $columns = array_keys($row);
                    $placeholders = array_fill(0, count($columns), '?');

                    $query = sprintf(
                        "INSERT INTO %s (`%s`) VALUES (%s)",
                        $table->name,
                        implode('`, `', $columns),
                        implode(', ', $placeholders)
                    );

                    Database::insert($query, array_values($row));
                }
            }

            return true;
        });

    }


    /**
     * @param \ReflectionClass $reflection
     * @return array<\Lucent\Model\Column>
     */
    private function analyzeNewStructure(ReflectionClass $reflection): array
    {
        //Check if we are extending anything.
        $parent = $reflection->getParentClass();
        $columns = Model::getDatabaseProperties($reflection);

        if ($parent->getName() !== Model::class) {
            $pk = Model::getDatabasePrimaryKey($parent);
            if (array_key_exists($pk->name, $columns)) {
                Log::channel("lucent.db")->error("[Migration] Parent primary key already exists in {$reflection->getName()}");
                throw new \RuntimeException("Parent primary key already exists in {$reflection->getName()}");
            }

            $pk->autoIncrement = false;
            $pk->references = $parent->getShortName() . "(" . $pk->name . ")";

            // Add primary key to front of columns.
            $columns = array_merge([
                $pk->name => $pk
            ], $columns);

            if (!Schema::table($parent->getShortName())->exists()) {
                if (!$this->make($parent->getName())) {
                    Log::channel("lucent.db")->error("[Migration] Could not create parent table {$parent->getName()}");
                }
            }
        }

        return $columns;
    }
}