<?php

namespace Tests\Unit;

use App\Support\Passkeys\LegacyWebAuthnConverter;
use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Algorithm\Signature\ECDSA\ECSignature;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Key\Key;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\TestCase;
use Webauthn\CredentialRecord;

class LegacyWebAuthnConverterTest extends TestCase
{
    public function test_ec_p256_key_still_verifies_signatures_after_conversion(): void
    {
        [$private, $pem] = $this->keyPair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        openssl_sign('payload', $der, $private, OPENSSL_ALGO_SHA256);

        $key = $this->decode(LegacyWebAuthnConverter::coseFromPem($pem));

        $this->assertTrue(ES256::create()->verify('payload', $key, ECSignature::fromAsn1($der, 64)));
        $this->assertFalse(ES256::create()->verify('tampered', $key, ECSignature::fromAsn1($der, 64)));
    }

    public function test_rsa_key_still_verifies_signatures_after_conversion(): void
    {
        [$private, $pem] = $this->keyPair(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        openssl_sign('payload', $signature, $private, OPENSSL_ALGO_SHA256);

        $this->assertTrue(RS256::create()->verify('payload', $this->decode(LegacyWebAuthnConverter::coseFromPem($pem)), $signature));
    }

    public function test_unsupported_curve_is_not_converted(): void
    {
        [, $pem] = $this->keyPair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);

        $this->assertNull(LegacyWebAuthnConverter::coseFromPem($pem));
    }

    public function test_credential_record_round_trips_with_the_legacy_user_handle(): void
    {
        [, $pem] = $this->keyPair(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $rawId = random_bytes(32);

        $credential = LegacyWebAuthnConverter::credential([
            'id' => Base64UrlSafe::encodeUnpadded($rawId),
            'user_id' => '0b9c3f8e-4c7a-4f0e-9a3b-2d1e5f6a7b8c',
            'counter' => 7,
            'transports' => ['internal'],
            'aaguid' => null,
        ], $pem);

        $record = WebAuthn::fromJson(json_encode($credential), CredentialRecord::class);

        $this->assertSame($rawId, $record->publicKeyCredentialId);
        $this->assertSame(7, $record->counter);
        // The browser base64url-decoded laragear's 32 hex chars: 24 bytes that re-encode to the hex.
        $this->assertSame('0b9c3f8e4c7a4f0e9a3b2d1e5f6a7b8c', Base64UrlSafe::encodeUnpadded($record->userHandle));
        $this->assertSame(LegacyWebAuthnConverter::coseFromPem($pem), $record->credentialPublicKey);
    }

    public function test_garbage_pem_is_not_converted(): void
    {
        $this->assertNull(LegacyWebAuthnConverter::credential([
            'id' => 'abc', 'user_id' => '0b9c3f8e-4c7a-4f0e-9a3b-2d1e5f6a7b8c', 'counter' => 0, 'transports' => null, 'aaguid' => null,
        ], 'not a key'));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}
     */
    private function keyPair(array $options): array
    {
        $private = openssl_pkey_new($options + ['config' => $this->opensslConfig()]);

        return [$private, openssl_pkey_get_details($private)['key']];
    }

    private function decode(?string $cose): Key
    {
        $this->assertNotNull($cose);

        return Key::createFromData(Decoder::create()->decode(StringStream::create($cose))->normalize());
    }

    /** Windows PHP builds often lack a default openssl.cnf; key generation needs one. */
    private function opensslConfig(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ap-openssl.cnf';
        if (! is_file($path)) {
            file_put_contents($path, "[req]\ndistinguished_name=dn\n[dn]\n");
        }

        return $path;
    }
}
