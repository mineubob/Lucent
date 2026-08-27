<?php

namespace Tests\Support\Concerns;

use Tests\Support\FixtureLoader;

/**
 * Copies fixture files into the disposable test working directory.
 *
 * Many test classes copy one or more fixtures (controllers, models, rules,
 * middleware, routes, ...) in their setUpBeforeClass(). This trait provides
 * a single copyFixtures() helper that maps a fixture type to the matching
 * FixtureLoader::copy*() method, so callers declare what they need instead
 * of repeating a chain of FixtureLoader calls.
 *
 * Opt-in trait: only test classes that copy fixtures should `use` it.
 */
trait CopiesFixtures
{
    /**
     * Map of fixture type => FixtureLoader::copy*() method.
     *
     * 'Cli' is special: it copies the CLI entrypoint template, which takes
     * no filename argument.
     */
    private const FIXTURE_METHODS = [
        'Model'      => 'copyModel',
        'Controller' => 'copyController',
        'Command'    => 'copyCommand',
        'Middleware' => 'copyMiddleware',
        'Service'    => 'copyService',
        'Route'      => 'copyRoutes',
        'View'       => 'copyView',
        'Cli'        => 'copyCliTemplate',
    ];

    /**
     * Copy fixture files into the temp install root.
     *
     * @param array<string, string|array<int, string>|null> $fixtures
     *   Map of fixture type (Controller, Middleware, Route, Rule, Model,
     *   Service, View, Command) to one or more filenames. The special 'Cli'
     *   key copies the CLI entrypoint template and ignores its value.
     */
    protected static function copyFixtures(array $fixtures): void
    {
        foreach ($fixtures as $type => $names) {
            $method = self::FIXTURE_METHODS[$type]
                ?? throw new \InvalidArgumentException("Unknown fixture type: {$type}");

            if ($type === 'Cli') {
                FixtureLoader::$method();
                continue;
            }

            foreach ((array) $names as $name) {
                FixtureLoader::$method($name);
            }
        }
    }
}