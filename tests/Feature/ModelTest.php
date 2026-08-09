<?php

namespace Tests\Feature;

use Lucent\Facades\CommandLine;
use Lucent\Facades\Faker;
use Lucent\Model\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DatabaseTesting;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

class ModelTest extends TestCase
{
    use DatabaseTesting;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        FixtureLoader::copyModel('Admin.php');
        FixtureLoader::copyModel('TestUser.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // CommandLine::execute() only returns the command output when capture
        // mode is on. This is a static flag that other tests may toggle, so
        // set it explicitly here to make these tests order-independent.
        CommandLine::captureOutput();
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $output = CommandLine::execute("migration make App/Models/TestUser");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_creation($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");

        self::assertTrue($user->create());

        $this->assertNotNull(\App\Models\TestUser::where("email", "john@doe.com")->getFirst());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_updating($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");

        self::assertTrue($user->create());

        $user = \App\Models\TestUser::where("full_name", "John Doe")->getFirst();

        $this->assertNotNull($user);

        $user->setFullName("Jack Harris");

        $result = false;
        try {
            $result = $user->save();
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }

        $this->assertTrue($result);

        $user = \App\Models\TestUser::where("full_name", "Jack Harris")->getFirst();

        $this->assertNotNull($user);
        $this->assertEquals("Jack Harris", $user->getFullName());

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_migration($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $output = CommandLine::execute("migration make App/Models/Admin");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_creation($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\Admin::class]);

        $adminUser = new \App\Models\Admin("john@doe.com", "password", "John Doe", false, true);

        $this->assertTrue($adminUser->create());

        $lookup = \App\Models\Admin::where("email", "john@doe.com")->where("can_lock_accounts", true)->getFirst();
        $this->assertEquals("John Doe", $lookup->getFullName());
        $this->assertTrue($lookup->can_lock_accounts);
        $this->assertFalse($lookup->can_reset_passwords);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_counts($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\Admin::class]);

        $adminUser = new \App\Models\Admin("gibbs@blackpearl.com", "password", "Joshamee Gibbs", false, false, "Just a crew member");

        $this->assertTrue($adminUser->create());

        $adminUser = new \App\Models\Admin("jack@blackpearl.com", "password", "Captain Jack", true, true, "He's the captain");

        $this->assertTrue($adminUser->create());

        $count = \App\Models\Admin::where("can_lock_accounts", true)->count();

        $this->assertEquals(1, $count);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_delete($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\Admin::class]);

        $adminUser = new \App\Models\Admin("gibbs@blackpearl.com", "password", "Joshamee Gibbs", false, false, "Just a crew member");

        $this->assertTrue($adminUser->create());

        $this->assertTrue($adminUser->delete());

        $lookUp = \App\Models\Admin::where("email", "gibbs@blackpearl.com")->getFirst();

        $this->assertNull($lookUp);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_update($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\Admin::class]);

        $adminUser = new \App\Models\Admin("gibbs@blackpearl.com", "password", "Joshamee Gibbs", false, false, "Just a crew member");

        $this->assertTrue($adminUser->create());

        $adminUser->setFullName("Jack Harris");
        $adminUser->setNotes("Not a pirate any more!");

        try {
            $adminUser->save();
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }

        $lookup = \App\Models\Admin::where("full_name", "Jack Harris")->getFirst();
        $this->assertNotNull($lookup);
        $this->assertEquals("Not a pirate any more!", $lookup->notes);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_getFirst($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\Admin::class]);

        $adminUser = new \App\Models\Admin("davey@jones.com", "password", "Davey Jones", false, false, "Captain of the flying dutchman");

        $this->assertTrue($adminUser->create());

        $lookup = \App\Models\Admin::where("full_name", "Davey Jones")->getFirst();
        $this->assertNotNull($lookup);
        $this->assertEquals("Davey Jones", $lookup->getFullName());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_pk_auto_increment($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("ai@test.com", "password", "AI Test");

        $this->assertTrue($user->create());

        $this->assertNotEquals(-1, $user->id);

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_count($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $count = 10;
        $i = 0;
        while ($i < $count) {
            $user = new \App\Models\TestUser("user-$i@test.com", "password", "user-$i");

            $this->assertTrue($user->create());
            $i++;
        }

        $this->assertEquals($count, \App\Models\TestUser::limit(100)->count());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_like_or($driver, $config): void
    {
        self::setupDatabase($driver, $config, [\App\Models\TestUser::class]);

        $user = new \App\Models\TestUser("john.smith@test.com", "password", "John Smith");

        $this->assertTrue($user->create());

        $user = new \App\Models\TestUser("james.smith@gmail.com", "password", "James Smith");

        $this->assertTrue($user->create());

        $user = new \App\Models\TestUser("bill@gmail.com", "password", "Bill Clinton");

        $this->assertTrue($user->create());

        $gmailUsers = \App\Models\TestUser::limit(100)->like("email", "gmail.com")->get();

        $this->assertCount(2, $gmailUsers);

        $gmailAndJohn = \App\Models\TestUser::limit(100)->like("email", "gmail.com")->orLike("full_name", "John")->get();

        $this->assertCount(3, $gmailAndJohn);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration_long_text($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->assertTrue(FixtureLoader::copyModel('LongTextModel.php')->exists());

        $output = CommandLine::execute("migration make App/Models/LongTextModel");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration_with_trait($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);

        $this->assertTrue(FixtureLoader::copyModel('SoftDelete.php')->exists());
        $this->assertTrue(FixtureLoader::copyModel('TestUserTwo.php')->exists());

        $output = CommandLine::execute("migration make App/Models/TestUserTwo");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_trait_use($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('SoftDelete.php')->exists());
        $this->assertTrue(FixtureLoader::copyModel('TestUserTwo.php')->exists());

        self::setupDatabase($driver, $config, [\App\Models\TestUserTwo::class]);

        $user = new \App\Models\TestUserTwo("john.smith@test.com", "password", "John Smith");

        $this->assertTrue($user->create());

        $this->assertTrue($user->delete());

        $user = \App\Models\TestUserTwo::where("email", "john.smith@test.com")->getFirst();
        $this->assertNotNull($user->deleted_at);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_trait_model_collection_hook($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('SoftDelete.php')->exists());
        $this->assertTrue(FixtureLoader::copyModel('TestUserTwo.php')->exists());

        self::setupDatabase($driver, $config, [\App\Models\TestUserTwo::class]);

        // Create and save a model
        $user = new \App\Models\TestUserTwo("john.smith@test.com", "password", "John Smith");
        $this->assertTrue($user->create());

        // Soft delete the model
        $this->assertTrue($user->delete());

        // Register the trait condition
        Collection::registerTraitCondition(
            \App\Models\SoftDelete::class,
            'deleted_at',
            null
        );

        // This should return zero records as they're all soft-deleted
        $users = \App\Models\TestUserTwo::where("email", "john.smith@test.com")->get();
        $this->assertCount(0, $users);

        // Test the withTrashed method if implemented
        // $users = TestUserTwo::where("email", "john.smith@test.com")->withTrashed()->get();
        // $this->assertCount(1, $users);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_sum_of_column($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $transaction = new \App\Models\TransactionModel(25.5, 0);

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(-50, 1);

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(120, 0);

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(4.5, 0);

        $this->assertTrue($transaction->create());

        //0 = credit, 1 = debit
        $sum = \App\Models\TransactionModel::where("type", 0)->sum("amount");

        $this->assertEquals(150, $sum);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_sum_of_column_with_subtraction($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $transaction = new \App\Models\TransactionModel(25.5, 0);

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(-50, 0);

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(120, 0);

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(4.5, 0);

        $this->assertTrue($transaction->create());

        //0 = credit, 1 = debit
        $sum = \App\Models\TransactionModel::where("type", 0)->sum("amount");

        $this->assertEquals(100, $sum);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_sorting_asc($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $i = 0;
        while ($i < 10) {
            $transaction = new \App\Models\TransactionModel(rand(10, 200), rand(0, 1));

            $this->assertTrue($transaction->create());

            $i++;
        }

        $last = -1;
        foreach (\App\Models\TransactionModel::where("type", 0)->orderBy('amount')->get() as $transaction) {
            $this->assertTrue($last <= $transaction->amount);
            $last = $transaction->amount;
        }

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_sorting_dsc($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $i = 0;
        while ($i < 10) {
            $transaction = new \App\Models\TransactionModel(rand(10, 200), rand(0, 1));

            $this->assertTrue($transaction->create());

            $i++;
        }

        $last = 200;
        foreach (\App\Models\TransactionModel::where("type", 0)->orderBy('amount', "DESC")->get() as $transaction) {
            $this->assertTrue($last >= $transaction->amount);
            $last = $transaction->amount;
        }

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_collection_in($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $i = 0;
        while ($i < 10) {
            $transaction = new \App\Models\TransactionModel(rand(10, 200), rand(0, 1), Faker::randomString(rand(0, 50)));

            $this->assertTrue($transaction->create());

            $i++;
        }

        //Ids we are counting.
        $ids = [1, 2, 3];

        $manualAmount = 0;
        //Manually check the sum with n+1 query.
        foreach ($ids as $id) {
            $manualAmount += \App\Models\TransactionModel::where("id", $id)->sum("amount");
        }

        $inAmount = \App\Models\TransactionModel::in("id", $ids)->orderBy('amount', "DESC")->sum("amount");

        $this->assertEquals($manualAmount, $inAmount);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_collection_in_with_where($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $i = 0;
        while ($i < 10) {
            $transaction = new \App\Models\TransactionModel(rand(10, 200), rand(0, 1), Faker::randomString(rand(0, 20)));

            $this->assertTrue($transaction->create());

            $i++;
        }

        //Ids we are counting.
        $ids = [1, 2, 3];

        $manualAmount = 0;
        //Manually check the sum with n+1 query.
        foreach ($ids as $id) {
            $manualAmount += \App\Models\TransactionModel::where("id", $id)->where("type", 0)->sum("amount");
        }

        $inAmount = \App\Models\TransactionModel::in("id", $ids)->orderBy('amount', "DESC")->where("type", 0)->sum("amount");

        $this->assertEquals($manualAmount, $inAmount);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_collection_where_greater_then($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TransactionModel.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TransactionModel::class]);

        $i = 0;
        while ($i < 10) {
            $transaction = new \App\Models\TransactionModel(rand(10, 200), rand(0, 1), Faker::randomString(rand(0, 50)), time() - 86400 * $i);

            $this->assertTrue($transaction->create());
            $i++;
        }

        $transactions = \App\Models\TransactionModel::compare("date", "<=", time() - (86400 * 5))->get();

        $this->assertCount(5, $transactions);

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_numeric_string_bug($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('TestCustomer.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\TestCustomer::class]);

        $mobile = "0423235427";

        $customer = new \App\Models\TestCustomer($mobile);

        $this->assertTrue($customer->create());

        $lookup = \App\Models\TestCustomer::where("mobile", $mobile)->getFirst();
        $this->assertNotNull($lookup);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_all_column_types_migration($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('AllTypes.php')->exists());
        self::setupDatabase($driver, $config, []);

        \App\Models\AllTypes::missingTypeCheck();

        $output = CommandLine::execute("migration make App/Models/AllTypes");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_all_column_types_create($driver, $config): void
    {
        $this->assertTrue(FixtureLoader::copyModel('AllTypes.php')->exists());
        self::setupDatabase($driver, $config, [\App\Models\AllTypes::class]);

        \App\Models\AllTypes::missingTypeCheck();

        $instance = new \App\Models\AllTypes();

        $this->assertTrue($instance->create());

        $found = \App\Models\AllTypes::where('int_special', $instance->int_special)->getFirst();

        self::assertEquals($instance, $found);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_pk_creation_bug($driver, $config): void
    {
        self::setupDatabase($driver, $config, []);
        $this->assertTrue(FixtureLoader::copyModel('TestUserPkBug.php')->exists());

        $output = CommandLine::execute("migration make App/Models/TestUserPkBug");
        $this->assertEquals("Successfully performed database migration", $output);

        $test_user = new \App\Models\TestUserPkBug("test@bug.com","Pa55w0rd","Test Account");

        $id = $test_user->id;

        $this->assertNotEquals(0, $test_user->id);

        $this->assertTrue($test_user->create());

        $this->assertNotEquals(0, $test_user->id);

        $this->assertTrue(strlen($test_user->id) == 36);

        $new_id = $test_user->id;

        $this->assertEquals($id,$new_id);
    }

    /**
     * Assert that two structures are equal, ignoring specific paths.
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string[] $ignorePaths JSONPath-like paths to ignore
     */
    public static function assertEqualsIgnoringPaths($expected, $actual, array $ignorePaths): void
    {
        // Prebuild regexes from ignore paths
        $ignoreRegexes = array_map(fn($p) => self::buildPathRegex($p), $ignorePaths);

        $matchesIgnore = function (string $path) use ($ignoreRegexes): bool {
            foreach ($ignoreRegexes as $regex) {
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
            return false;
        };

        // Recursively normalize structure, skipping ignored paths
        $normalize = function ($value, string $path = '') use (&$normalize, $matchesIgnore) {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $k => $v) {
                    $keyStr = is_int($k) ? "[$k]" : $k;
                    $subPath = $path === '' ? $keyStr : "$path.$keyStr";
                    if ($matchesIgnore($subPath))
                        continue;
                    $result[$k] = $normalize($v, $subPath);
                }
                return $result;
            }

            if (is_object($value)) {
                $result = new \stdClass();
                $ref = new \ReflectionClass($value);

                do {
                    foreach ($ref->getProperties() as $prop) {
                        /** @var \ReflectionProperty $prop */
                        $prop->setAccessible(true);
                        $name = $prop->getName();
                        $subPath = $path === '' ? $name : "$path.$name";

                        if ($matchesIgnore($subPath))
                            continue;

                        try {
                            $propValue = $prop->getValue($value);
                        } catch (\Throwable) {
                            continue;
                        }

                        $result->$name = $normalize($propValue, $subPath);
                    }
                } while ($ref = $ref->getParentClass());

                return $result;
            }

            return $value;
        };

        \PHPUnit\Framework\Assert::assertEquals(
            $normalize($expected),
            $normalize($actual)
        );
    }

    /**
     * Build a regex from a JSONPath-like wildcard path.
     *
     * Supports:
     * - *   → exactly one segment
     * - **  → zero or more segments
     * - [*] → any array index
     * - [n] → specific array index
     */
    private static function buildPathRegex(string $pattern): string
    {
        $pattern = ltrim($pattern, '$.');
        $segments = explode('.', $pattern);
        $regex = '';

        foreach ($segments as $i => $seg) {
            if ($seg === '**') {
                // zero or more segments including dots
                $regex .= '(?:[^.\[\]]+(?:\.[^.\[\]]+)*)?';
            } elseif ($seg === '*') {
                $regex .= '[^.\[\]]+'; // single segment
            } elseif ($seg === '[*]') {
                $regex .= '\[[0-9]+\]'; // any array index
            } else {
                $regex .= preg_quote($seg, '/');
            }

            // Append a literal dot if next segment exists and current segment is not '**'
            if ($i < count($segments) - 1 && $seg !== '**') {
                $regex .= '\.';
            }
        }

        return '/^' . $regex . '$/';
    }
}