<?php
namespace App\Rules;

use Lucent\Validation\Rule;

/**
 * Rule that exercises request context editing during validation.
 *
 * - 'name' stores its value into the request context via setContext().
 * - 'confirm' reads that stored value back via getContext() and checks
 *   it matches, proving a rule can both edit the request and inspect
 *   context written by an earlier rule in the same pass.
 */
class ContextRule extends Rule
{
    public function setup(): array
    {
        return [
            'name' => [
                'store_name',
            ],
            'confirm' => [
                'matches_stored_name',
            ],
        ];
    }

    protected function store_name(mixed $value): bool
    {
        $this->setContext('name', $value);
        return true;
    }

    protected function matches_stored_name(mixed $value): bool
    {
        return $value === $this->getContext('name');
    }
}