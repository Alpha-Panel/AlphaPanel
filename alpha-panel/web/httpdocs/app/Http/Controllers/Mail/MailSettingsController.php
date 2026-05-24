<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\UpdateRelaySettingsRequest;
use App\Http\Requests\Mail\UpdateZimbraSettingsRequest;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\MailSettingsService;
use App\Services\Mail\Zimbra\ZimbraSoapClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MailSettingsController extends Controller
{
    public function __construct(
        private readonly MailSettingsService $settings,
        private readonly ZimbraSoapClient $zimbra,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Mail/Settings/Index', [
            'features' => [
                'mailu' => $this->settings->mailuEnabled(),
                'zimbra' => $this->settings->zimbraEnabled(),
            ],
            'relay' => $this->settings->relayConfig(),
            'zimbra' => $this->settings->zimbraConfig(),
        ]);
    }

    public function updateRelay(UpdateRelaySettingsRequest $request): RedirectResponse
    {
        $this->settings->updateRelay($request->validated());

        return back()->with('success', __('Mail relay updated. Restart Mailu to apply.'));
    }

    public function updateZimbra(UpdateZimbraSettingsRequest $request): RedirectResponse
    {
        $this->settings->updateZimbra($request->validated());

        return back()->with('success', __('Zimbra server saved.'));
    }

    public function testZimbra(): JsonResponse
    {
        try {
            $this->zimbra->refreshToken();
            $this->zimbra->authenticate();
        } catch (MailProviderException $e) {
            return response()->json([
                'ok' => false,
                'status' => $this->mapZimbraError($e),
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['ok' => true, 'status' => 'auth_ok']);
    }

    private function mapZimbraError(MailProviderException $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();

        return match ($class) {
            'ZimbraAuthException' => 'auth_failed',
            'ZimbraConnectionException' => 'unreachable',
            'ZimbraSoapFaultException' => 'soap_fault',
            default => 'unknown',
        };
    }
}
