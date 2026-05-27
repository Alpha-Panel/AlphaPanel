<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use App\Services\LocalDomainFileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FileManagerController extends ApiController
{
    private function getService(Domain $domain): LocalDomainFileManagerService
    {
        $ftpUser = $domain->ftpUser ?? $domain->parentDomain?->ftpUser;
        abort_unless($ftpUser, 422, __('No FTP user configured for this domain.'));

        return LocalDomainFileManagerService::forUser($ftpUser);
    }

    public function index(Domain $domain): JsonResponse
    {
        $this->authorize('viewFiles', $domain);

        return response()->json(['data' => ['fqdn' => $domain->fqdn, 'web_root' => $domain->getWebRootPath()]]);
    }

    public function list(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('viewFiles', $domain);

        $path = (string) $request->input('path', '/');
        $items = $this->getService($domain)->listDirectory($path);

        return response()->json(['data' => $items]);
    }

    public function read(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('viewFiles', $domain);
        $request->validate(['path' => 'required|string']);
        $content = $this->getService($domain)->readFile($request->input('path'));

        return response()->json(['data' => ['content' => $content]]);
    }

    public function write(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['path' => 'required|string', 'content' => 'required|string']);
        $this->getService($domain)->writeFile($request->input('path'), $request->input('content'));

        return response()->json(['message' => __('File saved.')]);
    }

    public function createFile(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['path' => 'required|string']);
        $this->getService($domain)->writeFile($request->input('path'), '');

        return response()->json(['message' => __('File created.')], 201);
    }

    public function createDirectory(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['path' => 'required|string']);
        $this->getService($domain)->createDirectory($request->input('path'));

        return response()->json(['message' => __('Directory created.')], 201);
    }

    public function upload(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['path' => 'required|string', 'files' => 'required|array', 'files.*' => 'file']);
        $service = $this->getService($domain);
        $count = 0;

        foreach ($request->file('files') as $file) {
            $service->upload($request->input('path'), $file);
            $count++;
        }

        return response()->json(['message' => __(':count file(s) uploaded', ['count' => $count])]);
    }

    public function delete(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['paths' => 'required|array', 'paths.*' => 'string']);
        $service = $this->getService($domain);

        foreach ($request->input('paths') as $path) {
            $service->delete($path);
        }

        return response()->json(['message' => __('Deleted.')]);
    }

    public function rename(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['old_path' => 'required|string', 'new_path' => 'required|string']);
        $this->getService($domain)->rename($request->input('old_path'), $request->input('new_path'));

        return response()->json(['message' => __('Renamed.')]);
    }

    public function chmod(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['path' => 'required|string', 'permissions' => 'required|string|regex:/^[0-7]{3,4}$/']);
        $this->getService($domain)->chmod($request->input('path'), $request->input('permissions'));

        return response()->json(['message' => __('Permissions updated.')]);
    }

    public function compress(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['paths' => 'required|array', 'archive_name' => 'required|string']);
        $this->getService($domain)->compress($request->input('paths'), $request->input('archive_name'));

        return response()->json(['message' => __('Compressed.')]);
    }

    public function decompress(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('manageFiles', $domain);
        $request->validate(['path' => 'required|string', 'destination' => 'nullable|string']);
        $this->getService($domain)->decompress($request->input('path'), $request->input('destination', '/'));

        return response()->json(['message' => __('Decompressed.')]);
    }

    public function download(Request $request, Domain $domain): Response
    {
        $this->authorize('viewFiles', $domain);
        $request->validate(['path' => 'required|string']);
        $stream = $this->getService($domain)->readStream($request->input('path'));
        $content = stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $name = basename($request->input('path'));

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}
