<?php

// Test fixture guard — NOT part of the SyncStep contract. The PHPUnit
// process runs many tests that re-require this file; a bare class
// declaration executing twice is an uncatchable "Cannot redeclare class"
// fatal. Real step files never need this wrapper: the sync command
// requires each file at most once per run.
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use Lucent\Support\SyncStep;

if (!class_exists(FixtureMediasMorphBackfillStep::class, false)) {
    final class FixtureMediasMorphBackfillStep implements SyncStep
    {
        public function describe(): string
        {
            return 'Backfill the morph pair from the legacy FK columns (post/event/profile)';
        }

        public function destructive(): bool
        {
            return false; // rewrites rows, loses none
        }

        public function table(): string
        {
            return 'medias';
        }

        public function run(SqlConnection $conn): void
        {
            // Veto gate: a plain throw aborts the plan with the same
            // rollback semantics a failing SQL statement has.
            $orphans = $conn->selectColumnSql(
                'SELECT COUNT(*) FROM medias WHERE post_id IS NULL AND event_id IS NULL AND profile_id IS NULL',
                []
            );

            if ((int) $orphans[0] > 1) {
                throw new DomainException('More than one orphan media row — refusing to guess');
            }

            $conn->statement(
                "UPDATE medias SET attachable_type = ?, attachable_id = post_id WHERE post_id IS NOT NULL",
                ['post']
            );
            $conn->statement(
                "UPDATE medias SET attachable_type = ?, attachable_id = event_id WHERE event_id IS NOT NULL",
                ['event']
            );
            $conn->statement(
                "UPDATE medias SET attachable_type = ?, attachable_id = profile_id WHERE profile_id IS NOT NULL",
                ['profile']
            );
        }
    }
}

return new FixtureMediasMorphBackfillStep();
