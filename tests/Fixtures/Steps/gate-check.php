<?php

// Test fixture guard — NOT part of the SyncStep contract. The PHPUnit
// process runs many tests that re-require this file; a bare class
// declaration executing twice is an uncatchable "Cannot redeclare class"
// fatal. Real step files never need this wrapper: the sync command
// requires each file at most once per run.
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use Lucent\Support\SyncStep;

if (!class_exists(FixtureDestructiveGateCheckStep::class, false)) {
    final class FixtureDestructiveGateCheckStep implements SyncStep
    {
        public function describe(): string
        {
            return 'Rewrite every morph row destructively';
        }

        public function destructive(): bool
        {
            return true;
        }

        public function table(): string
        {
            return 'medias';
        }

        public function run(SqlConnection $conn): void
        {
            $conn->statement("UPDATE medias SET attachable_type = 'post'");
        }
    }
}

return new FixtureDestructiveGateCheckStep();
