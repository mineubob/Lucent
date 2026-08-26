<?php

namespace Tests\Support\Stubs;

/**
 * A backed (int) enum used to test the Enum validation constraint.
 */
enum TestIntEnum: int
{
    case One = 1;
    case Two = 2;
    case Three = 3;
}