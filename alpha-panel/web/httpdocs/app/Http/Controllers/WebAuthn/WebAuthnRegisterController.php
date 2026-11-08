<?php

namespace App\Http\Controllers\WebAuthn;

use App\Models\AuditLog;
use App\Support\Passkeys\BrowserCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredentialCreationOptions;

use function response;

class WebAuthnRegisterController
{
    private const OPTIONS_KEY = 'passkey.registration_options';

    /**
     * Returns a challenge to be verified by the user device.
     */
    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $options = $generate($request->user());

        $request->session()->put(self::OPTIONS_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Registers a device for further passkey authentication.
     */
    public function register(Request $request, StorePasskey $store): Response
    {
        $request->validate(['name' => 'nullable|string|max:120']);

        $name = trim((string) $request->input('name', ''));
        $user = $request->user();

        $credential = BrowserCredential::fromRequest($request);
        $options = BrowserCredential::pullOptions($request, self::OPTIONS_KEY, PublicKeyCredentialCreationOptions::class);

        try {
            $store($user, $name !== '' ? $name : 'Passkey', $credential, $options);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Passkey registration failed.', ['error' => $e->getMessage()]);

            throw InvalidPasskeyException::make('Unable to register passkey. Please try again.');
        }

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
