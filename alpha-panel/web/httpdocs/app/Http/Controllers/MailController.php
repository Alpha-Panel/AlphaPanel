<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MailDomain;
use App\Services\MailcowApiService;
use Inertia\Inertia;
use Inertia\Response;

class MailController extends Controller
{
    public function __construct(
        private MailcowApiService $mailcowApi,
    ) {}

    public function index(): Response
    {
        $mailDomains = MailDomain::query()
            ->with(['domain:id,fqdn,status', 'mailboxes', 'aliases'])
            ->withCount(['mailboxes', 'aliases'])
            ->latest()
            ->get();

        return Inertia::render('Mail/Index', [
            'mailDomains' => $mailDomains,
            'mailcowEnabled' => config('panel.mailcow.enabled'),
            'webmailUrl' => 'https://'.config('panel.mailcow.webmail_domain'),
        ]);
    }

    public function settings(): Response
    {
        $connected = false;

        try {
            $connected = $this->mailcowApi->testConnection();
        } catch (\Throwable) {
        }

        return Inertia::render('Mail/Settings', [
            'connected' => $connected,
            'hostname' => config('panel.mailcow.hostname'),
            'webmailDomain' => config('panel.mailcow.webmail_domain'),
        ]);
    }
}
