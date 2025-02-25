<?php

namespace Unit;

use App\Models\Admin;
use Lucent\Application;
use Lucent\Database;
use Lucent\Database\Dataset;
use Lucent\Facades\CommandLine;
use Lucent\Facades\File;
use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Temporarily set EXTERNAL_ROOT to match TEMP_ROOT for testing
        File::overrideRootPath(TEMP_ROOT.DIRECTORY_SEPARATOR);

        // Create storage directory in our temp environment
        if (!is_dir(File::rootPath() . 'storage')) {
            mkdir(File::rootPath() . 'storage', 0755, true);
        }

        $env =  <<<'ENV'
                # MySQL Configuration
                DB_DRIVER=sqlite
                #DB_HOST=localhost
                #DB_PORT=3306
                #DB_DATABASE=test_database
                #DB_USERNAME=root
                #DB_PASSWORD=your_password
                
                # SQLite Configuration (commented out)
                DB_DATABASE=/database.sqlite
                ENV;

        $path = File::rootPath(). '.env';

        $result = file_put_contents($path, $env);

        if ($result === false) {
            throw new \RuntimeException("Failed to write .env file to: " . $path);
        }

        // Optionally verify the file exists
        if (!file_exists($path)) {
            throw new \RuntimeException(".env file was not created at: " . $path);
        }

        $app = Application::getInstance();
        $app->LoadEnv();

        self::generate_test_extended_model();
        self::generate_test_model();

    }


    public function test_model_migration() : void
    {

        $output = CommandLine::execute("Migration make App/Models/TestUser");
        $this->assertEquals("Successfully performed database migration",$output);

    }

    public function test_model_creation() : void
    {

        $this->test_model_migration();

        $user = new \App\Models\TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
        ]));

        self::assertTrue($user->create());
    }

    public function test_model_updating() : void
    {

        $this->test_model_migration();

        $user = new \App\Models\TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "john@doe.com",
            "password_hash" => "password",
        ]));

        self::assertTrue($user->create());

        $user = \App\Models\TestUser::where("full_name", "John Doe")->getFirst();

        $this->assertNotNull($user);

        $user->setFullName("Jack Harris");

        try{
            $user->save();
        }catch (\Exception $e){
            $this->fail($e->getMessage());
        }

        $user = \App\Models\TestUser::where("full_name", "Jack Harris")->getFirst();

        $this->assertNotNull($user);
        $this->assertEquals("Jack Harris",$user->getFullName());

    }

    public function test_extended_model_migration() : void
    {
        //Drop our test user from the prior tests to ensure it generates both.
        Database::query("DROP TABLE IF EXISTS TestUser");

        $output = CommandLine::execute("Migration make App/Models/Admin");
        $this->assertEquals("Successfully performed database migration",$output);    }

    public function test_extended_model_creation() : void
    {
        $this->test_extended_model_migration();

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

    public function test_extended_model_counts() : void{
        $this->test_extended_model_migration();

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

    private static function generate_test_model(): void
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
            private ?int $id;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false
            ])]
            private string $email;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false
            ])]
            private string $password_hash;
        
            #[DatabaseColumn([
                "TYPE"=>LUCENT_DB_VARCHAR,
                "ALLOW_NULL"=>false,
                "LENGTH"=>100
            ])]
            private string $full_name;
        
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
        
        
        }
        PHP;


        $appPath = File::rootPath(). "App";
        $modelPath = $appPath . DIRECTORY_SEPARATOR . "Models";

        if (!is_dir($modelPath)) {
            mkdir($modelPath, 0755, true);
        }

        file_put_contents(
            $modelPath.DIRECTORY_SEPARATOR.'TestUser.php',
            $modelContent
        );

    }

    private static function generate_test_extended_model(): void
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
}
PHP;

        // Create directories if they don't exist
        $appPath = File::rootPath() . "App";
        $modelPath = $appPath . DIRECTORY_SEPARATOR . "Models";

        if (!is_dir($modelPath)) {
            mkdir($modelPath, 0755, true);
        }

        // Write model files
        file_put_contents(
            $modelPath . DIRECTORY_SEPARATOR . 'Admin.php',
            $adminModel
        );

    }






}