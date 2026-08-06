<?php

namespace Tests\Unit;

use App\Rules\ArrayRule;
use App\Rules\CustomRegexRule;
use App\Rules\CustomRule;
use App\Rules\DynamicRule;
use App\Rules\NumRule;
use App\Rules\OverrideMessageRule;
use Lucent\Application;
use Lucent\Facades\Faker;
use Lucent\Facades\Regex;
use Tests\Support\FixtureLoader;
use Tests\Support\TestCase;

class RuleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Copy the rule stubs into temp_install/App/Rules/ so the App\ PSR-4
        // autoloader can resolve them.
        FixtureLoader::copyRule('TestRule.php');
        FixtureLoader::copyRule('NumRule.php');
        FixtureLoader::copyRule('OverrideMessageRule.php');
        FixtureLoader::copyRule('DynamicRule.php');
        FixtureLoader::copyRule('ArrayRule.php');
        FixtureLoader::copyRule('CustomRule.php');
        FixtureLoader::copyRule('CustomRegexRule.php');
    }

    public function test_num_rule_is_valid(): void
    {
        $rule = new NumRule();

        $test_data = Faker::request()->passing(NumRule::class)->all();

        $this->assertTrue($rule->validate_bool($test_data));
    }

    public function test_num_rule_is_not_valid(): void
    {
        $rule = new NumRule();

        $test_data = Faker::request()->failing(NumRule::class)->all();

        $this->assertFalse($rule->validate_bool($test_data));
    }

    public function test_dynamic_rule_passing(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", "Harris");
        $request->setInput("email", "notvalid.com");

        $rule = new DynamicRule($request->all());

        $this->assertTrue($request->validate($rule));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_dynamic_rule_failing(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "Jack");
        $request->setInput("last_name", 1);

        $rule = new DynamicRule($request->all());
        $this->assertFalse($request->validate($rule));
        $this->assertEquals(1, count($request->getValidationErrors()));

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

        $this->assertEquals("", $request->input("address"));
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
        $this->assertCount(1, $request->getValidationErrors());
    }

    public function test_custom_rule_passing(): void
    {

        $request = Faker::request();
        $request->setInput("post_code", "3934");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(CustomRule::class));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_custom_rule_failing(): void
    {

        $request = Faker::request();
        $request->setInput("post_code", "393");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(CustomRule::class));
        $this->assertCount(1, $request->getValidationErrors());

    }

    public function test_same_rule_passing(): void
    {
        $request = Faker::request();

        $request->setInput("password", "Pa55w0rd");
        $request->setInput("confirm_password", "Pa55w0rd");

        $request->reInitializeRequestData();

        $outcome = $request->validate([
            "password" => ["min:8", "max:8"],
            "confirm_password" => ["same:@password"],
        ]);

        $this->assertTrue($outcome);
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_same_rule_failing(): void
    {
        $request = Faker::request();

        $request->setInput("password", "Pa55w0rd");
        $request->setInput("confirm_password", "Pa55w0rd1");

        $request->reInitializeRequestData();

        $outcome = $request->validate([
            "password" => ["min:8", "max:8"],
            "confirm_password" => ["same:@password"],
        ]);

        $this->assertFalse($outcome);
        $this->assertCount(1, $request->getValidationErrors());
    }

    public function test_regex_email_passing(): void
    {
        $request = Faker::request();
        $request->setInput("email", "st_tuff@me.com");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(["email" => ["regex:email"]]));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_regex_email_failing(): void
    {
        $request = Faker::request();
        $request->setInput("email", "st_tuffme.com");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["email" => ["regex:email"]]));
        $this->assertCount(1, $request->getValidationErrors());
    }

    public function test_regex_password_passing(): void
    {
        $request = Faker::request();
        $request->setInput("password", "Password1");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(["password" => ["regex:password"]]));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_regex_password_failing(): void
    {
        $request = Faker::request();
        $request->setInput("password", "pass");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["password" => ["regex:password"]]));
        $this->assertCount(1, $request->getValidationErrors());
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
        $this->assertCount(1, $request->getValidationErrors());
    }

    public function test_custom_global_regex_rule_passing(): void
    {

        Application::getInstance();

        Regex::set("global_custom", '/^\S+\s+\S+$/');

        $request = Faker::request();
        $request->setInput("test", "abc 123");
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate(["test" => ["regex:global_custom"]]));
        $this->assertEmpty($request->getValidationErrors());
    }

    public function test_custom_global_regex_rule_failing(): void
    {

        Application::getInstance();

        Regex::set("global_custom", '/^\S+\s+\S+$/');

        $request = Faker::request();
        $request->setInput("test", "abc123");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["test" => ["regex:global_custom"]]));
        $this->assertCount(1, $request->getValidationErrors());
    }

    public function test_request_errors(): void
    {
        $request = Faker::request();

        $request->setInput("email", "testemail.com");
        $request->setInput("password", "pass");
        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(["email" => ["regex:email"], "password" => ["regex:password", "min:4"]]));
    }

    public function test_nullable_passing(): void
    {
        $request = Faker::request();

        $request->setInput("full_name", "John Doe");
        $request->setInput("last_name", "");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "full_name" => ["min:8", "max:255"],
            "last_name" => ["min:8", "max:255", "nullable"],
        ]));
    }

    public function test_nullable_failing(): void
    {
        $request = Faker::request();

        $request->setInput("full_name", "John Doe");
        $request->setInput("last_name", "1234");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "full_name" => ["min:8", "max:255"],
            "last_name" => ["min:8", "max:255", "nullable"],
        ]));
    }

    public function test_message_translator(): void
    {
        $request = Faker::request();

        $request->setInput("min_test", "John");
        $request->setInput("max_test", "123456789123456789");
        $request->setInput("min_num_test", 1);
        $request->setInput("max_num_test", 10);
        $request->setInput("same_test", "abc");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate([
            "min_test" => ["min:8", "max:255"],
            "max_test" => ["max:16"],
            "min_num_test" => ["min_num:2"],
            "max_num_test" => ["max_num:5"],
            "same_test" => ["same:@min_test"]
        ]));

        $this->assertEquals("min_test must be at least 8 characters", $request->getValidationErrors()["min_test"]);
        $this->assertEquals("max_test may not be greater than 16 characters", $request->getValidationErrors()["max_test"]);
        $this->assertEquals("min_num_test must be greater than 2", $request->getValidationErrors()["min_num_test"]);
        $this->assertEquals("max_num_test may not be less than 5", $request->getValidationErrors()["max_num_test"]);
        $this->assertEquals("same_test and min_test must match", $request->getValidationErrors()["same_test"]);
    }

    public function test_error_message_overriding_local(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "John");

        $request->reInitializeRequestData();

        $this->assertFalse($request->validate(OverrideMessageRule::class));

        $this->assertEquals("Message Override!", $request->getValidationErrors()["first_name"]);
    }

    public function test_error_message_overriding_global(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "John");
        $request->reInitializeRequestData();

        \Lucent\Facades\Rule::overrideMessage("min", "Global override for :attribute with a min of :min");

        $this->assertFalse($request->validate([
            "first_name" => ["min:10", "max:255"],
        ]));

        $this->assertEquals("Global override for first_name with a min of 10", $request->getValidationErrors()["first_name"]);
    }

    public function test_nullable_with_failing_value(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "John");
        $request->reInitializeRequestData();

        \Lucent\Facades\Rule::overrideMessage("min", ":attribute must be at least :min characters");

        $this->assertFalse($request->validate([
            "first_name" => ["min:10", "max:255", "nullable"],
        ]));

        $this->assertEquals("first_name must be at least 10 characters", $request->getValidationErrors()["first_name"]);

    }

    public function test_nullable_with_passing_value(): void
    {
        $request = Faker::request();
        $request->setInput("first_name", "John Smith");

        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "first_name" => ["min:10", "max:255", "nullable"],
        ]));

    }

    public function test_nullable_with_null(): void
    {
        $request = Faker::request();
        $request->reInitializeRequestData();

        $this->assertTrue($request->validate([
            "first_name" => ["min:10", "max:255", "nullable"],
        ]));
    }

    public function test_array_with_invalid_dataset(): void
    {
        $request = Faker::request();

        $request->setInput("values", ["f1rstname"=>"John","l2stname"=>"Smith"]);

        $this->assertFalse($request->validate(ArrayRule::class));
    }


    public function test_array_with_valid_dataset(): void
    {
        $request = Faker::request();

        $request->setInput("values", ["first_name"=>"John","last_name"=>"Smith"]);

        $this->assertTrue($request->validate(ArrayRule::class));
    }

    public function test_array_with_mixed_dataset(): void
    {
        $request = Faker::request();

        $request->setInput("values", ["first_name"=>"John","last_name"=>"Smith","username"=>"John Smith"]);

        $this->assertFalse($request->validate(ArrayRule::class));
    }

}