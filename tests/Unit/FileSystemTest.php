<?php

namespace Unit;

use Lucent\Facades\Faker;
use Lucent\Filesystem\File;
use Lucent\Filesystem\FileSystemCollection;
use Lucent\Filesystem\Folder;
use PHPUnit\Framework\TestCase;

class FileSystemTest extends TestCase
{

    public function test_folder_get_files() :void
    {
        $target = 15;
        $i = 1;

        while($i <= $target){
            $file = new File("/storage/file_test/{$i}.txt","This is file {$i} of {$target}");
            $this->assertTrue($file->exists());
            $i++;
        }

        $folder = new Folder("/storage/file_test/");

        $this->assertTrue($folder->exists());
        $this->assertCount($target,$folder->getFiles());

        $this->assertTrue($folder->delete());
    }

    public function test_folder_delete() :void
    {
        $folder = new Folder("/storage/file_test/");

        $this->assertTrue($folder->create());

        $this->assertTrue($folder->exists());


        $this->assertTrue($folder->delete());

        $this->assertFalse($folder->exists());
    }

    public function test_folder_get_files_recursive() :void
    {
        $depth = 5;
        $target = 15;
        $i = 0;

        while($i < $depth) {
            $j = 0;
            while ($j < $target) {
                $file = new File("/storage/file_test/folder-{$i}/{$j}.txt", "This is file {$j} of {$target}");
                $this->assertTrue($file->exists());
                $j++;
            }
            $i++;
        }

        $folder = new Folder("/storage/file_test/");
        $this->assertTrue($folder->exists());
        $this->assertCount($target*$depth,$folder->getFiles(recursive: true));

        $this->assertTrue($folder->delete());
    }

    public function test_folder_get_files_by_extension() : void
    {
        $target = 16;
        $i = 1;

        while($i <= $target){
            if($i % 2 == 0){
                $file = new File("/storage/file_test/{$i}.txt","This is file {$i} of {$target}");
            }else{
                $file = new File("/storage/file_test/{$i}.php","<?php echo 'Hello from file {$i}';?>");
            }
            $this->assertTrue($file->exists());
            $i++;
        }

        $folder = new Folder("/storage/file_test/");
        $this->assertTrue($folder->exists());
        $this->assertCount($target/2,$folder->search()->extension("php")->collect());

        $this->assertTrue($folder->delete());
    }

    public function test_folder_get_files_by_wildcard() : void
    {
        $target = 16;
        $i = 1;

        while($i <= $target){
            if($i % 2 == 0){
                $file = new File("/storage/file_test/{$i}.temp.txt","This is file {$i} of {$target}");
            }else{
                $file = new File("/storage/file_test/{$i}.temp.php","<?php echo 'Hello from file {$i}';?>");
            }
            $this->assertTrue($file->exists());
            $i++;
        }

        $folder = new Folder("/storage/file_test/");
        $this->assertTrue($folder->exists());

        $files = $folder->search()->extension("txt")->pattern("*.temp.*")->collect();

        $this->assertCount(8,$files);

        $this->assertTrue($folder->delete());

    }

    public function test_folder_get_files_by_regex() : void
    {
        //Checks for even number
        $regex = '/^([0-9]*[02468])\.([a-zA-Z0-9]+)$/';

        $target = 8;
        $i = 1;

        while($i <= $target){

            $file = new File("/storage/file_test/{$i}.txt","This is file {$i} of {$target}");
            $this->assertTrue($file->exists());
            $i++;
        }

        $folder = new Folder("/storage/file_test/");

        $this->assertTrue($folder->exists());
        $this->assertCount(4,$folder->search()->pattern($regex,FileSystemCollection::PATTERN_REGEX)->collect());

        $this->assertTrue($folder->delete());
    }

    public function test_folder_exclude_only() : void
    {
        // Create simple text files, no double extensions
        $files = [
            'doc1.txt',
            'doc2.txt',
            'exclude_me.txt'
        ];

        foreach($files as $fileName){
            $file = new File("/storage/file_test/{$fileName}", "New file");
            $this->assertTrue($file->exists());
        }

        $folder = new Folder("/storage/file_test/");

        // Test exclusion only with wildcard
        $result = $folder->search()
            ->exclude('*exclude*', FileSystemCollection::PATTERN_WILDCARD)
            ->collect();

        $this->assertCount(2, $result);

        $fileNames = array_map(function($file) {
            return $file->getName();
        }, $result);

        $this->assertTrue(in_array('doc1.txt', $fileNames));
        $this->assertTrue(in_array('doc2.txt', $fileNames));
        $this->assertFalse(in_array('exclude_me.txt', $fileNames));

        $this->assertTrue($folder->delete());
    }

    public function test_folder_get_files_exclude_with_pattern() : void
    {
        $files = [
            'document1.txt',
            'document2.txt',
            'image1.jpg',
            'image2.jpg',
            'script1.php',
            'script2.php',
            'data123.csv',
            'data456.csv',
            'test_file.xml',
            'exclude_this.txt'
        ];

        foreach($files as $fileName){
            $file = new File("/storage/file_test/{$fileName}","New file");
            $this->assertTrue($file->exists());
        }

        $folder = new Folder("/storage/file_test/");

        $this->assertTrue($folder->exists());

        $result = $folder->search()
            ->pattern('/.*\d+\.txt$/', FileSystemCollection::PATTERN_REGEX)
            ->exclude('*exclude*', FileSystemCollection::PATTERN_WILDCARD)
            ->collect();

        // Should only find document1.txt and document2.txt (not exclude_this.txt)
        $this->assertCount(2, $result);

        $this->assertTrue($folder->delete());
    }

    public function test_folder_open_invalid() :void
    {
        $folder = new Folder("/storage/file_test_invalid/");
        $this->assertFalse($folder->exists());
    }

    public function test_folder_get_directory() : void
    {
        $folder = new Folder("/storage/file_test/");
        if(!$folder->exists()){
            $folder->create();
        }
        $this->assertTrue($folder->exists());
        $parent = $folder->getDirectory();
        $this->assertTrue($parent->exists());
        $this->assertEquals("storage",$parent->getName());
    }

    public function test_file_load_existing() :void
    {
        //Write prior to disk
        $file = new File("/storage/file_test/test.txt","This is a test file!");
        $this->assertTrue($file->exists());

        $file2 = new File("/storage/file_test/test.txt","This shouldn't write.");
        $this->assertTrue($file2->exists());

        $this->assertEquals("This is a test file!",$file2->getContents());

        $this->assertTrue($file2->delete());

    }

    public function test_file_get_directory() :void
    {

        $file = new File("/storage/myfile.txt","test file");

        $this->assertTrue($file->exists());

        $folder = $file->getDirectory();
        $this->assertTrue($folder->exists());
        var_dump("Runnign file search....");
        $this->assertCount(1,$folder->search()->extension("txt")->onlyFiles()->collect());

        $this->assertTrue($file->delete());
    }

    public function test_file_copy_no_destination_once(): void
    {

        $file = new File("/storage/copy/myfile.txt","jacks copy test!");
        $this->assertTrue($file->exists());

        $copy = $file->copy("myfile2.txt",$file->getDirectory());

        $this->assertNotNull($copy);

        $this->assertTrue($copy->exists());
        $this->assertEquals("myfile2.txt",$copy->getName());

        $this->assertTrue($copy->delete());
        $this->assertTrue($file->delete());
    }

    public function test_file_copy_no_destination_multiple(): void
    {

        $i = 0;
        $target = 5;

        $file = new File("/storage/copy/myfile.txt","jacks copy test!");

        while($i < $target){

            $copy = $file->copy("myfile Copy $i.txt",$file->getDirectory());

            $this->assertNotNull($copy);
            $this->assertTrue($copy->exists());

            $i++;
        }

        $this->assertCount(6,$file->getDirectory()->getFiles());
        $this->assertTrue($file->getDirectory()->delete());
    }

    public function test_file_copy_with_folder_as_string() : void
    {
        $file = new File("/storage/copy/myfile.txt","jacks copy test!");

        $this->assertTrue($file->exists());

        $newFolder = new Folder("/storage/copy2");

        if(!$newFolder->exists()){
            $newFolder->create();
        }

        $copy = $file->copy("myfile2.txt",$newFolder);

        $this->assertNotNull($copy);
        $this->assertTrue($copy->exists());

        $this->assertTrue($copy->delete());
        $this->assertTrue($file->delete());
    }

    public function test_copy_folder() : void
    {

        $files = Faker::files("/storage/html-project",5,
        ["extension"=>["html","css","js"]]);

        foreach($files as $file){
            $this->assertTrue($file->exists());
        }

        $count = count($files);

        $folder = new Folder("/storage/html-project");

        $this->assertTrue($folder->exists());

        $newFolder = new Folder("/storage/html-project-backup");

        if(!$newFolder->exists()){
            $newFolder->create();
        }

        $copy = $folder->copy($newFolder);

        $this->assertNotNull($copy);
        $this->assertTrue($copy);
        $this->assertCount($count,$newFolder->getFiles());

    }





}