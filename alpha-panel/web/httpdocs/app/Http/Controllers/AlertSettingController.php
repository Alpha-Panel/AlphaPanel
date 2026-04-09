<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAlertSettingRequest;
use App\Models\AlertSetting;
use App\Models\SystemAlert;
use Illuminate\Http\RedirectResponse;
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
}
