<?php

namespace Tests\Unit;

use App\Services\VhostFileManagerService;
use PHPUnit\Framework\TestCase;

class VhostFileManagerServiceTest extends TestCase
{
    private const HOMES = ['example.com', 'example.com/subdomains/shop', 'other.org'];

    public function test_root_and_unowned_paths_match_no_home(): void
    {
        $this->assertNull(VhostFileManagerService::matchHome('', self::HOMES));
        $this->assertNull(VhostFileManagerService::matchHome('orphan-dir/file.txt', self::HOMES));
    }

    public function test_home_itself_and_children_match_their_home(): void
    {
        $this->assertSame('example.com', VhostFileManagerService::matchHome('example.com', self::HOMES));
        $this->assertSame('other.org', VhostFileManagerService::matchHome('other.org/httpdocs/index.php', self::HOMES));
    }

    public function test_longest_home_wins_for_nested_subdomain(): void
    {
        $this->assertSame(
            'example.com/subdomains/shop',
            VhostFileManagerService::matchHome('example.com/subdomains/shop/httpdocs', self::HOMES),
        );
        $this->assertSame('example.com', VhostFileManagerService::matchHome('example.com/subdomains/blog', self::HOMES));
    }

    public function test_sibling_with_shared_prefix_does_not_match(): void
    {
        $this->assertNull(VhostFileManagerService::matchHome('example.community/httpdocs', self::HOMES));
    }

    public function test_normalize_path_strips_traversal_segments(): void
    {
        $service = new VhostFileManagerService('/var/www/vhosts');

        $this->assertSame('example.com/etc', $service->normalizePath('../example.com/../../etc'));
        $this->assertSame('a/b', $service->normalizePath('\\a//./b/'));
    }
}
