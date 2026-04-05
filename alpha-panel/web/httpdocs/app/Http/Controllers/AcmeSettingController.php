<?php

namespace App\Http\Controllers;

use App\Models\AcmeSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcmeSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/AcmeSettings', [
            'settings' => AcmeSetting::instance(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'staging' => ['required', 'boolean'],
            'server_url' => ['required', 'url', 'max:500'],
            'staging_server_url' => ['required', 'url', 'max:500'],
            'key_type' => ['required', 'in:EC,RSA'],
            'key_length' => ['required', 'in:P-256,P-384,2048,4096'],
            'dns_propagation_wait' => ['required', 'integer', 'min:5', 'max:600'],
            'local_dns_wait' => ['required', 'integer', 'min:1', 'max:120'],
            'poll_timeout' => ['required', 'integer', 'min:30', 'max:900'],
            'webroot_path' => ['required', 'string', 'max:500'],
            'auto_renew_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        AcmeSetting::instance()->update($validated);

        return redirect()->route('settings.acme.index')
            ->with('success', __('ACME settings updated successfully.'));
    }
}
