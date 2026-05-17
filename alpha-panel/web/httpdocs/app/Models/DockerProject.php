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

    /** Absolute path to this project's directory inside the panel container. */
    public function projectPath(): string
    {
        $base = rtrim((string) config('panel.docker_services.projects_dir', '/docker_compose_project_root/external-services/projects'), '/');

        return "{$base}/{$this->name}";
    }

    /** Absolute path to this project's directory on the Docker host (for build context). */
    public function hostProjectPath(): string
    {
        $base = rtrim((string) config('panel.docker_services.projects_dir_host', '/opt/alphapanel/external-services/projects'), '/');

        return "{$base}/{$this->name}";
    }

    /** Absolute path to docker-compose.yml inside the project directory. */
    public function composeFilePath(): string
    {
        return $this->projectPath().'/docker-compose.yml';
    }

    /** Docker image tag for the built image. */
    public function imageTag(): string
    {
        return 'alphapanel-'.$this->name.':latest';
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
