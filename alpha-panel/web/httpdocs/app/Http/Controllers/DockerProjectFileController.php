<?php

namespace App\Http\Controllers;

use App\Models\DockerProject;
use App\Services\LocalFileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DockerProjectFileController extends Controller
{
    public function index(DockerProject $dockerProject): Response
    {
        $maxUploadBytes = self::phpMaxUploadBytes();

        return Inertia::render('DockerProjects/Files', compact('dockerProject', 'maxUploadBytes'));
    }

    public function list(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $fm = $this->resolveFileManager($dockerProject);
        $path = $request->query('path', '');

        return response()->json([
            'items' => $fm->listDirectory($path),
            'path' => $fm->normalizePath($path),
        ]);
    }

    public function read(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $fm = $this->resolveFileManager($dockerProject);
        $path = $request->query('path', '');

        if ($fm->isBinaryFile($path)) {
            return response()->json([
                'binary' => true,
                'path' => $path,
                'message' => __('This is a binary file and cannot be edited. Use download instead.'),
            ]);
        }

        $content = $fm->readFile($path);
        $language = $fm->getEditorLanguage($path);

        return response()->json([
            'binary' => false,
            'content' => $content,
            'language' => $language,
            'path' => $path,
        ]);
    }

    public function write(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $request->validate([
            'path' => ['required', 'string'],
            'content' => ['required', 'string'],
        ]);

        $fm = $this->resolveFileManager($dockerProject);
        $fm->writeFile($request->input('path'), $request->input('content'));

        return response()->json(['success' => true]);
    }

    public function createDirectory(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        $fm = $this->resolveFileManager($dockerProject);
        $fm->createDirectory($request->input('path'));

        return response()->json(['success' => true]);
    }

    public function upload(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $maxKb = (int) (self::phpMaxUploadBytes() / 1024);

        $request->validate([
            'directory' => ['nullable', 'string'],
            'files' => ['required', 'array'],
            'files.*' => ['file', "max:{$maxKb}"],
        ]);

        $fm = $this->resolveFileManager($dockerProject);
        $directory = $request->input('directory', '');
        $uploaded = [];

        foreach ($request->file('files') as $file) {
            $uploaded[] = $fm->upload($directory, $file);
        }

        return response()->json(['success' => true, 'files' => $uploaded]);
    }

    public function delete(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => ['required', 'string'],
        ]);

        $fm = $this->resolveFileManager($dockerProject);

        foreach ($request->input('paths') as $path) {
            $fm->delete($path);
        }

        return response()->json(['success' => true]);
    }

    public function rename(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string'],
        ]);

        $fm = $this->resolveFileManager($dockerProject);
        $fm->rename($request->input('from'), $request->input('to'));

        return response()->json(['success' => true]);
    }

    public function download(Request $request, DockerProject $dockerProject): StreamedResponse
    {
        $fm = $this->resolveFileManager($dockerProject);
        $path = $request->query('path', '');
        $normalizedPath = $fm->normalizePath($path);
        $filename = basename($normalizedPath);

        $stream = $fm->readStream($normalizedPath);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $filename);
    }

    public function createFile(Request $request, DockerProject $dockerProject): JsonResponse
    {
        $request->validate(['path' => ['required', 'string']]);

        $fm = $this->resolveFileManager($dockerProject);
        $fm->writeFile($request->input('path'), '');

        return response()->json(['success' => true]);
    }

    private function resolveFileManager(DockerProject $dockerProject): LocalFileManagerService
    {
        $path = $dockerProject->projectPath();

        if (! is_dir($path)) {
            throw new RuntimeException(__('Project directory does not exist.'));
        }

        return new LocalFileManagerService($path);
    }

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
