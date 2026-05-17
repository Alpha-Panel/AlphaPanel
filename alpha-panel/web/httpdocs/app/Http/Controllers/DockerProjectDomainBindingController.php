<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDockerProjectDomainBindingRequest;
use App\Models\AuditLog;
use App\Models\DockerProject;
use App\Models\DockerProjectDomainBinding;
use App\Models\Domain;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DockerProjectDomainBindingController extends Controller
{
    public function __construct(
        private DomainConfigService $configService,
        private ReloadService $reloadService,
    ) {}

    public function index(Request $request, Domain $domain): Response
    {
        $domain->load('dockerProjectBindings.dockerProject');

        $availableProjects = DockerProject::whereIn('status', ['running', 'stopped', 'failed'])
            ->get(['id', 'name', 'display_name', 'status']);

        return Inertia::render('Domains/DockerProjects', [
            'domain' => $domain,
            'bindings' => $domain->dockerProjectBindings,
            'availableProjects' => $availableProjects,
        ]);
    }

    public function store(StoreDockerProjectDomainBindingRequest $request, Domain $domain): RedirectResponse|JsonResponse
    {
        $binding = $domain->dockerProjectBindings()->create($request->validated());
        $binding->load('dockerProject');

        $domain->load(['dockerServiceBindings.dockerService', 'dockerProjectBindings.dockerProject']);
        $this->configService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();

        $pathInfo = $binding->path_prefix ? " at path {$binding->path_prefix}" : '';
        $projectLabel = $binding->dockerProject->display_name ?? $binding->dockerProject->name;
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'docker_project_bound',
            'domain_id' => $domain->id,
            'summary' => "Bound Docker project service \"{$projectLabel}/{$binding->service_name}\" to {$domain->fqdn}:{$binding->container_port}{$pathInfo}.",
            'details' => json_encode([
                'project_id' => $binding->docker_project_id,
                'service_name' => $binding->service_name,
                'container_port' => $binding->container_port,
                'path_prefix' => $binding->path_prefix,
            ], JSON_THROW_ON_ERROR),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('Docker project service bound to domain successfully.'),
                'binding' => $binding,
            ]);
        }

        return back()->with('success', __('Docker project service bound to domain successfully.'));
    }

    public function destroy(Request $request, Domain $domain, DockerProjectDomainBinding $binding): RedirectResponse|JsonResponse
    {
        $projectName = $binding->dockerProject->display_name ?? $binding->dockerProject->name;
        $serviceName = $binding->service_name;
        $containerPort = $binding->container_port;
        $pathPrefix = $binding->path_prefix;

        $binding->delete();

        $domain->load(['dockerServiceBindings.dockerService', 'dockerProjectBindings.dockerProject']);
        $this->configService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();

        $pathInfo = $pathPrefix ? " at path {$pathPrefix}" : '';
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'docker_project_unbound',
            'domain_id' => $domain->id,
            'summary' => "Unbound Docker project service \"{$projectName}/{$serviceName}\" from {$domain->fqdn}:{$containerPort}{$pathInfo}.",
        ]);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Docker project service unbound from domain.')]);
        }

        return back()->with('success', __('Docker project service unbound from domain.'));
    }
}
