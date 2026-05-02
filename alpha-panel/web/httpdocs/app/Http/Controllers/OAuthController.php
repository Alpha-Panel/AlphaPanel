<?php

namespace App\Http\Controllers;

use App\Models\OAuthAuthorizationCode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OAuthController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless($request->filled('redirect_uri') && filter_var($request->redirect_uri, FILTER_VALIDATE_URL), 400, 'Invalid redirect_uri.');
        abort_unless($request->filled('state'), 400, 'state required.');

        return view('oauth.authorize', [
            'redirect_uri' => $request->redirect_uri,
            'state' => $request->state,
            'error' => session('oauth_error'),
        ]);
    }

    public function authorize(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'redirect_uri' => 'required|url',
            'state' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return back()
                ->withInput(['email' => $validated['email'], 'redirect_uri' => $validated['redirect_uri'], 'state' => $validated['state']])
                ->withErrors(['email' => 'Kimlik bilgileri hatalı.']);
        }

        $code = Str::random(64);

        OAuthAuthorizationCode::create([
            'code' => $code,
            'user_id' => $user->id,
            'redirect_uri' => $validated['redirect_uri'],
            'expires_at' => now()->addSeconds(90),
        ]);

        return redirect($validated['redirect_uri'].'?'.http_build_query([
            'code' => $code,
            'state' => $validated['state'],
        ]));
    }
}
