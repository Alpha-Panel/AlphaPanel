<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAlertSettingRequest;
use App\Models\AlertSetting;
use App\Models\SystemAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class AlertSettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/SystemAlerts', [
            'settings' => AlertSetting::instance(),
            'alerts' => SystemAlert::query()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function update(UpdateAlertSettingRequest $request): RedirectResponse
    {
        AlertSetting::instance()->update($request->validated());

        return redirect()->route('settings.alerts.index')
            ->with('success', __('Alert settings updated successfully.'));
    }

    public function runCheck(): RedirectResponse
    {
        try {
            Artisan::call('system:check-health');
            $output = trim(Artisan::output());

            return redirect()->route('settings.alerts.index')
                ->with('success', __('Health check executed successfully.').($output ? " {$output}" : ''));
        } catch (\Throwable $e) {
            return redirect()->route('settings.alerts.index')
                ->with('error', __('Health check failed: :error', ['error' => $e->getMessage()]));
        }
    }
}
