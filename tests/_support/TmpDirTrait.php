<?php

/**
 * Shared helper for tests that create temporary directories.
 *
 * @internal
 */
trait TmpDirTrait
{
    /**
     * Recursively remove a directory and all its contents.
     * Safe to call on a path that does not exist.
     */
    protected function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            if ($file->isLink() || $file->isFile()) {
                @unlink($file->getPathname());
            } elseif ($file->isDir()) {
                @rmdir($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}
