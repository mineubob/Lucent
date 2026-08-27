<?php

namespace Tests\Support\Stubs;

/**
 * A pure (non-backed) enum used to test the Enum validation constraint.
 */
enum TestPureEnum
{
    case Foo;
    case Bar;
    case Baz;
}