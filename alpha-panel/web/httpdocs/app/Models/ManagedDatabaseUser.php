<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedDatabaseUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'managed_database_id',
        'db_user',
        'db_password_encrypted',
        'created_by',
    ];

    protected $hidden = [
        'db_password_encrypted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'db_password_encrypted' => 'encrypted',
        ];
    }

    public function managedDatabase(): BelongsTo
    {
        return $this->belongsTo(ManagedDatabase::class, 'managed_database_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
