<?php

namespace Unit;

use App\Extensions\View\View;
use App\Models\Admin;
use App\Models\TestUser;
use App\Models\TestUserTwo;
use App\Models\TransactionModel;
use Lucent\Database;
use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use Lucent\Facades\CommandLine;
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
            'sqlite' => ['sqlite', [
                'DB_DATABASE' => '/database.sqlite'
            ]],
            'mysql' => ['mysql', [
                'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
                'DB_PORT' => getenv('DB_PORT') ?: '3306',
                'DB_DATABASE' => getenv('DB_DATABASE') ?: 'test_database',
                'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
                'DB_PASSWORD' => getenv('DB_PASSWORD') ?: ''
            ]]
        ];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::generate_test_extended_model();
        self::generate_test_model();
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $output = CommandLine::execute("Migration make App/Models/TestUser");
        $this->assertEquals("Successfully performed database migration",$output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_creation($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver,$config);

        $user = new \App\Models\TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
        ]));

        self::assertTrue($user->create());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_updating($driver, $config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver,$config);

        $user = new \App\Models\TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
        ]));

        self::assertTrue($user->create());

        $user = \App\Models\TestUser::where("full_name", "John Doe")->getFirst();

        $this->assertNotNull($user);

        $user->setFullName("Jack Harris");

        $result = false;
        try{
            $result = $user->save();
        }catch (\Exception $e){
            $this->fail($e->getMessage());
        }

        $this->assertTrue($result);

        $user = \App\Models\TestUser::where("full_name", "Jack Harris")->getFirst();

        $this->assertNotNull($user);
        $this->assertEquals("Jack Harris",$user->getFullName());

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_migration($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        if($driver == "mysql"){
            Database::statement("SET FOREIGN_KEY_CHECKS=0");
        }
        //Drop our test user from the prior tests to ensure it generates both.
        Database::statement("DROP TABLE IF EXISTS TestUser");
        if($driver == "mysql"){
            Database::statement("SET FOREIGN_KEY_CHECKS=1");
        }
        $output = CommandLine::execute("Migration make App/Models/Admin");
        $this->assertEquals("Successfully performed database migration",$output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_creation($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver,$config);

        $adminUser = new \App\Models\Admin(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
            "can_lock_accounts" => true,
            "can_reset_passwords" => false,
            "notes" => "Just a test!"
        ]));

        $this->assertTrue($adminUser->create());

        $lookup = Admin::where("email", "john@doe.com")->where("can_lock_accounts",true)->getFirst();
        $this->assertEquals("John Doe",$lookup->getFullName());
        $this->assertTrue($lookup->can_lock_accounts);
        $this->assertFalse($lookup->can_reset_passwords);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_counts($driver,$config) : void{
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver,$config);

        $adminUser = new \App\Models\Admin(new Dataset([
            "full_name" => "Joshamee Gibbs",
            "email" => "gibbs@blackpearl.com",
            "password_hash" => "password",
            "can_lock_accounts" => false,
            "can_reset_passwords" => false,
            "notes" => "Just a crew member"
        ]));

        $this->assertTrue($adminUser->create());

        $adminUser = new \App\Models\Admin(new Dataset([
            "full_name" => "Captain Jack",
            "email" => "jack@blackpearls.com",
            "password_hash" => "password",
            "can_lock_accounts" => true,
            "can_reset_passwords" => true,
            "notes" => "Hes the captain"
        ]));

        $this->assertTrue($adminUser->create());

        $count = Admin::where("can_lock_accounts", true)->count();

        $this->assertEquals(1, $count);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_delete($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver,$config);

        $adminUser = new \App\Models\Admin(new Dataset([
            "full_name" => "Joshamee Gibbs",
            "email" => "gibbs@blackpearl.com",
            "password_hash" => "password",
            "can_lock_accounts" => false,
            "can_reset_passwords" => false,
            "notes" => "Just a crew member"
        ]));

        $this->assertTrue($adminUser->create());

        $this->assertTrue($adminUser->delete());

        $lookUp = Admin::where("email", "gibbs@blackpearl.com")->getFirst();

        $this->assertNull($lookUp);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_update($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver,$config);

        $adminUser = new \App\Models\Admin(new Dataset([
            "full_name" => "Joshamee Gibbs",
            "email" => "gibbs@blackpearl.com",
            "password_hash" => "password",
            "can_lock_accounts" => false,
            "can_reset_passwords" => false,
            "notes" => "Just a crew member"
        ]));

        $this->assertTrue($adminUser->create());

        $adminUser->setFullName("Jack Harris");
        $adminUser->setNotes("Not a pirate any more!");

        try {
            $adminUser->save();
        }catch (\Exception $e){
            $this->fail($e->getMessage());
        }

        $lookup = \App\Models\Admin::where("full_name", "Jack Harris")->getFirst();
        $this->assertNotNull($lookup);
        $this->assertEquals("Not a pirate any more!",$lookup->notes);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_extended_model_getFirst($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_extended_model_migration($driver,$config);

        $adminUser = new \App\Models\Admin(new Dataset([
            "full_name" => "Davey Jones",
            "email" => "Davey@Jones.com",
            "password_hash" => "password",
            "can_lock_accounts" => false,
            "can_reset_passwords" => false,
            "notes" => "Captain of the flying dutchman."
        ]));

        $this->assertTrue($adminUser->create());

        $lookup = \App\Models\Admin::where("full_name", "Davey Jones")->getFirst();
        $this->assertNotNull($lookup);
        $this->assertEquals("Davey Jones",$lookup->getFullName());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_pk_auto_increment($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver,$config);

        $user = new \App\Models\TestUser(new Dataset([
            "full_name" => "AI Test",
            "email" => "ai@test.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $this->assertNotEquals(-1,$user->id);

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_count($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver,$config);

        $count = 10;
        $i = 0;
        while($i < $count){
            $user =  new \App\Models\TestUser(new Dataset([
                "full_name" => "user-".$i,
                "email" => "user-".$i."@test.com",
                "password_hash" => "password",
            ]));

            $this->assertTrue($user->create());
            $i++;
        }

        $this->assertEquals($count,TestUser::limit(100)->count());
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_like_or($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->test_model_migration($driver,$config);

        $user =  new \App\Models\TestUser(new Dataset([
            "full_name" => "John Smith",
            "email" => "john.smith@test.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $user =  new \App\Models\TestUser(new Dataset([
            "full_name" => "James Smith",
            "email" => "james.smith@gmail.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $user =  new \App\Models\TestUser(new Dataset([
            "full_name" => "Bill Clinton",
            "email" => "bill@gmail.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $gmailUsers = TestUser::limit(100)->like("email","gmail.com")->get();

        $this->assertCount(2,$gmailUsers);

        $gmailAndBill = TestUser::limit(100)->like("email","gmail.com")->orLike("full_name","Bill")->get();

        $this->assertCount(2,$gmailAndBill);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration_long_text($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->generate_test_model_long_text();

        $output = CommandLine::execute("Migration make App/Models/LongTextModel");
        $this->assertEquals("Successfully performed database migration",$output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration_with_trait($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_soft_delete_trait()->exists());
        $this->assertTrue($this->generate_soft_delete_trait_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TestUserTwo");
        $this->assertEquals("Successfully performed database migration",$output);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_trait_use($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration_with_trait($driver,$config);

        $user = new \App\Models\TestUserTwo(new Dataset([
            "full_name" => "John Smith",
            "email" => "john.smith@test.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $this->assertTrue($user->delete());

        $user = \App\Models\TestUserTwo::where("email","john.smith@test.com")->getFirst();
        $this->assertNotNull($user->deleted_at);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_trait_model_collection_hook($driver, $config): void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration_with_trait($driver,$config);

        // Create and save a model
        $user = new \App\Models\TestUserTwo(new Dataset([
            'email' => 'john.smith@test.com',
            'password_hash' => 'password',
            'full_name' => 'John Smith'
        ]));
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
    public function test_model_get_sum_of_column($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration",$output);


        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => 25.5,
        ]));

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>1,
            "amount" => -50,
        ]));

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => 120,
        ]));

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => 4.5,
        ]));

        $this->assertTrue($transaction->create());

        //0 = credit, 1 = debit
        $sum = \App\Models\TransactionModel::where("type",0)->sum("amount");

        $this->assertEquals(150,$sum);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_get_sum_of_column_with_subtraction($driver,$config) : void
    {
        self::setupDatabase($driver, $config);

        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration",$output);


        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => 25.5,
        ]));

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => -50,
        ]));

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => 120,
        ]));

        $this->assertTrue($transaction->create());

        $transaction = new \App\Models\TransactionModel(new Dataset([
            "type" =>0,
            "amount" => 4.5,
        ]));

        $this->assertTrue($transaction->create());

        //0 = credit, 1 = debit
        $sum = \App\Models\TransactionModel::where("type",0)->sum("amount");

        $this->assertEquals(100,$sum);
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_sorting_asc($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration",$output);

        $i = 0;
        while($i < 10){

            $transaction = new \App\Models\TransactionModel(new Dataset([
                "type" => 0,
                "amount" => rand(10,200),
            ]));

            $this->assertTrue($transaction->create());

            $i++;
        }

        $last = -1;
        foreach (TransactionModel::where("type",0)->orderBy('amount')->get() as $transaction){
            $this->assertTrue($last <= $transaction->amount);
            $last = $transaction->amount;
        }

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_sorting_dsc($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->assertTrue($this->generate_transaction_model()->exists());

        $output = CommandLine::execute("Migration make App/Models/TransactionModel");
        $this->assertEquals("Successfully performed database migration",$output);

        $i = 0;
        while($i < 10){

            $transaction = new \App\Models\TransactionModel(new Dataset([
                "type" => 0,
                "amount" => rand(10,200),
            ]));

            $this->assertTrue($transaction->create());

            $i++;
        }

        $last = 200;
        foreach (TransactionModel::where("type",0)->orderBy('amount',"DESC")->get() as $transaction){
            $this->assertTrue($last >= $transaction->amount);
            $last = $transaction->amount;
        }

    }


    public static function generate_test_model(): File
    {
        $modelContent = <<<'PHP'
        <?php
        
        namespace App\Models;
        
        use Lucent\Database\Attributes\DatabaseColumn;
        use Lucent\Database\Dataset;
        use Lucent\Model;
        
        class TestUser extends Model
        {
        
            #[DatabaseColumn([
                "PRIMARY_KEY"=>true,
                "TYPE"=>LUCENT_DB_INT,
                "ALLOW_NULL"=>false,
                "AUTO_INCREMENT"=>true,
                "LENGTH"=>255
            ])]
            public private(set) ?int $id;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false
            ])]
            protected string $email;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false
            ])]
            protected string $password_hash;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false,
                "LENGTH"=>100
            ])]
            protected string $full_name;
        
            public function __construct(Dataset $dataset){
                $this->id = $dataset->get("id",-1);
                $this->email = $dataset->get("email");
                $this->password_hash = $dataset->get("password_hash");
                $this->full_name = $dataset->get("full_name");
            }
        
            public function getFullName() : string{
                return $this->full_name;
            }
            
            public function setFullName(string $full_name){
                $this->full_name = $full_name;
            }
            
            public function getId() : int
            {
                return $this->id;
            }
        
        
        }
        PHP;


        return new File("/App/Models/TestUser.php",$modelContent);

    }

    public static function generate_test_extended_model(): File
    {
        $adminModel = <<<'PHP'
<?php

namespace App\Models;

use Lucent\Database\Attributes\DatabaseColumn;
use Lucent\Database\Dataset;
use App\Models\TestUser;

class Admin extends TestUser
{
    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_BOOLEAN,
        "ALLOW_NULL" => false,
        "DEFAULT" => false
    ])]
    public private(set) bool $can_reset_passwords;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_BOOLEAN,
        "ALLOW_NULL" => false,
        "DEFAULT" => false,
    ])]
    public private(set) bool $can_lock_accounts;

    #[DatabaseColumn([
        "TYPE" => LUCENT_DB_VARCHAR,
        "ALLOW_NULL" => true,
    ])]
    public private(set) ?string $notes;


    public function __construct(Dataset $dataset){
        parent::__construct($dataset);
        
        $this->can_reset_passwords = $dataset->get("can_reset_passwords");
        $this->can_lock_accounts = $dataset->get("can_lock_accounts");
        $this->notes = $dataset->get("notes");
    }
    
    public function setNotes(string $notes): void
    {
        $this->notes = $notes;
    }
}
PHP;

        return new File("/App/Models/Admin.php",$adminModel);
    }

    public static function generate_test_model_long_text(): File
    {
        $modelContent = <<<'PHP'
        <?php
        
        namespace App\Models;
        
        use Lucent\Database\Attributes\DatabaseColumn;
        use Lucent\Database\Dataset;
        use Lucent\Model;
        
        class LongTextModel extends Model
        {
        
            #[DatabaseColumn([
                "PRIMARY_KEY"=>true,
                "TYPE"=>LUCENT_DB_INT,
                "ALLOW_NULL"=>false,
                "AUTO_INCREMENT"=>true,
                "LENGTH"=>255
            ])]
            public private(set) ?int $id;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_LONGTEXT,
            ])]
            protected string $email;
            
                  #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_TEXT,
            ])]
            protected string $text;
            
                    #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_MEDIUMTEXT,
            ])]
            protected string $mText;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_LONGTEXT,
                "ALLOW_NULL"=>false
            ])]
            protected string $password_hash;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false,
                "LENGTH"=>100
            ])]
            protected string $full_name;
        
            public function __construct(Dataset $dataset){
                $this->id = $dataset->get("id",-1);
                $this->email = $dataset->get("email");
                $this->password_hash = $dataset->get("password_hash");
                $this->full_name = $dataset->get("full_name");
            }
        
            public function getFullName() : string{
                return $this->full_name;
            }
            
            public function setFullName(string $full_name){
                $this->full_name = $full_name;
            }
            
            public function getId() : int
            {
                return $this->id;
            }
        
        
        }
        PHP;


        return new File("/App/Models/LongTextModel.php",$modelContent);

    }

    public static function generate_soft_delete_trait(): File
    {
        $modelContent = <<<'PHP'
        <?php
        
        namespace App\Models;
        
        use Lucent\Database\Attributes\DatabaseColumn;
        use Lucent\Database\Dataset;
        use Lucent\Model;
        
        trait SoftDelete
        {
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_INT,
                "ALLOW_NULL"=>true
            ])]
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


        return new File("/App/Models/SoftDelete.php",$modelContent);

    }

    public static function generate_soft_delete_trait_model(): File
    {
        $modelContent = <<<'PHP'
        <?php
        
        namespace App\Models;
        
        use Lucent\Database\Attributes\DatabaseColumn;
        use Lucent\Database\Dataset;
        use Lucent\Model;
        use App\Models\SoftDelete;
        
        class TestUserTwo extends Model
        {
         use SoftDelete;
        
            #[DatabaseColumn([
                "PRIMARY_KEY"=>true,
                "TYPE"=>LUCENT_DB_INT,
                "ALLOW_NULL"=>false,
                "AUTO_INCREMENT"=>true,
                "LENGTH"=>255
            ])]
            public private(set) ?int $id;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false
            ])]
            protected string $email;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false
            ])]
            protected string $password_hash;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false,
                "LENGTH"=>100
            ])]
            protected string $full_name;
        
            public function __construct(Dataset $dataset){
                $this->id = $dataset->get("id",-1);
                $this->email = $dataset->get("email");
                $this->password_hash = $dataset->get("password_hash");
                $this->full_name = $dataset->get("full_name");
                $this->deleted_at = $dataset->get("deleted_at");
            }
        
            public function getFullName() : string{
                return $this->full_name;
            }
            
            public function setFullName(string $full_name){
                $this->full_name = $full_name;
            }
            
            public function getId() : int
            {
                return $this->id;
            }
        
        
        }
        PHP;


        return new File("/App/Models/TestUserTwo.php",$modelContent);

    }

    public static function generate_transaction_model(): File
    {
        $modelContent = <<<'PHP'
        <?php
        
        namespace App\Models;
        
        use Lucent\Database\Attributes\DatabaseColumn;
        use Lucent\Database\Dataset;
        use Lucent\Model;
        use App\Models\SoftDelete;
        
        class TransactionModel extends Model
        {
        
            #[DatabaseColumn([
                "PRIMARY_KEY"=>true,
                "TYPE"=>LUCENT_DB_INT,
                "ALLOW_NULL"=>false,
                "AUTO_INCREMENT"=>true,
                "LENGTH"=>255
            ])]
            public private(set) ?int $id;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>true
            ])]
            protected ?string $description;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_DECIMAL,
                "ALLOW_NULL"=>false
            ])]
            public protected(set) float $amount;
            
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_INT,
                "ALLOW_NULL"=>false
            ])]
            protected int $type;
        
            public function __construct(Dataset $dataset){
                $this->id = $dataset->get("id",-1);
                $this->description = $dataset->get("description");
                $this->amount = $dataset->get("amount");
                $this->type = $dataset->get("type");
            }   
        }
        PHP;


        return new File("/App/Models/TransactionModel.php",$modelContent);

    }




}