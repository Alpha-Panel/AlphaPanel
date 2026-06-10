<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDockerServiceDomainBindingRequest;
use App\Models\AuditLog;
use App\Models\DockerService;
use App\Models\DockerServiceDomainBinding;
use App\Models\Domain;
use App\Services\DomainConfigService;
use App\Services\ReloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DockerServiceDomainBindingController extends Controller
{
    public function __construct(
        private DomainConfigService $configService,
        private ReloadService $reloadService,
    ) {}

    public function index(Request $request, Domain $domain): Response
    {
        $this->authorize('update', $domain);

        $domain->load('dockerServiceBindings.dockerService');

        $availableServices = DockerService::where('status', 'running')
            ->orWhere('status', 'stopped')
            ->get(['id', 'name', 'display_name', 'image', 'tag', 'status', 'ports']);

        return Inertia::render('Domains/DockerServices', [
            'domain' => $domain,
            'bindings' => $domain->dockerServiceBindings,
            'availableServices' => $availableServices,
        ]);
    }

    public function store(StoreDockerServiceDomainBindingRequest $request, Domain $domain): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $domain);

        $binding = $domain->dockerServiceBindings()->create($request->validated());
        $binding->load('dockerService');

        // Regenerate domain config with the new binding
        $domain->load('dockerServiceBindings.dockerService');
        $this->configService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();

        $pathInfo = $binding->path_prefix ? " at path {$binding->path_prefix}" : '';
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'docker_service_bound',
            'domain_id' => $domain->id,
            'summary' => "Bound Docker service \"{$binding->dockerService->display_name}\" to {$domain->fqdn}:{$binding->container_port}{$pathInfo}.",
            'details' => json_encode([
                'service_id' => $binding->docker_service_id,
                'container_port' => $binding->container_port,
                'path_prefix' => $binding->path_prefix,
            ], JSON_THROW_ON_ERROR),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('Docker service bound to domain successfully.'),
                'binding' => $binding,
            ]);
        }

        return back()->with('success', __('Docker service bound to domain successfully.'));
    }

    public function destroy(Request $request, Domain $domain, DockerServiceDomainBinding $binding): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $domain);
        abort_unless($binding->domain_id === $domain->id, 404);

        $serviceName = $binding->dockerService->display_name;
        $containerPort = $binding->container_port;
        $pathPrefix = $binding->path_prefix;

        $binding->delete();

        // Regenerate domain config without the removed binding
        $domain->load('dockerServiceBindings.dockerService');
        $this->configService->renderWithTls($domain);
        $this->reloadService->reloadCaddy();

        $pathInfo = $pathPrefix ? " at path {$pathPrefix}" : '';
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'docker_service_unbound',
            'domain_id' => $domain->id,
            'summary' => "Unbound Docker service \"{$serviceName}\" from {$domain->fqdn}:{$containerPort}{$pathInfo}.",
        ]);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Docker service unbound from domain.')]);
        }

        return back()->with('success', __('Docker service unbound from domain.'));
    }
}
