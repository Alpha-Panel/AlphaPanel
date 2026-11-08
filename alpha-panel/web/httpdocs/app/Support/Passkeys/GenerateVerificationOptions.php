<?php

namespace App\Support\Passkeys;

use Laravel\Passkeys\Actions\GenerateVerificationOptions as BaseGenerateVerificationOptions;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Same as the package, but user verification is "preferred" instead of "required".
 *
 * Passkeys migrated from laragear were registered with user verification discouraged, so a
 * security key without a PIN could not satisfy "required" and its owner would be locked out
 * (password login is blocked while a passkey exists). Platform authenticators (Face ID,
 * Touch ID, Windows Hello) still verify the user either way.
 */
class GenerateVerificationOptions extends BaseGenerateVerificationOptions
{
    public function __invoke(?PasskeyUser $user = null): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: $this->allowCredentials($user),
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: Passkeys::timeout(),
        );
    }
}
