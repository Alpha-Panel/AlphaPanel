<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FtpUser extends Model
{
    protected $fillable = [
        'domain_id',
        'username',
        'homedir',
        'encrypted_password',
        'password',
        'uid',
        'gid',
        'shell',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_password' => 'encrypted',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function hasPassword(): bool
    {
        return $this->encrypted_password !== null;
    }
}
