<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\FileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManagerController extends Controller
{
    /**
     * Show the file manager page.
     */
    public function index(Domain $domain): \Illuminate\Http\RedirectResponse|Response
    {
        $this->authorize('view', $domain);

        $domain->load('ftpUser');

        if (! $domain->ftpUser || ! $domain->ftpUser->hasPassword()) {
            return redirect()->route('domains.show', $domain)
                ->with('error', __('FTP user with a stored password is required to use the file manager. Please create or update the FTP user password.'));
        }

        $maxUploadBytes = self::phpMaxUploadBytes();

        return Inertia::render('Domains/FileManager', compact('domain', 'maxUploadBytes'));
    }

    /**
     * List directory contents.
     */
    public function list(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('view', $domain);

        $fileManager = $this->resolveFileManager($domain);
        $path = $request->query('path', '');

        return response()->json([
            'items' => $fileManager->listDirectory($path),
            'path' => $fileManager->normalizePath($path),
        ]);
    }

    /**
     * Read file contents for the editor.
     */
    public function read(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('view', $domain);

        $fileManager = $this->resolveFileManager($domain);
        $path = $request->query('path', '');

        if ($fileManager->isBinaryFile($path)) {
            return response()->json([
                'binary' => true,
                'path' => $path,
                'message' => __('This is a binary file and cannot be edited. Use download instead.'),
            ]);
        }

        $content = $fileManager->readFile($path);
        $language = $fileManager->getEditorLanguage($path);

        return response()->json([
            'binary' => false,
            'content' => $content,
            'language' => $language,
            'path' => $path,
        ]);
    }

    /**
     * Save file contents.
     */
    public function write(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'path' => ['required', 'string'],
            'content' => ['required', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $fileManager->writeFile($request->input('path'), $request->input('content'));

        return response()->json(['success' => true]);
    }

    /**
     * Create a new directory.
     */
    public function createDirectory(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'path' => ['required', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $fileManager->createDirectory($request->input('path'));

        return response()->json(['success' => true]);
    }

    /**
     * Upload files.
     */
    public function upload(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $maxKb = (int) (self::phpMaxUploadBytes() / 1024);

        $request->validate([
            'directory' => ['nullable', 'string'],
            'files' => ['required', 'array'],
            'files.*' => ['file', "max:{$maxKb}"],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $directory = $request->input('directory', '');
        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $uploaded[] = $fileManager->upload($directory, $file);
        }

        return response()->json(['success' => true, 'files' => $uploaded]);
    }

    /**
     * Delete one or more files/directories.
     */
    public function delete(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);

        foreach ($request->input('paths') as $path) {
            $fileManager->delete($path);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Rename a file or directory.
     */
    public function rename(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $fileManager->rename($request->input('from'), $request->input('to'));

        return response()->json(['success' => true]);
    }

    /**
     * Download a file.
     */
    public function download(Request $request, Domain $domain): StreamedResponse
    {
        $this->authorize('view', $domain);

        $fileManager = $this->resolveFileManager($domain);
        $path = $request->query('path', '');
        $normalizedPath = $fileManager->normalizePath($path);
        $filename = basename($normalizedPath);

        $stream = $fileManager->readStream($normalizedPath);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $filename);
    }

    /**
     * Compress selected files/directories into a zip archive.
     */
    public function compress(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
            'name' => ['required', 'string'],
            'directory' => ['nullable', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $directory = $fileManager->normalizePath($request->input('directory', ''));
        $name = $request->input('name');

        if (! str_ends_with($name, '.zip')) {
            $name .= '.zip';
        }

        $zipPath = $directory ? $directory.'/'.$name : $name;

        $fileManager->compress($request->input('paths'), $zipPath);

        return response()->json(['success' => true, 'path' => $zipPath]);
    }

    /**
     * Decompress a zip archive.
     */
    public function decompress(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'path' => ['required', 'string'],
            'directory' => ['nullable', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $zipPath = $request->input('path');
        $directory = $request->input('directory', '');

        $fileManager->decompress($zipPath, $directory);

        return response()->json(['success' => true]);
    }

    /**
     * Change file/directory permissions.
     */
    public function chmod(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'path' => ['required', 'string'],
            'mode' => ['required', 'string', 'regex:/^[0-7]{3,4}$/'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $result = $fileManager->chmod($request->input('path'), $request->input('mode'));

        if (! $result) {
            return response()->json(['success' => false, 'message' => __('Failed to change permissions.')], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Create a new empty file.
     */
    public function createFile(Request $request, Domain $domain): JsonResponse
    {
        $this->authorize('update', $domain);

        $request->validate([
            'path' => ['required', 'string'],
        ]);

        $fileManager = $this->resolveFileManager($domain);
        $fileManager->writeFile($request->input('path'), '');

        return response()->json(['success' => true]);
    }

    /**
     * Resolve the FileManagerService for a domain.
     */
    private function resolveFileManager(Domain $domain): FileManagerService
    {
        $domain->loadMissing('ftpUser');

        if (! $domain->ftpUser) {
            throw new RuntimeException(__('No FTP user configured for this domain.'));
        }

        if (! $domain->ftpUser->hasPassword()) {
            throw new RuntimeException(__('FTP password not stored. Please update the FTP user password.'));
        }

        return FileManagerService::forUser($domain->ftpUser);
    }

    /**
     * Get the effective PHP max upload size in bytes.
     */
    private static function phpMaxUploadBytes(): int
    {
        $parse = static function (string $value): int {
            $value = trim($value);
            $last = strtolower($value[strlen($value) - 1]);
            $num = (int) $value;

            return match ($last) {
                'g' => $num * 1024 * 1024 * 1024,
                'm' => $num * 1024 * 1024,
                'k' => $num * 1024,
                default => $num,
            };
        };

        return min(
            $parse(ini_get('upload_max_filesize') ?: '2M'),
            $parse(ini_get('post_max_size') ?: '8M'),
        );
    }
}
