<?php

namespace App\Helpers;

class UploadLimits
{
    /**
     * Effective PHP upload size in bytes (min of upload_max_filesize and post_max_size).
     */
    public static function phpMaxUploadBytes(): int
    {
        return min(
            self::parseSize(ini_get('upload_max_filesize') ?: '2M'),
            self::parseSize(ini_get('post_max_size') ?: '8M'),
        );
    }

    /**
     * Parse a php.ini-style size shorthand (e.g. "8M", "1G", "512K") into bytes.
     */
    public static function parseSize(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $last = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;

        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
