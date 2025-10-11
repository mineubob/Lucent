<?php

namespace Unit;

use Lucent\Facades\CommandLine;
use Lucent\Facades\Faker;
use Lucent\Filesystem\File;
use Lucent\ModelCollection;
use PHPUnit\Framework\Attributes\DataProvider;

// Manually require the DatabaseDriverSetup file
$driverSetupPath = __DIR__ . '/DatabaseDriverSetup.php';

if (file_exists($driverSetupPath)) {
    require_once $driverSetupPath;
} else {
    // Fallback path if the normal path doesn't work
    require_once dirname(__DIR__, 1) . '/Unit/DatabaseDriverSetup.php';
}


class ModelTest extends DatabaseDriverSetup
{
    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function databaseDriverProvider(): array
    {
        return [
            'sqlite' => [
                'sqlite',
                [
                    'DB_DATABASE' => '/storage/database.sqlite'
                ]
            ],
            'mysql' => [
                'mysql',
                [
                    'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
                    'DB_PORT' => getenv('DB_PORT') ?: '3306',
                    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test_database',
                    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''
                ]
            ]
        ];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::generate_test_extended_model();
        self::generate_test_model();
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $output = CommandLine::execute("Migration make App/Models/TestUser");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_creation($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver, $config);

        $user = new \App\Models\TestUser("john@doe.com", "password", "John Doe");

        self::assertTrue($user->create());

        $this->assertNotNull(\App\Models\TestUser::where("email", "john@doe.com")->getFirst());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_updating($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver, $config);

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
        self::setupDatabase($driver, $config);

        $output = CommandLine::execute("Migration make App/Models/Admin");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_creation($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver, $config);

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
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver, $config);

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
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver, $config);

        $adminUser = new \App\Models\Admin("gibbs@blackpearl.com", "password", "Joshamee Gibbs", false, false, "Just a crew member");

        $this->assertTrue($adminUser->create());

        $this->assertTrue($adminUser->delete());

        $lookUp = \App\Models\Admin::where("email", "gibbs@blackpearl.com")->getFirst();

        $this->assertNull($lookUp);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_update($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver, $config);

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
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver, $config);

        $adminUser = new \App\Models\Admin("davey@jones.com", "password", "Davey Jones", false, false, "Captain of the flying dutchman");

        $this->assertTrue($adminUser->create());

        $lookup = \App\Models\Admin::where("full_name", "Davey Jones")->getFirst();
        $this->assertNotNull($lookup);
        $this->assertEquals("Davey Jones", $lookup->getFullName());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_pk_auto_increment($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver, $config);

        $user = new \App\Models\TestUser("ai@test.com", "password", "AI Test");

        $this->assertTrue($user->create());

        $this->assertNotEquals(-1, $user->id);

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_count($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver, $config);

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
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver, $config);

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
        self::setupDatabase($driver, $config);

        $this->generate_test_model_long_text();

        $output = CommandLine::execute("Migration make App/Models/LongTextModel");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration_with_trait($driver, $config): void
    {
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_soft_delete_trait()->exists());
        $this->assertTrue($this->generate_soft_delete_trait_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TestUserTwo");
        $this->assertEquals("Successfully performed database migration", $output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_trait_use($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration_with_trait($driver, $config);

        $user = new \App\Models\TestUserTwo("john.smith@test.com", "password", "John Smith");

        $this->assertTrue($user->create());

        $this->assertTrue($user->delete());

        $user = \App\Models\TestUserTwo::where("email", "john.smith@test.com")->getFirst();
        $this->assertNotNull($user->deleted_at);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_trait_model_collection_hook($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration_with_trait($driver, $config);

        // Create and save a model
        $user = new \App\Models\TestUserTwo("john.smith@test.com", "password", "John Smith");
        $this->assertTrue($user->create());

        // Soft delete the model
        $this->assertTrue($user->delete());

        // Register the trait condition
        ModelCollection::registerTraitCondition(
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
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);
        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);
        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);
        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration", $output);

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
        self::setupDatabase($driver, $config);
        $this->assertTrue($this->generate_test_model_numeric_string_bug()->exists());

        $output = CommandLine::execute("Migration make App/Models/TestCustomer");
        $this->assertEquals("Successfully performed database migration", $output);

        $mobile = "0423235427";

        $customer = new \App\Models\TestCustomer($mobile);

        $this->assertTrue($customer->create());

        $lookup = \App\Models\TestCustomer::where("mobile", $mobile)->getFirst();
        $this->assertNotNull($lookup);
    }


    public static function generate_test_model(): File
    {
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TestUser extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => false
    ])]
    protected string $email;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => false
    ])]
    protected string $password_hash;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => false,
        "LENGTH" => 100
    ])]
    protected string $full_name;

    public function __construct(string $email, string $password_hash, string $full_name)
    {
        $this->email = $email;
        $this->password_hash = $password_hash;
        $this->full_name = $full_name;
    }

    public function getFullName(): string
    {
        return $this->full_name;
    }

    public function setFullName(string $full_name)
    {
        $this->full_name = $full_name;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
PHP;

        return new File("/App/Models/TestUser.php", $modelContent);
    }

    public static function generate_test_extended_model(): File
    {
        $adminModel = <<<'PHP'
<?php

namespace App\Models;

use App\Models\TestUser;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class Admin extends TestUser
{
    #[Column(ColumnType::BOOLEAN, default: false)]
    public private(set) bool $can_reset_passwords;

    #[Column(ColumnType::BOOLEAN, default: false)]
    public private(set) bool $can_lock_accounts;

    #[Column(ColumnType::VARCHAR, length: 255, nullable: true)]
    public private(set) ?string $notes;


    public function __construct(
        string $email,
        string $password_hash,
        string $full_name,
        bool $can_reset_passwords,
        bool $can_lock_accounts,
        ?string $notes = null
    ) {
        parent::__construct($email, $password_hash, $full_name);

        $this->can_reset_passwords = $can_reset_passwords;
        $this->can_lock_accounts = $can_lock_accounts;
        $this->notes = $notes;
    }

    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }
}
PHP;

        return new File("/App/Models/Admin.php", $adminModel);
    }

    public static function generate_test_model_long_text(): File
    {
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class LongTextModel extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::LONGTEXT)]
    protected string $email;

    #[Column(ColumnType::TEXT)]
    protected string $text;

    #[Column(ColumnType::MEDIUMTEXT)]
    protected string $mText;

    #[Column(ColumnType::VARCHAR, length: 100)]
    protected string $full_name;

    public function __construct(string $email, string $text, string $mText, string $full_name)
    {
        $this->email = $email;
        $this->text = $text;
        $this->mText = $mText;
        $this->full_name = $full_name;
    }

    public function getFullName(): string
    {
        return $this->full_name;
    }

    public function setFullName(string $full_name)
    {
        $this->full_name = $full_name;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
PHP;


        return new File("/App/Models/LongTextModel.php", $modelContent);
    }

    public static function generate_soft_delete_trait(): File
    {
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Model\Column;
use Lucent\Model\ColumnType;

trait SoftDelete
{
    #[Column(ColumnType::INT, nullable: true)]
    public private(set) ?int $deleted_at = null;

    /**
     * Override the base delete method with a soft delete implementation
     *
     * @param mixed $propertyName The primary key property name
     * @return bool Success
     */
    public function delete($propertyName = "id"): bool
    {
        return $this->softDelete($propertyName);
    }

    /**
     * Delete the model by setting the deleted_at timestamp
     *
     * @param string $propertyName The primary key property name
     * @return bool Success
     */
    public function softDelete(string $propertyName = "id"): bool
    {
        $this->deleted_at = time();
        return $this->save($propertyName);
    }

    /**
     * Restore a soft deleted model
     *
     * @return bool Success
     */
    public function restore(): bool
    {
        $this->deleted_at = null;
        return $this->save();
    }
}
PHP;


        return new File("/App/Models/SoftDelete.php", $modelContent);
    }

    public static function generate_soft_delete_trait_model(): File
    {
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Model;
use App\Models\SoftDelete;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TestUserTwo extends Model
{
    use SoftDelete;

    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255)]
    protected string $email;

    #[Column(ColumnType::VARCHAR, length: 255)]
    protected string $password_hash;

    #[Column(ColumnType::VARCHAR, length: 100)]
    protected string $full_name;

    public function __construct(string $email, string $password_hash, string $full_name)
    {
        $this->email = $email;
        $this->password_hash = $password_hash;
        $this->full_name = $full_name;
    }

    public function getFullName(): string
    {
        return $this->full_name;
    }

    public function setFullName(string $full_name)
    {
        $this->full_name = $full_name;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
PHP;


        return new File("/App/Models/TestUserTwo.php", $modelContent);
    }

    public static function generate_transaction_model(): File
    {
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TransactionModel extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public private(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255, nullable: true)]
    protected ?string $description;

    #[Column(ColumnType::DECIMAL)]
    public protected(set) float $amount;

    #[Column(ColumnType::INT)]
    protected int $type;

    #[Column(ColumnType::INT)]
    public protected(set) int $date;

    public function __construct(float $amount, int $type, ?string $description = null, ?int $date = null)
    {
        $this->amount = $amount;
        $this->description = $description;
        $this->type = $type;
        $this->date = $date ?? time();
    }
}
PHP;


        return new File("/App/Models/TransactionModel.php", $modelContent);
    }

    public static function generate_test_model_numeric_string_bug(): File
    {
        $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Model;
use Lucent\Model\Column;
use Lucent\Model\ColumnType;

class TestCustomer extends Model
{
    #[Column(ColumnType::INT, primaryKey: true, autoIncrement: true)]
    public protected(set) ?int $id;

    #[Column(ColumnType::VARCHAR, length: 255)]
    public protected(set) string $mobile;

    public function __construct(string $mobile)
    {
        $this->mobile = $mobile;
    }
}
PHP;

        return new File("/App/Models/TestCustomer.php", $modelContent);
    }
}