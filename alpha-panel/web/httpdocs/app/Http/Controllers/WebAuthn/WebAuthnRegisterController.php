<?php

namespace App\Http\Controllers\WebAuthn;

use App\Models\AuditLog;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

use function response;

class WebAuthnRegisterController
{
    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(AttestationRequest $request): Responsable
    {
        return $request
            ->fastRegistration()
//            ->userless()
//            ->allowDuplicates()
            ->toCreate();
    }

    /**
     * Registers a device for further WebAuthn authentication.
     */
    public function register(AttestedRequest $request): Response
    {
        $name = trim((string) $request->input('name', ''));

        $request->save($name !== '' ? ['name' => $name] : []);

        $user = $request->user();
        $user->otp = true;
        $user->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'webauthn_registered',
            'summary' => $name !== ''
                ? sprintf('Registered security key "%s"', $name)
                : 'Registered security key',
        ]);

        return response()->noContent();
    }
}
