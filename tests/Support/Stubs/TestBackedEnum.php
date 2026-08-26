<?php

namespace Tests\Support\Stubs;

/**
 * A backed (string) enum used to test the Enum validation constraint.
 */
enum TestBackedEnum: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
}