<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for the Lucent test suite.
 *
 * Extend this instead of PHPUnit's TestCase directly. Cross-cutting test
 * concerns (database setup, application refresh, command output capture)
 * are provided as opt-in traits under Tests\Support\Concerns, so pure-unit
 * tests stay free of infrastructure they don't need.
 */
abstract class TestCase extends BaseTestCase
{
}