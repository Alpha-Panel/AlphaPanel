<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedDatabase extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'db_name',
        'created_by',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function databaseUsers(): HasMany
    {
        return $this->hasMany(ManagedDatabaseUser::class, 'managed_database_id');
    }
}
