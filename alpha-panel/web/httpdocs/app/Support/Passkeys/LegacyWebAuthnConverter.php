<?php

namespace App\Support\Passkeys;

use CBOR\ByteStringObject;
use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\StringStream;
use CBOR\UnsignedIntegerObject;
use Cose\Algorithms;
use Cose\Key\Ec2Key;
use Cose\Key\Key;
use Cose\Key\RsaKey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Ramsey\Uuid\Uuid as RamseyUuid;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Converts a laragear/webauthn credential row into the laravel/passkeys credential record.
 *
 * Laragear kept the public key as PEM; laravel/passkeys verifies against a COSE key, and only
 * for ES256 and RS256. Anything else is reported as unconvertible (null) so the caller can skip it.
 */
final class LegacyWebAuthnConverter
{
    /**
     * Build the laravel/passkeys credential JSON for a legacy row, or null when it can't be converted.
     *
     * @param  array{id: string, user_id: string, counter: int|null, transports: array<int, string>|null, aaguid: string|null}  $row
     * @return array<string, mixed>|null
     */
    public static function credential(array $row, string $pem): ?array
    {
        $cose = self::coseFromPem($pem);

        if ($cose === null || ! self::coseMatchesPem($cose, $pem)) {
            return null;
        }

        try {
            $record = CredentialRecord::create(
                publicKeyCredentialId: Base64UrlSafe::decodeNoPadding(rtrim($row['id'], '=')),
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                transports: $row['transports'] ?? [],
                attestationType: 'none',
                trustPath: EmptyTrustPath::create(),
                aaguid: Uuid::fromString($row['aaguid'] ?? '00000000-0000-0000-0000-000000000000'),
                credentialPublicKey: $cose,
                userHandle: self::userHandle($row['user_id']),
                counter: (int) ($row['counter'] ?? 0),
            );

            return json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The user handle bytes the authenticator stored at registration.
     *
     * Laragear sent the user UUID as 32 hex chars and the browser decoded that string as
     * base64url, so the authenticator holds those 24 bytes, not the UUID's 16.
     */
    public static function userHandle(string $userId): string
    {
        return Base64UrlSafe::decodeNoPadding(RamseyUuid::fromString($userId)->getHex()->toString());
    }

    /**
     * COSE (CBOR) public key for a PEM key, or null when it isn't P-256 EC or RSA.
     */
    public static function coseFromPem(string $pem): ?string
    {
        $key = openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($details === false) {
            return null;
        }

        if ($details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1') {
            return (string) MapObject::create()
                ->add(UnsignedIntegerObject::create(Key::TYPE), UnsignedIntegerObject::create(Key::TYPE_EC2))
                ->add(UnsignedIntegerObject::create(Key::ALG), NegativeIntegerObject::create(Algorithms::COSE_ALGORITHM_ES256))
                ->add(NegativeIntegerObject::create(Ec2Key::DATA_CURVE), UnsignedIntegerObject::create(Ec2Key::CURVE_P256))
                ->add(NegativeIntegerObject::create(Ec2Key::DATA_X), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)))
                ->add(NegativeIntegerObject::create(Ec2Key::DATA_Y), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)));
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
            return (string) MapObject::create()
                ->add(UnsignedIntegerObject::create(Key::TYPE), UnsignedIntegerObject::create(Key::TYPE_RSA))
                ->add(UnsignedIntegerObject::create(Key::ALG), NegativeIntegerObject::create(Algorithms::COSE_ALGORITHM_RS256))
                ->add(NegativeIntegerObject::create(RsaKey::DATA_N), ByteStringObject::create($details['rsa']['n']))
                ->add(NegativeIntegerObject::create(RsaKey::DATA_E), ByteStringObject::create($details['rsa']['e']));
        }

        return null;
    }

    /**
     * Decode the COSE key the way webauthn-lib will and check it is the same public key as the PEM.
     */
    public static function coseMatchesPem(string $cose, string $pem): bool
    {
        try {
            $data = Decoder::create()->decode(StringStream::create($cose))->normalize();
            $key = Key::createFromData($data);
            $original = openssl_pkey_get_details(openssl_pkey_get_public($pem));

            if ($key instanceof Ec2Key) {
                return openssl_pkey_get_details(openssl_pkey_get_public($key->asPEM()))['key'] === $original['key'];
            }

            return $key instanceof RsaKey
                && ltrim($key->n(), "\0") === ltrim($original['rsa']['n'], "\0")
                && ltrim($key->e(), "\0") === ltrim($original['rsa']['e'], "\0");
        } catch (Throwable) {
            return false;
        }
    }
}
