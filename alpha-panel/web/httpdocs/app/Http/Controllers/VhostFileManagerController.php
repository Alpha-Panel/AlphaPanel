<?php

namespace App\Http\Controllers;

use App\Helpers\UploadLimits;
use App\Models\AuditLog;
use App\Services\VhostFileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only file manager over every hosted site (/var/www/vhosts), served by the panel itself.
 * Same JSON contract as the per-domain FileManagerController, so the Vue file manager is shared.
 */
class VhostFileManagerController extends Controller
{
    public function __construct(private readonly VhostFileManagerService $files) {}

    public function index(): Response
    {
        return Inertia::render('Domains/FileManager', [
            'domain' => null,
            'maxUploadBytes' => UploadLimits::phpMaxUploadBytes(),
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '');

        return response()->json([
            'items' => $this->files->listDirectory($path),
            'path' => $this->files->normalizePath($path),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '');

        if ($this->files->isBinaryFile($path)) {
            return response()->json([
                'binary' => true,
                'path' => $path,
                'message' => __('This is a binary file and cannot be edited. Use download instead.'),
            ]);
        }

        return response()->json([
            'binary' => false,
            'content' => $this->files->readFile($path),
            'language' => $this->files->getEditorLanguage($path),
            'path' => $path,
        ]);
    }

    public function write(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
            'content' => ['nullable', 'string'],
        ]);

        $this->files->writeFile($request->input('path'), $request->input('content') ?? '');
        $this->audit($request, 'file_saved', $request->input('path'));

        return response()->json(['success' => true]);
    }

    public function createFile(Request $request): JsonResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        $this->files->writeFile($request->input('path'), '');

        return response()->json(['success' => true]);
    }

    public function createDirectory(Request $request): JsonResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        $this->files->createDirectory($request->input('path'));

        return response()->json(['success' => true]);
    }

    public function upload(Request $request): JsonResponse
    {
        // Release the session lock during long uploads (web only; the API is stateless).
        if ($request->hasSession()) {
            $request->session()->save();
        }

        $maxKb = (int) (UploadLimits::phpMaxUploadBytes() / 1024);

        $request->validate([
            'directory' => ['nullable', 'string'],
            'files' => ['required', 'array'],
            'files.*' => ['file', "max:{$maxKb}"],
        ]);

        $directory = (string) $request->input('directory');
        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $uploaded[] = $this->files->upload($directory, $file);
        }

        return response()->json(['success' => true, 'files' => $uploaded]);
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
        ]);

        foreach ($request->input('paths') as $path) {
            $this->files->delete($path);
        }

        $this->audit($request, 'file_deleted', implode(', ', $request->input('paths')));

        return response()->json(['success' => true]);
    }

    public function rename(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string'],
        ]);

        $this->files->rename($request->input('from'), $request->input('to'));

        return response()->json(['success' => true]);
    }

    public function download(Request $request): StreamedResponse
    {
        $path = $this->files->normalizePath((string) $request->query('path', ''));
        $stream = $this->files->readStream($path);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($path));
    }

    public function compress(Request $request): JsonResponse
    {
        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
            'name' => ['required', 'string'],
            'directory' => ['nullable', 'string'],
        ]);

        $directory = $this->files->normalizePath((string) $request->input('directory'));
        $name = $request->input('name');

        if (! str_ends_with($name, '.zip')) {
            $name .= '.zip';
        }

        $zipPath = $directory ? $directory.'/'.$name : $name;
        $this->files->compress($request->input('paths'), $zipPath);

        return response()->json(['success' => true, 'path' => $zipPath]);
    }

    public function decompress(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
            'directory' => ['nullable', 'string'],
        ]);

        $this->files->decompress($request->input('path'), (string) $request->input('directory'));

        return response()->json(['success' => true]);
    }

    public function chmod(Request $request): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
            'mode' => ['required', 'string', 'regex:/^[0-7]{3,4}$/'],
        ]);

        if (! $this->files->chmod($request->input('path'), $request->input('mode'))) {
            return response()->json(['success' => false, 'message' => __('Failed to change permissions.')], 422);
        }

        return response()->json(['success' => true]);
    }

    private function audit(Request $request, string $action, string $path): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'summary' => "File Manager: {$path}",
        ]);
    }
}
