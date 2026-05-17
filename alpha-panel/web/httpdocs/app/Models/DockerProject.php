<?php

namespace App\Models;

use App\Enums\DockerProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DockerProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'compose_yaml',
        'status',
        'portainer_stack_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DockerProjectStatus::class,
            'portainer_stack_id' => 'integer',
        ];
    }

    /** Portainer stack name — prefixed to avoid clashes with other stacks. */
    public function stackName(): string
    {
        return 'alphapanel-'.$this->name;
    }

    /**
     * Predictable container name for a compose service.
     * Docker Compose v2 naming: {project}-{service}-{replica}.
     */
    public function containerName(string $serviceName, int $replica = 1): string
    {
        return "{$this->stackName()}-{$serviceName}-{$replica}";
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function domainBindings(): HasMany
    {
        return $this->hasMany(DockerProjectDomainBinding::class);
    }
}
