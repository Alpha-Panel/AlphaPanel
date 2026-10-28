<?php

namespace Tests\Unit;

use App\Services\Portainer\PortainerContainerClient;
use PHPUnit\Framework\TestCase;

class PortainerContainerNameFilterTest extends TestCase
{
    public function test_filter_matches_only_the_exact_container_name(): void
    {
        $pattern = '#'.PortainerContainerClient::exactNameFilter('frankenphp').'#';

        $this->assertSame(1, preg_match($pattern, '/frankenphp'));
        $this->assertSame(1, preg_match($pattern, 'frankenphp'));
        $this->assertSame(0, preg_match($pattern, '/fix-frankenphp'));
        $this->assertSame(0, preg_match($pattern, '/frankenphp-2'));
    }

    public function test_filter_escapes_regex_metacharacters(): void
    {
        $pattern = '#'.PortainerContainerClient::exactNameFilter('a.b').'#';

        $this->assertSame(1, preg_match($pattern, '/a.b'));
        $this->assertSame(0, preg_match($pattern, '/axb'));
    }
}
