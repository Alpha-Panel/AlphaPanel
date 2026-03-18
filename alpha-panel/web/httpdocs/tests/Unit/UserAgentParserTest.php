<?php

namespace Tests\Unit;

use App\Helpers\UserAgentParser;
use PHPUnit\Framework\TestCase;

class UserAgentParserTest extends TestCase
{
    public function test_chrome_on_windows(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $this->assertSame('Chrome', $result['browser_name']);
        $this->assertSame('120.0.0.0', $result['browser_version']);
        $this->assertSame('Windows', $result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_firefox_on_linux(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0');

        $this->assertSame('Firefox', $result['browser_name']);
        $this->assertSame('121.0', $result['browser_version']);
        $this->assertSame('Linux', $result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_safari_on_macos(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15');

        $this->assertSame('Safari', $result['browser_name']);
        $this->assertSame('17.2', $result['browser_version']);
        $this->assertSame('macOS', $result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_chrome_on_android(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36');

        $this->assertSame('Chrome', $result['browser_name']);
        $this->assertSame('120.0.6099.144', $result['browser_version']);
        $this->assertSame('Android', $result['os_name']);
        $this->assertSame('mobile', $result['device_type']);
    }

    public function test_safari_on_iphone(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1');

        $this->assertSame('Safari', $result['browser_name']);
        $this->assertSame('17.2', $result['browser_version']);
        $this->assertSame('iOS', $result['os_name']);
        $this->assertSame('mobile', $result['device_type']);
    }

    public function test_edge_on_windows(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0');

        $this->assertSame('Edge', $result['browser_name']);
        $this->assertSame('120.0.0.0', $result['browser_version']);
        $this->assertSame('Windows', $result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_opera_on_windows(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0');

        $this->assertSame('Opera', $result['browser_name']);
        $this->assertSame('106.0.0.0', $result['browser_version']);
        $this->assertSame('Windows', $result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_safari_on_ipad(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1');

        $this->assertSame('Safari', $result['browser_name']);
        $this->assertSame('17.2', $result['browser_version']);
        $this->assertSame('iOS', $result['os_name']);
        $this->assertSame('tablet', $result['device_type']);
    }

    public function test_empty_string_returns_nulls(): void
    {
        $result = UserAgentParser::parse('');

        $this->assertNull($result['browser_name']);
        $this->assertNull($result['browser_version']);
        $this->assertNull($result['os_name']);
        $this->assertNull($result['device_type']);
    }

    public function test_whitespace_string_returns_nulls(): void
    {
        $result = UserAgentParser::parse('   ');

        $this->assertNull($result['browser_name']);
        $this->assertNull($result['browser_version']);
        $this->assertNull($result['os_name']);
        $this->assertNull($result['device_type']);
    }

    public function test_chrome_os(): void
    {
        $result = UserAgentParser::parse('Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $this->assertSame('Chrome', $result['browser_name']);
        $this->assertSame('Chrome OS', $result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }

    public function test_unknown_browser_returns_null(): void
    {
        $result = UserAgentParser::parse('SomeCustomBot/1.0');

        $this->assertNull($result['browser_name']);
        $this->assertNull($result['browser_version']);
        $this->assertNull($result['os_name']);
        $this->assertSame('desktop', $result['device_type']);
    }
}
