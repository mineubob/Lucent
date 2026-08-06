<?php

namespace Tests\Feature;

use Lucent\Facades\Faker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

/**
 * Database-driven validation tests (the `unique` / `!unique` rules).
 *
 * Split out of RuleTest because they require a real database connection,
 * whereas the rest of RuleTest is pure validation logic.
 */
class RuleDatabaseTest extends TestCase
{
    use DatabaseTesting;

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_unique_passing($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $request = Faker::request();
        $request->setInput("email", "unique-test@email.com");
        $request->setInput("full_name", "John Doe");
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "email" => ["unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_unique_failing($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("unique-test@email.com", "password", "John Doe");

        $this->assertTrue($user->create());

        $request = Faker::request();
        $request->setInput("email", "unique-test@email.com");
        $request->setInput("full_name", "John Doe");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "email" => ["unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_not_unique_passing($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("not-unique-test@email.com", "password", "John Doe");

        $this->assertTrue($user->create());

        $request = Faker::request();
        $request->setInput("email", "not-unique-test@email.com");
        $request->setInput("full_name", "John Doe");
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "email" => ["!unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_not_unique_failing($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestUser.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $request = Faker::request();
        $request->setInput("email", "not-unique-test@email.com");
        $request->setInput("full_name", "John Doe");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "email" => ["!unique:TestUser"]
        ]));
    }
}