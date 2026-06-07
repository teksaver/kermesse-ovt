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

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $file) {
                if ($file->isLink() || $file->isFile()) {
                    if (!unlink($file->getPathname()) && file_exists($file->getPathname())) {
                        chmod($file->getPathname(), 0777);
                        unlink($file->getPathname());
                    }
                } elseif ($file->isDir()) {
                    if (!rmdir($file->getPathname()) && is_dir($file->getPathname())) {
                        chmod($file->getPathname(), 0777);
                        rmdir($file->getPathname());
                    }
                }
            }

            if (is_dir($dir)) {
                if (!rmdir($dir)) {
                    chmod($dir, 0777);
                    rmdir($dir);
                }
            }
        } catch (\UnexpectedValueException $e) {
            // Ignore access denied errors during teardown
        } catch (\Exception $e) {
            // Ignore other filesystem errors during teardown
        }
    }
}
