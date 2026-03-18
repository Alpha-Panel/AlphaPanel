<?php

namespace App\Helpers;

class UserAgentParser
{
    /**
     * Parse a User-Agent string into browser, OS, and device information.
     *
     * @return array{browser_name: ?string, browser_version: ?string, os_name: ?string, device_type: ?string}
     */
    public static function parse(string $userAgent): array
    {
        if (trim($userAgent) === '') {
            return [
                'browser_name' => null,
                'browser_version' => null,
                'os_name' => null,
                'device_type' => null,
            ];
        }

        return [
            'browser_name' => self::detectBrowserName($userAgent),
            'browser_version' => self::detectBrowserVersion($userAgent),
            'os_name' => self::detectOs($userAgent),
            'device_type' => self::detectDeviceType($userAgent),
        ];
    }

    private static function detectBrowserName(string $ua): ?string
    {
        if (str_contains($ua, 'Edg/')) {
            return 'Edge';
        }

        if (str_contains($ua, 'OPR/')) {
            return 'Opera';
        }

        if (str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Edg/') && ! str_contains($ua, 'OPR/')) {
            return 'Chrome';
        }

        if (str_contains($ua, 'Firefox/')) {
            return 'Firefox';
        }

        if (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') && ! str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Edg/') && ! str_contains($ua, 'OPR/')) {
            return 'Safari';
        }

        return null;
    }

    private static function detectBrowserVersion(string $ua): ?string
    {
        $patterns = [
            'Edg/' => '/Edg\/([\d.]+)/',
            'OPR/' => '/OPR\/([\d.]+)/',
            'Firefox/' => '/Firefox\/([\d.]+)/',
        ];

        foreach ($patterns as $needle => $pattern) {
            if (str_contains($ua, $needle) && preg_match($pattern, $ua, $matches)) {
                return $matches[1];
            }
        }

        // Chrome must exclude Edge and Opera
        if (str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Edg/') && ! str_contains($ua, 'OPR/')) {
            if (preg_match('/Chrome\/([\d.]+)/', $ua, $matches)) {
                return $matches[1];
            }
        }

        // Safari must exclude Chrome, Edge, and Opera
        if (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') && ! str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Edg/') && ! str_contains($ua, 'OPR/')) {
            if (preg_match('/Version\/([\d.]+)/', $ua, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private static function detectOs(string $ua): ?string
    {
        if (str_contains($ua, 'Windows NT')) {
            return 'Windows';
        }

        // iOS must be checked before macOS because iOS UA strings contain "Mac OS X"
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod')) {
            return 'iOS';
        }

        if (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) {
            return 'macOS';
        }

        if (str_contains($ua, 'Android')) {
            return 'Android';
        }

        if (str_contains($ua, 'CrOS')) {
            return 'Chrome OS';
        }

        if (str_contains($ua, 'Linux')) {
            return 'Linux';
        }

        return null;
    }

    private static function detectDeviceType(string $ua): ?string
    {
        // Tablet must be checked before mobile because iPad UA strings contain "Mobile"
        if (str_contains($ua, 'iPad') || str_contains($ua, 'Tablet')) {
            return 'tablet';
        }

        if (str_contains($ua, 'Mobi') || str_contains($ua, 'iPhone') || str_contains($ua, 'iPod')) {
            return 'mobile';
        }

        return 'desktop';
    }
}
