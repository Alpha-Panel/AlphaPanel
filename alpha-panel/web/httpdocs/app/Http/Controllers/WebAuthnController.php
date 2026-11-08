<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\DeletePasskey;

class WebAuthnController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        return response()->json(self::summaries($request->user()));
    }

    public function delete(Request $request, DeletePasskey $deletePasskey): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer',
        ]);

        /** @var User $user */
        $user = $request->user();
        $passkey = $user->passkeys()->whereKey($validated['id'])->first();

        if ($passkey) {
            $deletedName = (string) $passkey->name;
            $deletePasskey($user, $passkey);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'webauthn_deleted',
                'summary' => $deletedName !== ''
                    ? sprintf('Deleted security key "%s"', $deletedName)
                    : 'Deleted security key',
            ]);
        }

        if (! $user->hasPasskeysEnabled() && ! $user->two_factor_confirmed && $user->two_factor_secret === null) {
            $user->otp = false;
            $user->save();
        }

        return response()->json(['status' => 'success']);
    }

    public function rename(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer',
            'name' => 'required|string|max:120',
        ]);

        $passkey = $request->user()->passkeys()->whereKey($validated['id'])->first();

        if ($passkey) {
            $oldName = (string) $passkey->name;
            $newName = trim($validated['name']);
            $passkey->name = $newName;
            $passkey->save();

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'webauthn_renamed',
                'summary' => $oldName !== ''
                    ? sprintf('Renamed security key "%s" → "%s"', $oldName, $newName)
                    : sprintf('Named security key "%s"', $newName),
            ]);
        }

        return response()->json(['status' => true]);
    }

    /**
     * The user's passkeys without the stored credential (public key, counter, user handle).
     */
    public static function summaries(User $user): Collection
    {
        return $user->passkeys()->oldest()->get()->makeHidden(['credential', 'credential_id', 'user_id']);
    }
}
