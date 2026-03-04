<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

class User extends Authenticatable implements WebAuthnAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, WebAuthnAuthentication;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'admin',
        'otp',
        'two_factor_confirmed',
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
        ];
    }

    public function isAdmin(): bool
    {
        return $this->admin;
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
}
