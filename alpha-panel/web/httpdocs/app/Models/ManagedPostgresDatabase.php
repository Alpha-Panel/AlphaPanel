<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedPostgresDatabase extends Model
{
    use HasFactory;

    protected $table = 'managed_pg_databases';

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

    public function pgDatabaseUsers(): HasMany
    {
        return $this->hasMany(ManagedPostgresDatabaseUser::class, 'managed_pg_database_id');
    }
}
