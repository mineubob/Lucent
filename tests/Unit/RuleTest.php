<?php

namespace Unit;

use App\Models\TestUser;
use Lucent\Application;
use Lucent\Database\Dataset;
use Lucent\Facades\CommandLine;
use Lucent\Facades\Faker;
use Lucent\Facades\FileSystem;
use Lucent\Facades\Regex;
use Lucent\Validation\Rule;
use PHPUnit\Framework\Attributes\DataProvider;

abstract class TestRule extends Rule
{
    public function validate_bool(array $data): bool
    {
        return sizeof($this->validate($data)) === 0;
    }
}

class NumRule extends TestRule
{
    public function setup(): array
    {
        return [
            'num1' => [
                'min_num:1',
                'max_num:5',
            ],
            'num2' => [
                'min_num:7',
                'max_num:10',
            ]
        ];
    }
}

class OverrideMessageRule extends TestRule
{
    public function setup(): array
    {

        $this->overrideRuleMessage("min","Message Override!");

        return [
            'first_name' => [
                'min:5',
                'max:10',
            ]
        ];
    }
}

class DynamicRule extends Rule
{

    private array $keys;

    public function __construct(array $keys){
        $this->keys = $keys;
    }

    public function setup(): array
    {
        $rules = [
            'first_name' => [
                'min:2',
                'max:10',
            ],
            'last_name' => [
                'min:2',
                'max:10',
            ]
            ,
            'address' => [
                'min:0',
                'max:10',
            ]
        ];

        return array_filter($rules, function (string $field) {
            return array_key_exists($field, $this->keys);
        }, ARRAY_FILTER_USE_KEY);
    }
}

class CustomRule extends Rule
{

    public function setup(): array
    {
        return [
            'post_code' => [
                'validate_post_code',
            ]
        ];
    }

    protected function validate_post_code(mixed $value): bool
    {
        return strlen((string)$value) === 4;
    }
}

class CustomRegexRule extends Rule
{

    public function setup(): array
    {
        $this->addRegexPattern("custom_rule",'/^(?=(?:.*\d){3})(?=(?:.*[a-zA-Z]){3})[a-zA-Z\d]{6}$/');

        return [
            'test' => [
                'regex:custom_rule',
            ]
        ];

    }

}


// Manually require the DatabaseDriverSetup file
$driverSetupPath = __DIR__ . '/DatabaseDriverSetup.php';

if (file_exists($driverSetupPath)) {
    require_once $driverSetupPath;
} else {
    // Fallback path if the normal path doesn't work
    require_once dirname(__DIR__, 1) . '/Unit/DatabaseDriverSetup.php';
}


class RuleTest extends DatabaseDriverSetup
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

    public function test_num_rule_is_valid() : void
    {
        $rule = new NumRule();

        $test_data = Faker::request()->passing(NumRule::class)->all();

        $this->assertTrue($rule->validate_bool($test_data));
    }

    public function test_num_rule_is_not_valid() : void
    {
        $rule = new NumRule();

        $test_data = Faker::request()->failing(NumRule::class)->all();

        $this->assertFalse($rule->validate_bool($test_data));
    }

    public function test_dynamic_rule_passing() : void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", "Harris");
        $request->setInput("email", "notvalid.com");

        $rule = new DynamicRule($request->all());

        $this->assertTrue($request->validate($rule));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_dynamic_rule_failing() : void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", 1);

        $rule = new DynamicRule($request->all());
        $this->assertFalse($request->validate($rule));
        $this->assertEquals(1,count($request->getValidationErrors()));

    }

    public function test_dynamic_rule_only_validates_present_fields(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");

        // Create a rule that only validates first_name
        $rule = new DynamicRule(["first_name" => "Jack"]);

        // Add an invalid last_name that would fail if validated
        $request->setInput("last_name", "A");  // Too short, would fail min:2

        // Should pass because last_name isn't in the keys array and won't be validated
        $this->assertTrue($request->validate($rule));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_dynamic_rule_with_null_fields_passing(): void
    {
        $_SERVER["REQUEST_METHOD"] = "POST";

        $request = Faker::request();

        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", "Harris");
        $request->setInput("address", "");

        $request->reInitializeRequestData();

        // Create a rule that only validates first_name
        $rule = new DynamicRule($request->all());

        // Should pass because last_name isn't in the keys array and won't be validated
        $this->assertTrue($request->validate($rule));
        $this->assertEmpty($request->getValidationErrors());

        $this->assertEquals("",$request->input("address"));
    }

    public function test_dynamic_rule_with_null_fields_failing(): void
    {
        $_SERVER["REQUEST_METHOD"] = "POST";

        $request = Faker::request();

        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", "Harris");
        $request->setInput("address", "123456789123");

        $request->reInitializeRequestData();

        // Create a rule that only validates first_name
        $rule = new DynamicRule($request->all());

        // Should pass because last_name isn't in the keys array and won't be validated
        $this->assertFalse($request->validate($rule));
        $this->assertCount(1,$request->getValidationErrors());

    }

    public function test_custom_rule_passing(): void{

        $request = Faker::request();
        $request->setInput("post_code", "3934");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(CustomRule::class));
        $this->assertEmpty($request->getValidationErrors());

    }

    public function test_custom_rule_failing(): void{

        $request = Faker::request();
        $request->setInput("post_code", "393");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(CustomRule::class));
        $this->assertCount(1,$request->getValidationErrors());

    }

    public function test_same_rule_passing() : void
    {
        $request = Faker::request();

        $request->setInput("password", "Pa55w0rd");
        $request->setInput("confirm_password", "Pa55w0rd");

        $request->reInitializeRequestData();

        $outcome = $request->validate([
            "password" => ["min:8","max:8"],
            "confirm_password" => ["same:@password"],
        ]);

        $this->assertTrue($outcome);
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_same_rule_failing() : void
    {
        $request = Faker::request();

        $request->setInput("password", "Pa55w0rd");
        $request->setInput("confirm_password", "Pa55w0rd1");

        $request->reInitializeRequestData();

        $outcome = $request->validate([
            "password" => ["min:8","max:8"],
            "confirm_password" => ["same:@password"],
        ]);

        $this->assertFalse($outcome);
        $this->assertCount(1,$request->getValidationErrors());
    }

    public function test_regex_email_passing(): void
    {
        $request = Faker::request();
        $request->setInput("email", "st_tuff@me.com");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(["email"=>["regex:email"]]));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_regex_email_failing(): void
    {
        $request = Faker::request();
        $request->setInput("email", "st_tuffme.com");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["email"=>["regex:email"]]));
        $this->assertCount(1,$request->getValidationErrors());
    }

    public function test_regex_password_passing(): void
    {
        $request = Faker::request();
        $request->setInput("password", "Password1");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(["password"=>["regex:password"]]));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_regex_password_failing(): void
    {
        $request = Faker::request();
        $request->setInput("password", "pass");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["password"=>["regex:password"]]));
        $this->assertCount(1,$request->getValidationErrors());
    }

    public function test_regex_invalid_rule(): void
    {
        $request = Faker::request();
        $request->setInput("email", "st_tuff@me.com");
        $request->reInitializeRequestData();

        $exceptionCaught = false;

        try {
            $request->validate([
                "email" => "regex:emai"
            ]);
        } catch (\InvalidArgumentException $e) {
            $exceptionCaught = true;
            $this->assertStringContainsString("Regex emai does not exists", $e->getMessage());
        }

        $this->assertTrue($exceptionCaught, "Expected exception was not thrown");
    }


    public function test_custom_local_regex_rule_passing(): void
    {
        $request = Faker::request();
        $request->setInput("test", "abc123");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(CustomRegexRule::class));
        $this->assertEmpty($request->getValidationErrors());

    }

    public function test_custom_local_regex_rule_failing(): void
    {
        $request = Faker::request();
        $request->setInput("test", "abc12");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(CustomRegexRule::class));
        $this->assertCount(1,$request->getValidationErrors());
    }

    public function test_custom_global_regex_rule_passing(): void
    {

        Application::getInstance();

        Regex::set("global_custom",'/^\S+\s+\S+$/');

        $request = Faker::request();
        $request->setInput("test", "abc 123");
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(["test"=>["regex:global_custom"]]));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_custom_global_regex_rule_failing(): void
    {

        Application::getInstance();

        Regex::set("global_custom",'/^\S+\s+\S+$/');

        $request = Faker::request();
        $request->setInput("test", "abc123");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["test"=>["regex:global_custom"]]));
        $this->assertCount(1,$request->getValidationErrors());
    }

    public function test_request_errors() : void
    {
        $request = Faker::request();
        $request->setInput("email", "testemail.com");
        $request->setInput("password", "pass");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["email"=>["regex:email"],"password"=>["regex:password","min:4"]]));

    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_unique_passing($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration($driver,$config);


        $request = Faker::request();
        $request->setInput("email","unique-test@email.com");
        $request->setInput("full_name","John Doe");
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "email" => ["unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_unique_failing($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration($driver,$config);

        $user = new TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "unique-test@email.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());


        $request = Faker::request();
        $request->setInput("email","unique-test@email.com");
        $request->setInput("full_name","John Doe");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "email" => ["unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_not_unique_passing($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration($driver,$config);


        $user = new TestUser(new Dataset([
            "full_name" => "John Doe",
            "email" => "not-unique-test@email.com",
            "password_hash" => "password",
        ]));

        $this->assertTrue($user->create());

        $request = Faker::request();
        $request->setInput("email","not-unique-test@email.com");
        $request->setInput("full_name","John Doe");
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "email" => ["!unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_validate_rule_not_unique_failing($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        $this->test_model_migration($driver,$config);

        $request = Faker::request();
        $request->setInput("email","not-unique-test@email.com");
        $request->setInput("full_name","John Doe");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "email" => ["!unique:TestUser"]
        ]));
    }

    #[DataProvider('databaseDriverProvider')]
    public function test_model_migration($driver,$config) : void
    {
        self::setupDatabase($driver, $config);
        self::generate_test_model();

        $output = CommandLine::execute("Migration make App/Models/TestUser");
        $this->assertEquals("Successfully performed database migration",$output);
    }

    public function test_nullable_passing() : void
    {
        $request = Faker::request();

        $request->setInput("full_name","John Doe");
        $request->setInput("last_name","");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "full_name" => ["min:8","max:255"],
            "last_name" => ["min:8","max:255","nullable"],
        ]));
    }

    public function test_nullable_failing() : void
    {
        $request = Faker::request();

        $request->setInput("full_name","John Doe");
        $request->setInput("last_name","1234");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "full_name" => ["min:8","max:255"],
            "last_name" => ["min:8","max:255","nullable"],
        ]));
    }

    public function test_message_translator(): void
    {
        $request = Faker::request();
        $request->setInput("min_test","John");
        $request->setInput("max_test","123456789123456789");
        $request->setInput("min_num_test",1);
        $request->setInput("max_num_test",10);
        $request->setInput("same_test","abc");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "min_test" => ["min:8","max:255"],
            "max_test" => ["max:16"],
            "min_num_test" => ["min_num:2"],
            "max_num_test" => ["max_num:5"],
            "same_test" => ["same:@min_test"]
        ]));

        $this->assertEquals("min_test must be at least 8 characters",$request->getValidationErrors()["min_test"]);
        $this->assertEquals("max_test may not be greater than 16 characters",$request->getValidationErrors()["max_test"]);

        $this->assertEquals("min_num_test must be greater than 2",$request->getValidationErrors()["min_num_test"]);
        $this->assertEquals("max_num_test may not be less than 5",$request->getValidationErrors()["max_num_test"]);

        $this->assertEquals("same_test and min_test must match",$request->getValidationErrors()["same_test"]);

    }

    public function test_error_message_overriding_local(): void
    {
        $request = Faker::request();
        $request->setInput("first_name","John");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(OverrideMessageRule::class));


        $this->assertEquals("Message Override!",$request->getValidationErrors()["first_name"]);
    }

    public function test_error_message_overriding_global(): void
    {
        $request = Faker::request();
        $request->setInput("first_name","John");
        $request->reInitializeRequestData();

        \Lucent\Facades\Rule::overrideMessage("min","Global override for :attribute with a min of :min");

        $this->assertFalse($request->validate([
            "first_name" => ["min:10","max:255"],
        ]));

        $this->assertEquals("Global override for first_name with a min of 10",$request->getValidationErrors()["first_name"]);
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


        $appPath = FileSystem::rootPath(). "/App";
        $modelPath = $appPath . DIRECTORY_SEPARATOR . "Models";

        if (!is_dir($modelPath)) {
            mkdir($modelPath, 0755, true);
        }

        file_put_contents(
            $modelPath.DIRECTORY_SEPARATOR.'TestUser.php',
            $modelContent
        );

    }


}