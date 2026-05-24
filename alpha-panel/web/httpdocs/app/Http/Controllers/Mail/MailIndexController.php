<?php

namespace App\Http\Controllers\Mail;

use App\Enums\MailHosting;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailIndexController extends Controller
{
    public function __construct(private readonly MailSettingsService $settings) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $domains = Domain::query()
            ->whereIn('mail_hosting', [
                MailHosting::Local->value,
                MailHosting::Zimbra->value,
                MailHosting::Remote->value,
            ])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('owner_user_id', $user->id))
            ->orderBy('fqdn')
            ->get(['id', 'fqdn', 'mail_hosting', 'mail_remote_mx_host', 'mail_remote_mx_priority']);

        return Inertia::render('Mail/Index', [
            'domains' => $domains->map(fn (Domain $d) => [
                'id' => $d->id,
                'fqdn' => $d->fqdn,
                'mail_hosting' => $d->mail_hosting->value,
                'mail_hosting_label' => $d->mail_hosting->shortLabel(),
                'mail_remote_mx_host' => $d->mail_remote_mx_host,
                'mail_remote_mx_priority' => $d->mail_remote_mx_priority,
            ]),
            'features' => [
                'mailu' => $this->settings->mailuEnabled(),
                'zimbra' => $this->settings->zimbraEnabled(),
            ],
        ]);
    }
}
