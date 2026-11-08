<?php

namespace App\Support\Passkeys;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

/**
 * The panel's pages (login, OTP, OAuth authorize, security) post the serialized credential at the
 * top level of the request body; laravel/passkeys expects it under `credential`. This reads the
 * top-level shape so those pages did not need to change.
 */
final class BrowserCredential
{
    public static function fromRequest(Request $request): PublicKeyCredential
    {
        $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
        ]);

        $payload = array_filter(
            $request->only(['id', 'rawId', 'type', 'response', 'authenticatorAttachment', 'clientExtensionResults']),
            fn ($value): bool => $value !== null,
        );

        // Some pages encode binary fields with btoa() (standard base64); webauthn-lib decodes
        // clientDataJSON as strict unpadded base64url, so normalise every binary field.
        $payload['rawId'] = self::base64Url($payload['rawId']);
        $payload['response'] = array_map(
            fn ($value) => is_string($value) ? self::base64Url($value) : $value,
            array_filter($payload['response'], fn ($value): bool => $value !== null),
        );

        try {
            return WebAuthn::fromJson(json_encode($payload, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw ValidationException::withMessages(['credential' => __('Invalid credential format.')]);
        }
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr($value, '+/', '-_'), '=');
    }

    /**
     * Pull the options stored for this ceremony; a missing entry means it expired or was replayed.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function pullOptions(Request $request, string $key, string $class): object
    {
        $serialized = $request->session()->pull($key);

        if (! is_string($serialized) || $serialized === '') {
            throw ValidationException::withMessages(['credential' => __('Passkey session expired. Please try again.')]);
        }

        return WebAuthn::fromJson($serialized, $class);
    }
}
