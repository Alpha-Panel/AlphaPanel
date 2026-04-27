<?php

namespace App\Models;

use App\Services\ImpersonationService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements WebAuthnAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, Notifiable, TwoFactorAuthenticatable, WebAuthnAuthentication;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'skip_self_push',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'admin' => 'boolean',
            'otp' => 'boolean',
            'two_factor_confirmed' => 'boolean',
            'skip_self_push' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function isImpersonating(): bool
    {
        $service = app(ImpersonationService::class);

        return $service->isActive() && $service->impersonator()?->id === $this->id;
    }

    public function confirmTwoFactorAuth(string $code): bool
    {
        $codeIsValid = app(TwoFactorAuthenticationProvider::class)
            ->verify(decrypt($this->two_factor_secret), $code);

        if ($codeIsValid) {
            $this->two_factor_confirmed = true;
            $this->save();

            return true;
        }

        return false;
    }

    public function ownedDomains(): HasMany
    {
        return $this->hasMany(Domain::class, 'owner_user_id');
    }

    public function applyRuns(): HasMany
    {
        return $this->hasMany(ApplyRun::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    /**
     * Domains this user has been granted access to (via pivot table).
     */
    public function accessibleDomains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class)->withTimestamps();
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }
}
