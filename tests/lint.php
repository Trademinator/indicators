<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
$count = 0;
foreach (['src','tests','examples'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;
        $count++;
        passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()), $status);
        $failed = $failed || $status !== 0;
    }
}
echo $count." PHP files checked.\n";
exit($failed ? 1 : 0);
