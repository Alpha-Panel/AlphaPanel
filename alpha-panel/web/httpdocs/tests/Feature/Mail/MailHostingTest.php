<?php

namespace Tests\Feature\Mail;

use App\Enums\MailHosting;
use App\Models\Domain;
use App\Services\Mail\Exceptions\MailHostingDisabledException;
use App\Services\Mail\Exceptions\UnsupportedMailOperationException;
use App\Services\Mail\MailProviderResolver;
use App\Services\Mail\Providers\MailuProvider;
use App\Services\Mail\Providers\RemoteProvider;
use App\Services\Mail\Providers\ZimbraProvider;
use Tests\TestCase;

class MailHostingTest extends TestCase
{
    public function test_enum_labels(): void
    {
        $this->assertSame('Mailu', MailHosting::Local->shortLabel());
        $this->assertSame('Zimbra', MailHosting::Zimbra->shortLabel());
        $this->assertTrue(MailHosting::Local->isManaged());
        $this->assertTrue(MailHosting::Zimbra->isManaged());
        $this->assertFalse(MailHosting::Remote->isManaged());
        $this->assertFalse(MailHosting::Disabled->isManaged());
    }

    public function test_resolver_routes_to_correct_provider(): void
    {
        $resolver = app(MailProviderResolver::class);

        $local = new Domain(['fqdn' => 'a.test', 'mail_hosting' => MailHosting::Local->value]);
        $local->setRawAttributes($local->getAttributes() + ['mail_hosting' => MailHosting::Local->value]);
        $local->syncOriginal();
        // re-hydrate the cast
        $local->mail_hosting = MailHosting::Local;
        $this->assertInstanceOf(MailuProvider::class, $resolver->for($local));

        $remote = new Domain(['fqdn' => 'b.test']);
        $remote->mail_hosting = MailHosting::Remote;
        $this->assertInstanceOf(RemoteProvider::class, $resolver->for($remote));

        $zimbra = new Domain(['fqdn' => 'c.test']);
        $zimbra->mail_hosting = MailHosting::Zimbra;
        $this->assertInstanceOf(ZimbraProvider::class, $resolver->for($zimbra));
    }

    public function test_resolver_throws_when_disabled(): void
    {
        $resolver = app(MailProviderResolver::class);
        $d = new Domain(['fqdn' => 'd.test']);
        $d->mail_hosting = MailHosting::Disabled;

        $this->expectException(MailHostingDisabledException::class);
        $resolver->for($d);
    }

    public function test_remote_provider_rejects_mailbox_ops(): void
    {
        $provider = app(RemoteProvider::class);
        $d = new Domain(['fqdn' => 'e.test']);
        $d->mail_hosting = MailHosting::Remote;

        $this->expectException(UnsupportedMailOperationException::class);
        $provider->createMailbox($d, 'user', 'password123');
    }

    public function test_remote_provider_emits_mx_hint(): void
    {
        $provider = app(RemoteProvider::class);
        $d = new Domain([
            'fqdn' => 'f.test',
            'mail_remote_mx_host' => 'mx.partner.example',
            'mail_remote_mx_priority' => 20,
        ]);
        $d->mail_hosting = MailHosting::Remote;

        $hints = $provider->getDnsHints($d);

        $this->assertCount(1, $hints->mx);
        $this->assertSame(20, $hints->mx[0]['priority']);
        $this->assertSame('mx.partner.example', $hints->mx[0]['content']);
    }
}
