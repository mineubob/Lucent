<?php
/**
 * Copyright Jack Harris
 * Peninsula Interactive - policyManager-AuthApi
 * Last Updated - 7/11/2023
 */

namespace Lucent\Database;

use Lucent\Database;
use Lucent\Facades\Log;
use Lucent\Model;
use ReflectionClass;


class Migration
{

    public function make($class): bool
    {

        return Database::disabling(LUCENT_DB_FOREIGN_KEY_CHECKS, function () use ($class) {

            $reflection = new ReflectionClass($class);

            $table = Schema::table($reflection->getShortName());

            // Backup existing data if table exists
            $data = [];

            if ($table->exists()) {
                $data = Database::select("SELECT * FROM " . $table->name);

                // Drop the existing table
                if (!$table->drop()) {
                    Log::channel("db")->error("Failed to drop table {$table->name}");
                    return false;
                }
            }

            // Get the new column structure
            $columns = $this->analyzeNewStructure($reflection);

            // Create a new table using the appropriate driver
            $query = Database::createTable($table->name, $columns);

            Log::channel("db")->info($query);

            if (!Database::statement($query)) {
                Log::channel("db")->critical("Failed to create table {$table->name}");
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

    private function analyzeNewStructure(ReflectionClass $reflection): array
    {
        //Check if we are extending anything.
        $parent = $reflection->getParentClass();
        $columns = [];

        if ($parent->getName() !== Model::class) {
            $parentPK = Model::getDatabasePrimaryKey($parent);
            if ($parentPK === null) {
                Log::channel("db")->critical("Could not retrieve primary key from parent class {$parent->getName()}");
                exit(1);
            }

            $parentPK["AUTO_INCREMENT"] = false;
            $parentPK["REFERENCES"] = $parent->getShortName() . "(" . $parentPK["NAME"] . ")";

            $columns[] = $parentPK;

            if (!Schema::table($parent->getShortName())->exists()) {
                if (!$this->make($parent->getName())) {
                    Log::channel("db")->critical("Could not create parent table {$parent->getName()}");
                }
            }
        }

        return array_merge($columns, Model::getDatabaseProperties($reflection->getName()));
    }
}