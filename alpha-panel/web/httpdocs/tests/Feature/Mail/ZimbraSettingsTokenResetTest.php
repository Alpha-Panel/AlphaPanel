<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\MailSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ZimbraSettingsTokenResetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_saving_zimbra_settings_drops_cached_admin_token(): void
    {
        Cache::put('mail.zimbra.token', 'token-of-old-admin', 60);

        app(MailSettingsService::class)->updateZimbra([
            'enabled' => true,
            'admin_url' => 'https://zimbra.test:7071/service/admin/soap',
            'admin_user' => 'panel-admin@panel.test',
            'admin_password' => 'secret',
        ]);

        $this->assertNull(Cache::get('mail.zimbra.token'));
    }
}
