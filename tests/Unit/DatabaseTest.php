<?php

namespace Unit;

use Exception;
use Lucent\Application;
use Lucent\Database;
use Lucent\Facades\File;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
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
    }


    public function test_sqlite_driver(){

        $query = 'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )';

        try {
            Database::statement($query);
            echo "Database connection successful!";
        } catch (Exception $e) {
            $this->fail($e->getMessage());
        }
        $query ="SELECT name FROM sqlite_master WHERE type='table' AND name = 'users'";

        $result = Database::select($query,false);

        $this->assertEquals('users',$result['name']);
    }

    public function test_statement_create_table_success() : void
    {
        $query = 'CREATE TABLE IF NOT EXISTS test_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
        )';
        $this->assertTrue(Database::statement($query));
    }

    public function test_statement_failed_select_all() : void
    {
        $this->test_statement_create_table_success();
        $query = 'SELECT * FROM test_users';
        try {
            Database::statement($query);
        }catch (Exception $e){
            $this->assertEquals("Invalid statement, SELECT * FROM test_users is not allowed to execute.",$e->getMessage());
        }
    }

    public function test_statement_insert_success() : void
    {

        $this->test_statement_create_table_success();

        $query = 'INSERT INTO test_users (name) VALUES ("Homer Simpson")';

        $this->assertTrue(Database::insert($query));

    }



}