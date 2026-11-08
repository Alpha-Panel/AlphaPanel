<?php

use App\Models\User;
use App\Support\Passkeys\LegacyWebAuthnConverter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Passkeys;

/**
 * Copies laragear/webauthn credentials into laravel/passkeys so users keep their passkeys.
 *
 * A row is copied only if it will verify under the new package (same RP ID, an allowed origin,
 * an ES256/RS256 key that converts back to the identical public key). Anything else is skipped
 * and logged: the panel blocks password login while a passkey exists, so an unusable copy would
 * lock the user out, whereas a skipped one leaves password + 2FA login open to re-register.
 * The old table is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webauthn_credentials') || ! Schema::hasTable('passkeys')) {
            return;
        }

        $rpId = Passkeys::relyingPartyId();
        $origins = array_map(fn (string $origin): string => rtrim($origin, '/'), Passkeys::allowedOrigins());
        $copied = 0;

        $rows = DB::table('webauthn_credentials')
            ->whereNull('disabled_at')
            ->where('authenticatable_type', (new User)->getMorphClass())
            ->whereIn('authenticatable_id', DB::table('users')->select('id'))
            ->get();

        foreach ($rows as $row) {
            $skip = fn (string $reason) => Log::warning('Passkey migration: skipped legacy credential.', [
                'user_id' => $row->authenticatable_id,
                'name' => $row->name ?? $row->alias,
                'reason' => $reason,
            ]);

            if ($row->rp_id !== $rpId) {
                $skip("RP ID {$row->rp_id} differs from {$rpId}");

                continue;
            }

            if (! in_array(rtrim($row->origin, '/'), $origins, true)) {
                $skip("origin {$row->origin} is not an allowed passkey origin");

                continue;
            }

            try {
                $pem = Crypt::decryptString($row->public_key);
            } catch (Throwable) {
                $skip('public key could not be decrypted with the current APP_KEY');

                continue;
            }

            $credential = LegacyWebAuthnConverter::credential([
                'id' => $row->id,
                'user_id' => $row->user_id,
                'counter' => $row->counter,
                'transports' => json_decode((string) $row->transports, true) ?: [],
                'aaguid' => $row->aaguid,
            ], $pem);

            if ($credential === null) {
                $skip('key type is not ES256/RS256 or did not convert cleanly');

                continue;
            }

            $credentialId = $credential['publicKeyCredentialId'] ?? null;

            if (! is_string($credentialId) || DB::table('passkeys')->where('credential_id', $credentialId)->exists()) {
                continue;
            }

            DB::table('passkeys')->insert([
                'user_id' => $row->authenticatable_id,
                'name' => trim((string) ($row->name ?? $row->alias ?? '')) ?: 'Passkey',
                'credential_id' => $credentialId,
                'credential' => json_encode($credential, JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $copied++;
        }

        Log::info('Passkey migration: copied legacy credentials.', ['copied' => $copied, 'total' => $rows->count()]);
    }

    public function down(): void
    {
        // Data copy only; the legacy table was never modified.
    }
};
