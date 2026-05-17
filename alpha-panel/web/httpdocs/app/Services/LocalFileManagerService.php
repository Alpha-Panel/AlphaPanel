<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\StorageAttributes;

class LocalFileManagerService
{
    private Filesystem $filesystem;

    public function __construct(private readonly string $rootPath)
    {
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($rootPath));
    }

    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '..' || $part === '' || $part === '.') {
                continue;
            }
            $normalized[] = $part;
        }

        return implode('/', $normalized);
    }

    /**
     * @return array<int, array{name: string, path: string, type: string, size: int|null, lastModified: int|null, permissions: string, permissionsOctal: string|null}>
     */
    public function listDirectory(string $path = ''): array
    {
        $path = $this->normalizePath($path);
        $items = [];

        /** @var StorageAttributes $item */
        foreach ($this->filesystem->listContents($path, false) as $item) {
            $itemPath = $item->path();
            $name = basename($itemPath);
            $size = null;

            if ($item instanceof FileAttributes) {
                $size = $item->fileSize();
            }

            $items[] = [
                'name' => $name,
                'path' => $itemPath,
                'type' => $item->isDir() ? 'directory' : 'file',
                'size' => $size,
                'lastModified' => $item->lastModified(),
                'permissions' => '',
                'permissionsOctal' => null,
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $items;
    }

    public function readFile(string $path): string
    {
        return $this->filesystem->read($this->normalizePath($path));
    }

    public function writeFile(string $path, string $content): void
    {
        $this->filesystem->write($this->normalizePath($path), $content);
    }

    public function createDirectory(string $path): void
    {
        $this->filesystem->createDirectory($this->normalizePath($path));
    }

    public function delete(string $path): void
    {
        $path = $this->normalizePath($path);

        if ($this->filesystem->directoryExists($path)) {
            $this->filesystem->deleteDirectory($path);
        } else {
            $this->filesystem->delete($path);
        }
    }

    public function rename(string $from, string $to): void
    {
        $this->filesystem->move($this->normalizePath($from), $this->normalizePath($to));
    }

    public function upload(string $directory, UploadedFile $file): string
    {
        $directory = $this->normalizePath($directory);
        $filename = $file->getClientOriginalName();
        $path = $directory ? "{$directory}/{$filename}" : $filename;

        $this->filesystem->write($path, $file->getContent());

        return $path;
    }

    /** @return resource */
    public function readStream(string $path)
    {
        return $this->filesystem->readStream($this->normalizePath($path));
    }

    public function exists(string $path): bool
    {
        $path = $this->normalizePath($path);

        return $this->filesystem->fileExists($path) || $this->filesystem->directoryExists($path);
    }

    public function mimeType(string $path): string
    {
        return $this->filesystem->mimeType($this->normalizePath($path));
    }

    public function isBinaryFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $binaryExtensions = [
            'png', 'jpg', 'jpeg', 'gif', 'bmp', 'ico', 'svg', 'webp', 'avif',
            'mp3', 'mp4', 'avi', 'mkv', 'mov', 'wav', 'ogg', 'flac',
            'zip', 'tar', 'gz', 'bz2', 'rar', '7z', 'xz',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'exe', 'dll', 'so', 'dylib', 'bin', 'dat',
            'ttf', 'otf', 'woff', 'woff2', 'eot',
            'sqlite', 'db',
        ];

        return in_array($extension, $binaryExtensions);
    }

    public function getEditorLanguage(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $languageMap = [
            'php' => 'php',
            'js' => 'javascript',
            'mjs' => 'javascript',
            'cjs' => 'javascript',
            'ts' => 'typescript',
            'tsx' => 'typescript',
            'jsx' => 'javascript',
            'json' => 'json',
            'html' => 'html',
            'htm' => 'html',
            'css' => 'css',
            'scss' => 'scss',
            'less' => 'less',
            'xml' => 'xml',
            'yaml' => 'yaml',
            'yml' => 'yaml',
            'md' => 'markdown',
            'sql' => 'sql',
            'sh' => 'shell',
            'bash' => 'shell',
            'py' => 'python',
            'rb' => 'ruby',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'c' => 'c',
            'cpp' => 'cpp',
            'h' => 'c',
            'vue' => 'html',
            'env' => 'ini',
            'ini' => 'ini',
            'conf' => 'ini',
            'log' => 'plaintext',
            'txt' => 'plaintext',
            'htaccess' => 'ini',
            'gitignore' => 'plaintext',
            'dockerfile' => 'dockerfile',
            'twig' => 'twig',
        ];

        if (str_ends_with($path, '.blade.php')) {
            return 'html';
        }

        return $languageMap[$extension] ?? 'plaintext';
    }
}
