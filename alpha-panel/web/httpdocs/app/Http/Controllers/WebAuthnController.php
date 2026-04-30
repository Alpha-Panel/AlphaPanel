<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebAuthn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebAuthnController extends Controller
{
    public function list(): JsonResponse
    {
        $credentials = WebAuthn::where('authenticatable_id', auth()->id())->get();

        return response()->json($credentials);
    }

    public function delete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
        ]);

        $credential = WebAuthn::where('authenticatable_id', auth()->id())
            ->where('id', $validated['id'])
            ->first();

        if ($credential) {
            $deletedName = (string) ($credential->name ?? '');
            $credential->delete();

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'webauthn_deleted',
                'summary' => $deletedName !== ''
                    ? sprintf('Deleted security key "%s"', $deletedName)
                    : 'Deleted security key',
            ]);
        }

        $remainingCount = WebAuthn::where('authenticatable_id', auth()->id())->count();

        if ($remainingCount === 0) {
            $user = User::find(auth()->id());
            if (! $user->two_factor_confirmed && $user->two_factor_secret === null) {
                $user->otp = false;
                $user->save();
            }
        }

        return response()->json(['status' => 'success']);
    }

    public function rename(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'name' => 'required|string|max:120',
        ]);

        $credential = WebAuthn::where('authenticatable_id', auth()->id())
            ->where('id', $validated['id'])
            ->first();

        if ($credential) {
            $oldName = (string) ($credential->name ?? '');
            $newName = trim($validated['name']);
            $credential->name = $newName;
            $credential->save();

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'webauthn_renamed',
                'summary' => $oldName !== ''
                    ? sprintf('Renamed security key "%s" → "%s"', $oldName, $newName)
                    : sprintf('Named security key "%s"', $newName),
            ]);
        }

        return response()->json(['status' => true]);
    }
}
