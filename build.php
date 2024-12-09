<?php

// build.php
$pharFile = 'lucent.phar';

// Clean up existing PHAR
if (file_exists($pharFile)) {
    unlink($pharFile);
}

// Create new PHAR
$phar = new Phar($pharFile);

// Start adding files
$phar->buildFromDirectory(__DIR__.DIRECTORY_SEPARATOR."src", '/\.(php|env)$/');

// Create custom stub
$stub = <<<'EOF'
<?php
Phar::mapPhar();

// Load the framework's autoloader and program
require 'phar://' . __FILE__ . '/boostrap.php';

__HALT_COMPILER();
EOF;

// Set the stub
$phar->setStub($stub);