<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoginIpRuleRequest;
use App\Models\LoginIpRule;
use App\Models\SecuritySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LoginIpFilterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Security/LoginIpFilter', [
            'mode' => SecuritySetting::instance()->ip_filter_mode,
            'rules' => LoginIpRule::with('creator:id,name')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function updateMode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip_filter_mode' => ['required', 'string', 'in:off,whitelist,blacklist'],
        ]);

        SecuritySetting::instance()->update($validated);

        return redirect()->route('settings.security.login-ip-filter.index')
            ->with('success', __('IP filter mode updated successfully.'));
    }

    public function store(StoreLoginIpRuleRequest $request): RedirectResponse
    {
        LoginIpRule::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('settings.security.login-ip-filter.index')
            ->with('success', __('IP rule added successfully.'));
    }

    public function destroy(LoginIpRule $loginIpRule): RedirectResponse
    {
        $loginIpRule->delete();

        return redirect()->route('settings.security.login-ip-filter.index')
            ->with('success', __('IP rule deleted successfully.'));
    }
}
