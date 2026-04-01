<?php

namespace App\Http\Controllers;

use App\Models\DnsSetting;
use App\Models\DnsTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DnsSettingController extends Controller
{
    public function index(): Response
    {
        $settings = DnsSetting::instance();
        $templates = DnsTemplate::query()->orderBy('name')->get(['id', 'name', 'is_default']);

        return Inertia::render('Settings/DnsSettings', [
            'settings' => $settings,
            'templates' => $templates,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ns1' => ['required', 'string', 'max:255'],
            'ns2' => ['required', 'string', 'max:255'],
            'ns3' => ['nullable', 'string', 'max:255'],
            'ns4' => ['nullable', 'string', 'max:255'],
            'default_ip' => ['nullable', 'string', 'ip'],
            'soa_admin_email' => ['required', 'string', 'max:255'],
            'soa_refresh' => ['required', 'integer', 'min:300', 'max:86400'],
            'soa_retry' => ['required', 'integer', 'min:60', 'max:86400'],
            'soa_expire' => ['required', 'integer', 'min:86400', 'max:2419200'],
            'soa_minimum_ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'default_ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'default_template_id' => ['nullable', 'exists:dns_templates,id'],
        ]);

        DnsSetting::instance()->update($validated);

        return redirect()->route('settings.dns.index')
            ->with('success', __('DNS settings updated successfully.'));
    }
}
