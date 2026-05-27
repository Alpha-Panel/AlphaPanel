<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Mail\UpdateRelayApiRequest;
use App\Http\Requests\Api\V1\Mail\UpdateZimbraApiRequest;
use App\Models\AuditLog;
use App\Services\Mail\Exceptions\MailProviderException;
use App\Services\Mail\MailSettingsService;
use App\Services\Mail\Zimbra\ZimbraSoapClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailSettingsApiController extends ApiController
{
    public function __construct(
        private readonly MailSettingsService $settings,
        private readonly ZimbraSoapClient $zimbra,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json([
            'features' => [
                'mail' => $this->settings->mailEnabled(),
                'mailu' => $this->settings->mailuEnabled(),
                'zimbra' => $this->settings->zimbraEnabled(),
            ],
            'relay' => $this->settings->relayConfig(),
            'zimbra' => $this->settings->zimbraConfig(),
        ]);
    }

    public function updateRelay(UpdateRelayApiRequest $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validated();
        $this->settings->updateRelay($data);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_relay_updated',
            'summary' => 'Mailu relay '.($data['host'] ?? 'cleared'),
        ]);

        return response()->json(['ok' => true]);
    }

    public function updateZimbra(UpdateZimbraApiRequest $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validated();
        $this->settings->updateZimbra($data);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_zimbra_updated',
            'summary' => ($data['enabled'] ?? false ? 'enabled' : 'disabled').' '.($data['admin_url'] ?? ''),
        ]);

        return response()->json([
            'ok' => true,
            'zimbra' => $this->settings->zimbraConfig(),
        ]);
    }

    public function testZimbra(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        try {
            $this->zimbra->refreshToken();
            $this->zimbra->authenticate();
        } catch (MailProviderException $e) {
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'mail_zimbra_test_failed',
                'summary' => $this->mapZimbraError($e),
                'details' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'status' => $this->mapZimbraError($e),
                'message' => $e->getMessage(),
            ], 422);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'mail_zimbra_tested',
            'summary' => 'auth_ok',
        ]);

        return response()->json(['ok' => true, 'status' => 'auth_ok']);
    }

    private function ensureAdmin(Request $request): void
    {
        if (! $request->user()->isAdmin()) {
            abort(403, 'Admin access required.');
        }
    }

    private function mapZimbraError(MailProviderException $e): string
    {
        return match ((new \ReflectionClass($e))->getShortName()) {
            'ZimbraAuthException' => 'auth_failed',
            'ZimbraConnectionException' => 'unreachable',
            'ZimbraSoapFaultException' => 'soap_fault',
            default => 'unknown',
        };
    }
}
