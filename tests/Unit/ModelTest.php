<?php

namespace Unit;

use App\Models\Admin;
use App\Models\TestUser;
use Lucent\Database;
use Lucent\Database\Dataset;
use Lucent\Facades\CommandLine;
use Lucent\Filesystem\File;
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

    private static function generate_test_model(): File
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

    private static function generate_test_extended_model(): File
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
}