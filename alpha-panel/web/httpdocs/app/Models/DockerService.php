<?php

namespace App\Models;

use App\Enums\DockerServiceStatus;
use App\Enums\RestartPolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DockerService extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'image',
        'tag',
        'status',
        'restart_policy',
        'container_id',
        'environment_variables',
        'volumes',
        'ports',
        'resource_limits',
        'networks',
        'hostname',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DockerServiceStatus::class,
            'restart_policy' => RestartPolicy::class,
            'environment_variables' => 'array',
            'volumes' => 'array',
            'ports' => 'array',
            'resource_limits' => 'array',
            'networks' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function domainBindings(): HasMany
    {
        return $this->hasMany(DockerServiceDomainBinding::class);
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class, 'docker_service_domain_bindings');
    }
}
