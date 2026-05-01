<?php

namespace App\Http\Controllers;

use App\Models\WebAuthn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $webauthn = WebAuthn::where('authenticatable_id', auth()->id())->get();

        return Inertia::render('User/Security', compact('webauthn'));
    }

    public function updateEmail(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        $user->email = $validated['email'];
        $user->email_verified_at = null;
        $user->save();

        return back()->with('status', 'email-updated');
    }

    public function updatePassword(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The provided password does not match your current password.')],
            ]);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return back()->with('status', 'password-updated');
    }

    public function notificationsPage(Request $request): Response
    {
        $user = $request->user();
        $perPage = max(10, min($request->integer('per_page', 20), 100));

        $notifications = $user->notifications()
            ->latest()
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification): array => $this->transformNotification($notification));

        return Inertia::render('User/Notifications', [
            'notifications' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->transformNotification($notification));

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markNotificationAsRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $user = $request->user();
        $this->ensureNotificationOwnership($user, $notification);

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAllNotificationsAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'status' => 'success',
            'unread_count' => 0,
        ]);
    }

    public function destroyNotification(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $user = $request->user();
        $this->ensureNotificationOwnership($user, $notification);

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function destroyAllNotifications(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json([
            'status' => 'success',
            'unread_count' => 0,
        ]);
    }

    private function ensureNotificationOwnership(object $user, DatabaseNotification $notification): void
    {
        if (
            $notification->notifiable_type !== $user::class
            || (int) $notification->notifiable_id !== (int) $user->id
        ) {
            abort(403);
        }
    }

    /** @return array<string, mixed> */
    private function transformNotification(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'created_at_human' => $notification->created_at?->diffForHumans(short: true),
        ];
    }
}
