<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebmailCompatRedirectTest extends TestCase
{
    public function test_sso_redirect_stays_on_panel_webmail_proxy_with_raw_query(): void
    {
        $this->get('/mail/index.php?sso&hash=abc123')
            ->assertRedirect(url('/mail/webmail/index.php?sso&hash=abc123'));
    }

    public function test_redirect_without_query_string(): void
    {
        $this->get('/mail/index.php')
            ->assertRedirect(url('/mail/webmail/index.php'));
    }
}
