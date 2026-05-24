<?php

namespace App\Services\Mail;

use App\Enums\MailHosting;
use App\Models\Domain;
use App\Services\Mail\Contracts\MailProviderInterface;
use App\Services\Mail\Exceptions\MailHostingDisabledException;
use App\Services\Mail\Providers\MailuProvider;
use App\Services\Mail\Providers\RemoteProvider;
use App\Services\Mail\Providers\ZimbraProvider;
use Illuminate\Contracts\Container\Container;

class MailProviderResolver
{
    public function __construct(private readonly Container $container) {}

    public function for(Domain $domain): MailProviderInterface
    {
        return match ($domain->mail_hosting) {
            MailHosting::Local => $this->container->make(MailuProvider::class),
            MailHosting::Zimbra => $this->container->make(ZimbraProvider::class),
            MailHosting::Remote => $this->container->make(RemoteProvider::class),
            default => throw new MailHostingDisabledException(
                "Domain {$domain->fqdn} has no mail hosting enabled."
            ),
        };
    }

    public function tryFor(Domain $domain): ?MailProviderInterface
    {
        try {
            return $this->for($domain);
        } catch (MailHostingDisabledException) {
            return null;
        }
    }
}
