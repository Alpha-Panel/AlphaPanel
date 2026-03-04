<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebAuthn;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller implements HasMiddleware
{
    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     */
    protected $redirectTo = '/';

    public static function middleware(): array
    {
        return [
            new Middleware('guest', except: ['logout']),
            new Middleware('auth', only: ['logout']),
        ];
    }

    /**
     * Show the application's login form.
     */
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function methods(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
        ]);

        $login = trim($validated['login']);
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::query()->where($field, $login)->first();
        if (! $user) {
            return response()->json([
                'message' => 'Account could not be found for this username/email.',
            ], 422);
        }

        return response()->json([
            'has_webauthn' => WebAuthn::query()
                ->where('authenticatable_id', $user->id)
                ->exists(),
            'has_totp' => (bool) $user->two_factor_confirmed,
            'email' => $user->email,
        ]);
    }

    /**
     * Get the login username to be used by the controller.
     */
    public function username(): string
    {
        return 'login';
    }

    /**
     * Validate the user login request.
     */
    protected function validateLogin(Request $request): void
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array<string, string>
     */
    protected function credentials(Request $request): array
    {
        $login = $request->input('login');
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $field => $login,
            'password' => $request->input('password'),
        ];
    }
}
