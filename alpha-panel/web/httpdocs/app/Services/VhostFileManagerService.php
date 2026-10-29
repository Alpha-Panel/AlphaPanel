<?php

namespace App\Services;

use App\Models\FtpUser;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Admin file manager over the whole vhosts tree (replaces the old code-server workspace).
 *
 * Paths are relative to the vhosts root. Each operation is routed to the FTP user whose
 * homedir is the longest prefix of the path, so files are created and changed as that
 * user (correct ownership, kernel-enforced). Paths outside every homedir (the vhosts
 * root itself, orphan folders) are handled as the panel process user.
 */
class VhostFileManagerService
{
    /** @var array<string, FtpUser>|null homedir relative to the root => user */
    private ?array $homes = null;

    public function __construct(private readonly string $root = '/var/www/vhosts') {}

    public function normalizePath(string $path): string
    {
        $parts = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..',
        );

        return implode('/', $parts);
    }

    /**
     * Longest homedir (relative to the root) that contains $path, or null.
     *
     * @param  array<int, string>  $homes
     */
    public static function matchHome(string $path, array $homes): ?string
    {
        $best = null;

        foreach ($homes as $home) {
            if ($home === '' || ($path !== $home && ! str_starts_with($path, $home.'/'))) {
                continue;
            }

            if ($best === null || strlen($home) > strlen($best)) {
                $best = $home;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array{name: string, path: string, type: string, size: int|null, lastModified: int|null, permissions: string, permissionsOctal: string|null}>
     */
    public function listDirectory(string $path = ''): array
    {
        [$service, $inner, $prefix] = $this->resolve($path);

        return array_map(function (array $item) use ($prefix): array {
            $item['path'] = $this->join($prefix, $item['path']);

            return $item;
        }, $service->listDirectory($inner));
    }

    public function readFile(string $path): string
    {
        [$service, $inner] = $this->resolve($path);

        return $service->readFile($inner);
    }

    public function writeFile(string $path, string $content): void
    {
        [$service, $inner] = $this->resolve($path);
        $service->writeFile($inner, $content);
    }

    public function createDirectory(string $path): void
    {
        [$service, $inner] = $this->resolve($path);
        $service->createDirectory($inner);
    }

    public function delete(string $path): void
    {
        [$service, $inner, $prefix] = $this->resolve($path);

        if ($inner === '' && $prefix !== '') {
            throw new RuntimeException(__('A domain home directory cannot be deleted from the file manager.'));
        }

        $service->delete($inner);
    }

    public function rename(string $from, string $to): void
    {
        [$service, $innerFrom, $prefix] = $this->resolve($from);
        [, $innerTo, $prefixTo] = $this->resolve($to);

        if ($prefix !== $prefixTo || ($innerFrom === '' && $prefix !== '')) {
            throw new RuntimeException(__('Items can only be renamed or moved inside the same domain.'));
        }

        $service->rename($innerFrom, $innerTo);
    }

    public function upload(string $directory, UploadedFile $file): string
    {
        [$service, $inner, $prefix] = $this->resolve($directory);

        return $this->join($prefix, $service->upload($inner, $file));
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        [$service, $inner] = $this->resolve($path);

        return $service->readStream($inner);
    }

    public function isBinaryFile(string $path): bool
    {
        [$service, $inner] = $this->resolve($path);

        return $service->isBinaryFile($inner);
    }

    public function getEditorLanguage(string $path): string
    {
        [$service, $inner] = $this->resolve($path);

        return $service->getEditorLanguage($inner);
    }

    public function chmod(string $path, string $mode): bool
    {
        [$service, $inner] = $this->domainScoped($path);

        return $service->chmod($inner, $mode);
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function compress(array $paths, string $zipPath): void
    {
        [$service, $innerZip, $prefix] = $this->domainScoped($zipPath);

        $inner = array_map(function (string $path) use ($prefix): string {
            [, $innerPath, $pathPrefix] = $this->resolve($path);

            if ($pathPrefix !== $prefix) {
                throw new RuntimeException(__('All selected items must belong to the same domain.'));
            }

            return $innerPath;
        }, $paths);

        $service->compress($inner, $innerZip);
    }

    public function decompress(string $zipPath, string $destinationDir): void
    {
        [$service, $innerZip, $prefix] = $this->domainScoped($zipPath);
        [, $innerDest, $destPrefix] = $this->resolve($destinationDir);

        if ($destPrefix !== $prefix) {
            throw new RuntimeException(__('All selected items must belong to the same domain.'));
        }

        $service->decompress($innerZip, $innerDest);
    }

    /**
     * @return array{0: LocalDomainFileManagerService|LocalFileManagerService, 1: string, 2: string}
     */
    private function resolve(string $path): array
    {
        $path = $this->normalizePath($path);
        $homes = $this->homes();
        $home = self::matchHome($path, array_keys($homes));

        if ($home === null) {
            return [new LocalFileManagerService($this->root), $path, ''];
        }

        $inner = $path === $home ? '' : substr($path, strlen($home) + 1);

        return [LocalDomainFileManagerService::forUser($homes[$home]), $inner, $home];
    }

    /**
     * chmod/zip run through the per-user worker only.
     *
     * @return array{0: LocalDomainFileManagerService, 1: string, 2: string}
     */
    private function domainScoped(string $path): array
    {
        [$service, $inner, $prefix] = $this->resolve($path);

        if (! $service instanceof LocalDomainFileManagerService) {
            throw new RuntimeException(__('This action is only available inside a domain directory.'));
        }

        return [$service, $inner, $prefix];
    }

    /**
     * @return array<string, FtpUser>
     */
    private function homes(): array
    {
        if ($this->homes !== null) {
            return $this->homes;
        }

        $rootPrefix = rtrim($this->root, '/').'/';
        $this->homes = [];

        foreach (FtpUser::query()->whereNotNull('homedir')->get() as $user) {
            $homedir = rtrim((string) $user->homedir, '/');

            if (str_starts_with($homedir.'/', $rootPrefix) && $homedir.'/' !== $rootPrefix) {
                $this->homes[$this->normalizePath(substr($homedir, strlen($rootPrefix)))] = $user;
            }
        }

        return $this->homes;
    }

    private function join(string $prefix, string $path): string
    {
        return $prefix === '' ? $path : ($path === '' ? $prefix : $prefix.'/'.$path);
    }
}
