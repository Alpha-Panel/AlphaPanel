<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedPostgresDatabaseUser extends Model
{
    use HasFactory;

    protected $table = 'managed_pg_database_users';

    protected $fillable = [
        'managed_pg_database_id',
        'pg_user',
        'pg_password_encrypted',
        'created_by',
    ];

    protected $hidden = [
        'pg_password_encrypted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pg_password_encrypted' => 'encrypted',
        ];
    }

    public function managedPostgresDatabase(): BelongsTo
    {
        return $this->belongsTo(ManagedPostgresDatabase::class, 'managed_pg_database_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
