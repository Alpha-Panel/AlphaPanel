<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcmeAccount extends Model
{
    protected $fillable = [
        'server_url',
        'account_url',
        'email',
        'private_key_pem',
        'public_key_pem',
    ];

    protected function casts(): array
    {
        return [
            'private_key_pem' => 'encrypted',
            'public_key_pem' => 'encrypted',
        ];
    }

    /**
     * Find the account for a given ACME server URL.
     */
    public static function forServer(string $serverUrl): ?self
    {
        return self::where('server_url', $serverUrl)->first();
    }
}
