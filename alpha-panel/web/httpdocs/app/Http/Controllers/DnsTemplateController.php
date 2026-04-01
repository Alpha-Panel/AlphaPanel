<?php

namespace App\Http\Controllers;

use App\Models\DnsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DnsTemplateController extends Controller
{
    public function index(): Response
    {
        $templates = DnsTemplate::query()
            ->withCount('records')
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/DnsTemplates', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:dns_templates,name'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.type' => ['required', 'string', 'in:A,AAAA,CNAME,MX,TXT,SRV,CAA,NS,SOA'],
            'records.*.name' => ['required', 'string', 'max:255'],
            'records.*.content' => ['required', 'string', 'max:65535'],
            'records.*.ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'records.*.priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        DB::transaction(function () use ($validated): void {
            $template = DnsTemplate::create(['name' => $validated['name']]);

            foreach ($validated['records'] as $record) {
                $template->records()->create($record);
            }
        });

        return redirect()->route('settings.dns-templates.index')
            ->with('success', __('DNS template created successfully.'));
    }

    public function show(DnsTemplate $dnsTemplate): JsonResponse
    {
        $dnsTemplate->load('records');

        return response()->json($dnsTemplate);
    }

    public function update(Request $request, DnsTemplate $dnsTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', "unique:dns_templates,name,{$dnsTemplate->id}"],
            'records' => ['required', 'array', 'min:1'],
            'records.*.type' => ['required', 'string', 'in:A,AAAA,CNAME,MX,TXT,SRV,CAA,NS,SOA'],
            'records.*.name' => ['required', 'string', 'max:255'],
            'records.*.content' => ['required', 'string', 'max:65535'],
            'records.*.ttl' => ['required', 'integer', 'min:60', 'max:86400'],
            'records.*.priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        DB::transaction(function () use ($dnsTemplate, $validated): void {
            $dnsTemplate->update(['name' => $validated['name']]);
            $dnsTemplate->records()->delete();

            foreach ($validated['records'] as $record) {
                $dnsTemplate->records()->create($record);
            }
        });

        return redirect()->route('settings.dns-templates.index')
            ->with('success', __('DNS template updated successfully.'));
    }

    public function destroy(DnsTemplate $dnsTemplate): RedirectResponse
    {
        $dnsTemplate->delete();

        return redirect()->route('settings.dns-templates.index')
            ->with('success', __('DNS template deleted successfully.'));
    }

    public function setDefault(DnsTemplate $dnsTemplate): RedirectResponse
    {
        $dnsTemplate->markAsDefault();

        return redirect()->route('settings.dns-templates.index')
            ->with('success', __('Default template updated successfully.'));
    }
}
