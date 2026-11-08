<?php

namespace Tests\Feature;

use App\Support\Passkeys\BrowserCredential;
use App\Support\Passkeys\GenerateVerificationOptions;
use App\Support\Passkeys\LegacyWebAuthnConverter;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions as BaseGenerateVerificationOptions;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * A passkey registered under laragear, copied by the conversion migration, must still sign the
 * user in: the converted record + an assertion posted the way the login page posts it (btoa,
 * standard base64) has to pass laravel/passkeys' assertion validator. No database involved.
 */
class LegacyPasskeyLoginTest extends TestCase
{
    private const RP_ID = 'panel.example.test';

    private const ORIGIN = 'https://panel.example.test:8443';

    protected function setUp(): void
    {
        parent::setUp();

        config(['passkeys.relying_party_id' => self::RP_ID, 'passkeys.allowed_origins' => [self::ORIGIN]]);
    }

    public function test_converted_legacy_passkey_verifies_a_login_assertion(): void
    {
        [$private, $pem] = $this->keyPair();
        $rawId = random_bytes(32);
        $legacyUserId = '0b9c3f8e-4c7a-4f0e-9a3b-2d1e5f6a7b8c';

        $record = WebAuthn::fromJson(json_encode(LegacyWebAuthnConverter::credential([
            'id' => Base64UrlSafe::encodeUnpadded($rawId),
            'user_id' => $legacyUserId,
            'counter' => 7,
            'transports' => ['usb'],
            'aaguid' => null,
        ], $pem)), CredentialRecord::class);

        $options = app(BaseGenerateVerificationOptions::class)();
        $payload = $this->assertion($private, $rawId, $options, LegacyWebAuthnConverter::userHandle($legacyUserId));

        $credential = BrowserCredential::fromRequest(Request::create('/webauthn/login', 'POST', $payload));

        $verified = WebAuthn::assertionValidator()->check(
            credentialRecord: $record,
            authenticatorAssertionResponse: $credential->response,
            publicKeyCredentialRequestOptions: $options,
            host: self::RP_ID,
            userHandle: $record->userHandle,
        );

        $this->assertSame(8, $verified->counter);
    }

    public function test_tampered_assertion_is_rejected(): void
    {
        [$private, $pem] = $this->keyPair();
        $rawId = random_bytes(32);
        $legacyUserId = '0b9c3f8e-4c7a-4f0e-9a3b-2d1e5f6a7b8c';

        $record = WebAuthn::fromJson(json_encode(LegacyWebAuthnConverter::credential([
            'id' => Base64UrlSafe::encodeUnpadded($rawId), 'user_id' => $legacyUserId, 'counter' => 0, 'transports' => [], 'aaguid' => null,
        ], $pem)), CredentialRecord::class);

        $options = app(BaseGenerateVerificationOptions::class)();
        $payload = $this->assertion($private, $rawId, $options, LegacyWebAuthnConverter::userHandle($legacyUserId));
        $payload['response']['signature'] = base64_encode(random_bytes(70));

        // Malformed signatures surface as InvalidArgumentException, not WebauthnException: the
        // controllers therefore map any non-validation Throwable to a 422.
        $this->expectException(\InvalidArgumentException::class);

        WebAuthn::assertionValidator()->check(
            credentialRecord: $record,
            authenticatorAssertionResponse: BrowserCredential::fromRequest(Request::create('/webauthn/login', 'POST', $payload))->response,
            publicKeyCredentialRequestOptions: $options,
            host: self::RP_ID,
            userHandle: $record->userHandle,
        );
    }

    public function test_login_options_prefer_rather_than_require_user_verification(): void
    {
        $options = app(BaseGenerateVerificationOptions::class)();

        $this->assertInstanceOf(GenerateVerificationOptions::class, app(BaseGenerateVerificationOptions::class));
        $this->assertSame('preferred', $options->userVerification);
    }

    /**
     * A navigator.credentials.get() result, serialized like Login.vue does it.
     *
     * @return array<string, mixed>
     */
    private function assertion(\OpenSSLAsymmetricKey $private, string $rawId, PublicKeyCredentialRequestOptions $options, string $userHandle): array
    {
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded($options->challenge),
            'origin' => self::ORIGIN,
            'crossOrigin' => false,
        ]);

        // rpIdHash | flags (user present + verified) | sign count 8
        $authenticatorData = hash('sha256', self::RP_ID, true).chr(0x05).pack('N', 8);

        openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $private, OPENSSL_ALGO_SHA256);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($rawId),
            'type' => 'public-key',
            'rawId' => base64_encode($rawId),
            'response' => [
                'clientDataJSON' => base64_encode($clientData),
                'authenticatorData' => base64_encode($authenticatorData),
                'signature' => base64_encode($signature),
                'userHandle' => base64_encode($userHandle),
            ],
        ];
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function keyPair(): array
    {
        $config = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ap-openssl.cnf';
        if (! is_file($config)) {
            file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
        }

        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);

        return [$private, openssl_pkey_get_details($private)['key']];
    }
}
