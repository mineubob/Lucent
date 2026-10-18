<?php

declare(strict_types=1);

namespace Lucent\Support;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/**
 * A hand-authored callable sync step — the PHP sibling of a `--step=.sql`
 * file. Where an SQL step carries its transform as reviewable statement
 * text (the dry run prints every statement), a callable step carries it as
 * runtime behavior: the plan holds only what this interface declares, and
 * the dry run shows nothing but the description — review happens at the
 * definition site, not the plan.
 *
 * Convention: one file, one step. Steps live in `database/steps/` named
 * `<date>-<description>Step.php` (e.g.
 * `20261018-medias-morph-backfillStep.php`), the class defined in the same
 * file, and the file ends with `return new MediasMorphBackfillStep();` —
 * the command `require`s the file once and uses the returned instance.
 *
 * The closure's connection discipline is identical to an SQL statement
 * step's: the run happens inside the plan's lock and transaction, and a
 * throw vetoes the plan with the same rollback semantics a failing
 * statement already has. Use throws as veto gates (precondition checks,
 * row-count sanity limits) instead of dialect forks (`RAISE` / `SIGNAL`).
 *
 * Choose the callable form when the transform's intelligence lives in PHP:
 * class-string constants (morph aliases), parameter-bound writes,
 * conditionals. Choose the SQL step for pure row transforms — the
 * visible-by-default form stays the default.
 *
 * @see \BlueprintAU\Radiant\Database\Schema\DataStep The plan vehicle the command adapts instances into.
 */
interface SyncStep
{
    /**
     * The human description of the step's effects — all a dry run can
     * show for a callable step, and the prompt line the destructive
     * confirm gate displays.
     */
    public function describe(): string;

    /**
     * Whether the step can lose or rewrite existing data. Destructive
     * steps route through the same confirm gate (`--force` bypasses it)
     * as destructive schema changes; declare false only for insert-only
     * transforms.
     */
    public function destructive(): bool;

    /**
     * The table the step primarily targets — a cosmetic display and
     * lock-scoping label, exactly like an SQL step's dominant-table
     * guess. Never used for dispatch.
     */
    public function table(): string;

    /**
     * Run the transform against the live connection.
     *
     * @param  SqlConnection  $conn  The plan's connection — use its
     *      statement/parameter-binding API rather than reaching for a
     *      second connection, so the run stays inside the plan's
     *      transaction and lock discipline.
     */
    public function run(SqlConnection $conn): void;
}
