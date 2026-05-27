<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\MailAliasApiController;
use App\Http\Controllers\Api\V1\MailboxApiController;
use App\Http\Controllers\Api\V1\MailDomainApiController;
use App\Http\Controllers\Api\V1\MailSettingsApiController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Route-registration smoke tests for the Mail API surface.
 *
 * Intentionally does NOT use RefreshDatabase — per project CLAUDE.md
 * destructive database traits are forbidden. These tests only assert
 * route definitions and the unauthenticated 401 boundary, which exercise
 * Sanctum's null-token short circuit before any DB lookup.
 */
class MailApiRoutesTest extends TestCase
{
    /**
     * @return array<int, array{0:string, 1:string}>
     */
    public static function mailRoutesProvider(): array
    {
        return [
            ['GET', 'api/mail/settings'],
            ['PUT', 'api/mail/settings/relay'],
            ['PUT', 'api/mail/settings/zimbra'],
            ['POST', 'api/mail/settings/zimbra/test'],
            ['GET', 'api/mail/domains'],
            ['GET', 'api/domains/{domain}/mail'],
            ['GET', 'api/domains/{domain}/mail/mailboxes'],
            ['POST', 'api/domains/{domain}/mail/mailboxes'],
            ['GET', 'api/domains/{domain}/mail/mailboxes/{local}'],
            ['PUT', 'api/domains/{domain}/mail/mailboxes/{local}'],
            ['DELETE', 'api/domains/{domain}/mail/mailboxes/{local}'],
            ['POST', 'api/domains/{domain}/mail/mailboxes/{local}/password'],
            ['POST', 'api/domains/{domain}/mail/mailboxes/{local}/forwarding'],
            ['GET', 'api/domains/{domain}/mail/aliases'],
            ['POST', 'api/domains/{domain}/mail/aliases'],
            ['DELETE', 'api/domains/{domain}/mail/aliases/{local}'],
        ];
    }

    #[DataProvider('mailRoutesProvider')]
    public function test_mail_route_is_registered(string $method, string $uri): void
    {
        $matched = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => in_array($method, $r->methods(), true) && $r->uri() === $uri);

        $this->assertNotNull($matched, "Route {$method} /{$uri} not registered.");
    }

    #[DataProvider('mailRoutesProvider')]
    public function test_mail_route_requires_authentication(string $method, string $uri): void
    {
        $url = '/'.str_replace(['{domain}', '{local}'], ['1', 'info'], $uri);

        $this->json($method, $url)->assertStatus(401);
    }

    public function test_mail_settings_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(MailSettingsApiController::class));
        $this->assertTrue(class_exists(MailboxApiController::class));
        $this->assertTrue(class_exists(MailAliasApiController::class));
        $this->assertTrue(class_exists(MailDomainApiController::class));
    }
}
