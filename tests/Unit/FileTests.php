<?php

namespace Unit;

use Lucent\Facades\File;
use PHPUnit\Framework\TestCase;

class FileTests extends TestCase
{

    public function test_get_files() :void
    {
        $target = 15;
        $i = 1;

        while($i <= $target){
            $this->assertNotNull(File::create("/storage/file_test/{$i}.txt","This is file {$i} of {$target}"));
            $i++;
        }

        $items = File::getFiles("/storage/file_test");

        $this->assertCount($target,$items);
    }

    public function test_create_file() :void
    {

        $file = File::create("/storage/myfile.txt");

        $this->assertNotNull($file);
    }

    public function test_file_renaming() :void
    {
        $file = File::create("/storage/myfile.txt","test");

        $this->assertNotNull($file);

        $this->assertTrue($file->rename("/storage/myfile2.txt"));

        $this->assertNotNull(File::get("/storage/myfile2.txt"));
        $this->assertNull(File::get("/storage/myfile.txt"));
    }

    public function test_file_delete() :void
    {
        $file = File::create("/storage/delete_test.txt","delete test");
        $this->assertNotNull($file);
        $this->assertTrue($file->delete());
        $this->assertNull(File::get("/storage/delete_test.txt"));
    }

    public function test_file_move() :void
    {
        $file = File::create("/storage/move_test.txt","test");
        $this->assertNotNull($file);
        $this->assertTrue($file->move("/storage/abc/move_test.txt"));
    }

    public function test_file_copy(): void
    {
        $file = File::create("/storage/copy_test.txt","This is a copy of my data");
        $this->assertNotNull($file);
        $this->assertTrue($file->copy("/storage/copy_test2.txt"));

        $newFile = File::get("/storage/copy_test2.txt");

        $this->assertNotNull($newFile);
        $this->assertEquals("This is a copy of my data", $newFile->getContents());
    }

    public function test_file_get_size() :void
    {
        $file = File::create("/storage/file_size_test.txt","123456789");
        $this->assertNotNull($file);
        $this->assertEquals(9, $file->getSize());
    }

    public function test_file_append() :void
    {
        $file = File::create("/storage/file_append_test.txt","row 1\n");
        $this->assertNotNull($file);
        $this->assertTrue($file->append("row 2"));

        $file2 = File::get("/storage/file_append_test.txt");
        $this->assertNotNull($file2);
        $this->assertEquals("row 1\nrow 2", $file2->getContents());

    }

}