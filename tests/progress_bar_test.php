#!/usr/bin/env php
<?php

ob_implicit_flush(true);
if (ob_get_level() > 0) {
    ob_end_flush();
}

require_once '/Users/jackharris/PhpstormProjects/Lucent/temp_install/packages/lucent.phar';

use Lucent\Commandline\Components\ProgressBar;

$steps    = 100;
$duration = 10; // seconds
$sleep    = (int)(($duration / $steps) * 1_000_000); // microseconds per step

echo "Progress bar test — {$steps} steps over {$duration} seconds\n\n";

$bar = new ProgressBar($steps);
$bar->setFormat('[{bar}] {percent}% ({current}/{total}) - {elapsed} elapsed - {eta} remaining');

for ($i = 1; $i <= $steps; $i++) {
    usleep($sleep);
    $bar->advance();
}

$bar->finish();

echo "\nDone!\n";