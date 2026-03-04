<?php

namespace App\Http\Controllers;

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
        $credential = WebAuthn::where('authenticatable_id', auth()->id())
            ->where('id', $request->post('webauthn_id'))
            ->first();

        if ($credential) {
            $credential->delete();
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
        $credential = WebAuthn::where('authenticatable_id', auth()->id())
            ->where('id', $request->post('webauthn_id'))
            ->first();

        if ($credential) {
            $credential->name = $request->post('device_name');
            $credential->save();
        }

        return response()->json(['status' => true]);
    }
}
