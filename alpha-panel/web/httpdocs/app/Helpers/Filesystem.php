<?php

namespace App\Helpers;

class Filesystem
{
    /**
     * Remove a directory and all of its contents recursively.
     *
     * Silently no-ops if the directory does not exist. Failures on individual
     * unlink/rmdir calls are suppressed because typical callers (cleanup of
     * temporary backup staging dirs) cannot meaningfully recover from them.
     */
    public static function removeDirectoryRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = glob("{$dir}/*") ?: [];

        foreach ($entries as $item) {
            if (is_dir($item)) {
                self::removeDirectoryRecursive($item);
            } else {
                @unlink($item);
            }
        }

        @rmdir($dir);
    }
}
