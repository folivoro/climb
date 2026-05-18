<?php

declare(strict_types=1);

uses(Tests\TestCase::class)->in('Feature');

// -------------------------------------------------------------------------
// Global helpers
// -------------------------------------------------------------------------

/**
 * Recursively remove a temporary test directory.
 */
function removeTmpDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($dir);
}
