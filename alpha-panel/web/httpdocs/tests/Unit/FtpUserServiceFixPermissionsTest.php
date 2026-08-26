<?php

namespace Tests\Unit;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\FtpUser;
use App\Services\FtpUserService;
use App\Services\Portainer\ExecResult;
use App\Services\PortainerService;
use Mockery;
use Tests\TestCase;

/**
 * `fixPermissions()` builds shell commands that run as root inside the PHP
 * containers, so the command strings and the container choice each get a test.
 * No database is touched — the ftpUser relation is set directly on the model.
 */
class FtpUserServiceFixPermissionsTest extends TestCase
{
    /** @var array<int, array{container: string, script: string}> */
    private array $calls = [];

    private function makeDomain(string $fqdn, ?string $ftpUsername, DomainType $type = DomainType::CaddyWebServer): Domain
    {
        $domain = new Domain(['fqdn' => $fqdn, 'type' => $type]);
        $domain->setRelation('ftpUser', $ftpUsername === null ? null : new FtpUser(['username' => $ftpUsername]));

        return $domain;
    }

    private function makeService(ExecResult $chownResult): FtpUserService
    {
        $this->calls = [];

        $portainer = Mockery::mock(PortainerService::class);
        $portainer->shouldReceive('execInContainer')
            ->andReturnUsing(function (string $container, array $command, int $timeout = 60) use ($chownResult): ExecResult {
                $this->calls[] = ['container' => $container, 'script' => $command[2]];

                return str_contains($command[2], 'chown') && str_contains($command[2], '-R')
                    ? $chownResult
                    : new ExecResult(0, '', '');
            });

        return new FtpUserService($portainer);
    }

    private function ok(): ExecResult
    {
        return new ExecResult(0, '', '');
    }

    public function test_it_throws_when_the_domain_has_no_ftp_user(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No FTP user exists for this domain.');

        $this->makeService($this->ok())->fixPermissions($this->makeDomain('example.com', null));
    }

    public function test_it_escapes_the_username_in_the_chown_command(): void
    {
        $service = $this->makeService($this->ok());

        $username = 'evil; rm -rf /';

        $service->fixPermissions($this->makeDomain('example.com', $username));

        $chown = collect($this->calls)->first(fn (array $call) => str_contains($call['script'], '-R'));

        $this->assertNotNull($chown);
        // escapeshellarg() quotes differently per platform; assert against its own output.
        $this->assertStringContainsString(escapeshellarg($username).':www-data', $chown['script']);
        $this->assertStringNotContainsString($username.':www-data', $chown['script']);
    }

    public function test_it_unlocks_and_relocks_the_user_ini_around_the_chown(): void
    {
        $service = $this->makeService($this->ok());

        $summary = $service->fixPermissions($this->makeDomain('example.com', 'examplecom'));

        $this->assertCount(3, $this->calls);
        $this->assertStringContainsString('chattr -i', $this->calls[0]['script']);
        $this->assertStringContainsString('-R', $this->calls[1]['script']);
        $this->assertStringContainsString('chattr +i', $this->calls[2]['script']);
        $this->assertStringContainsString('chmod 444', $this->calls[2]['script']);
        $this->assertSame('chown examplecom:www-data -R on /var/www/vhosts/example.com', $summary);
    }

    public function test_it_targets_php_code_server_for_apache_reverse_proxy_domains(): void
    {
        $service = $this->makeService($this->ok());

        $service->fixPermissions($this->makeDomain('example.com', 'examplecom', DomainType::ApacheReverseProxy));

        $this->assertSame(['php-code-server'], array_unique(array_column($this->calls, 'container')));
    }

    public function test_it_targets_frankenphp_for_other_domain_types(): void
    {
        $service = $this->makeService($this->ok());

        $service->fixPermissions($this->makeDomain('example.com', 'examplecom'));

        $this->assertSame(['frankenphp'], array_unique(array_column($this->calls, 'container')));
    }

    public function test_it_throws_the_stderr_of_a_failed_chown(): void
    {
        $service = $this->makeService(new ExecResult(1, '', 'chown: invalid user'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('chown: invalid user');

        $service->fixPermissions($this->makeDomain('example.com', 'examplecom'));
    }

    public function test_it_falls_back_to_a_generic_message_when_a_failed_chown_is_silent(): void
    {
        $service = $this->makeService(new ExecResult(1, '', ''));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown error.');

        $service->fixPermissions($this->makeDomain('example.com', 'examplecom'));
    }
}
