<?php

/**
 * File Manager Worker — runs as the FTP user via `runuser -u`.
 *
 * Invoked by LocalDomainFileManagerService through proc_open. All filesystem
 * operations execute with the calling user's UID so ownership and permissions
 * are enforced by the kernel — no chown, no privilege juggling. Worker exits
 * with code 0 on success, 1 on error; error messages go to stderr, payload
 * data goes to stdout (JSON for structured, raw bytes for `read`).
 *
 * CLI args use `--key=value` form; arrays are JSON-encoded.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message);
    exit($code);
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }
        $kv = substr($arg, 2);
        $pos = strpos($kv, '=');
        if ($pos === false) {
            $out[$kv] = true;
        } else {
            $out[substr($kv, 0, $pos)] = substr($kv, $pos + 1);
        }
    }

    return $out;
}

function normalizeRelative(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?? $path;

    $parts = explode('/', $path);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            continue;
        }
        $out[] = $p;
    }

    return implode('/', $out);
}

function realpathNorm(string $path): false|string
{
    $r = realpath($path);

    return $r === false ? false : str_replace('\\', '/', $r);
}

function resolvePath(string $root, string $realRoot, string $relative, bool $allowMissing = true): string
{
    $relative = normalizeRelative($relative);
    $full = $relative === '' ? $root : $root.'/'.$relative;

    $real = realpathNorm($full);

    if ($real !== false) {
        if (! ($real === $realRoot || str_starts_with($real, $realRoot.'/'))) {
            fail("Path escapes root: {$relative}");
        }

        return $real;
    }

    if (! $allowMissing) {
        fail("Path not found: {$relative}");
    }

    $parentReal = realpathNorm(dirname($full));
    if ($parentReal === false || ! ($parentReal === $realRoot || str_starts_with($parentReal, $realRoot.'/'))) {
        fail("Parent path not accessible: {$relative}");
    }

    return $parentReal.'/'.basename($full);
}

function permissionsString(int $mode): string
{
    $perms = '';
    $map = [
        [0400, 'r'], [0200, 'w'], [0100, 'x'],
        [0040, 'r'], [0020, 'w'], [0010, 'x'],
        [0004, 'r'], [0002, 'w'], [0001, 'x'],
    ];
    foreach ($map as [$bit, $char]) {
        $perms .= ($mode & $bit) ? $char : '-';
    }
    if ($mode & 04000) {
        $perms[2] = ($mode & 0100) ? 's' : 'S';
    }
    if ($mode & 02000) {
        $perms[5] = ($mode & 0010) ? 's' : 'S';
    }
    if ($mode & 01000) {
        $perms[8] = ($mode & 0001) ? 't' : 'T';
    }

    return $perms;
}

function statEntry(string $name, string $fullPath, string $relativePath): array
{
    $isLink = is_link($fullPath);
    $isDir = is_dir($fullPath);
    $stat = @lstat($fullPath);
    $mode = $stat['mode'] ?? 0;
    $size = ($isDir || $isLink) ? null : (is_file($fullPath) ? @filesize($fullPath) : null);
    if ($size === false) {
        $size = null;
    }

    return [
        'name' => $name,
        'path' => $relativePath,
        'type' => ($isDir || ($isLink && is_dir($fullPath))) ? 'directory' : 'file',
        'size' => $size,
        'lastModified' => $stat['mtime'] ?? null,
        'permissions' => permissionsString($mode),
        'permissionsOctal' => sprintf('%04o', $mode & 07777),
    ];
}

function deleteRecursive(string $fullPath): void
{
    if (is_dir($fullPath) && ! is_link($fullPath)) {
        $entries = @scandir($fullPath) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            deleteRecursive($fullPath.'/'.$e);
        }
        if (! @rmdir($fullPath)) {
            fail("Failed to remove directory: {$fullPath}");
        }

        return;
    }

    if (! @unlink($fullPath)) {
        fail("Failed to delete: {$fullPath}");
    }
}

function addPathToZip(ZipArchive $zip, string $fullPath, string $entryPath): void
{
    if (is_dir($fullPath) && ! is_link($fullPath)) {
        $zip->addEmptyDir($entryPath);
        $entries = @scandir($fullPath) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            addPathToZip($zip, $fullPath.'/'.$e, $entryPath.'/'.$e);
        }

        return;
    }

    $zip->addFile($fullPath, $entryPath);
}

$args = parseArgs($argv);
$root = $args['root'] ?? null;
$action = $args['action'] ?? null;

if (! is_string($root) || $root === '' || ! is_dir($root)) {
    fail("Invalid --root: {$root}");
}
$root = str_replace('\\', '/', $root);
$realRoot = realpathNorm($root);
if ($realRoot === false) {
    fail("Failed to resolve --root: {$root}");
}

if (! is_string($action) || $action === '') {
    fail('Missing --action');
}

switch ($action) {
    case 'list':
        $path = $args['path'] ?? '';
        $full = resolvePath($root, $realRoot, $path, allowMissing: false);
        if (! is_dir($full)) {
            fail("Not a directory: {$path}");
        }

        $entries = @scandir($full);
        if ($entries === false) {
            fail("Failed to read directory: {$path}");
        }

        $relBase = normalizeRelative($path);
        $items = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $childFull = $full.'/'.$name;
            $childRel = $relBase === '' ? $name : $relBase.'/'.$name;
            $items[] = statEntry($name, $childFull, $childRel);
        }

        usort($items, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        echo json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);

    case 'stat':
        $path = $args['path'] ?? '';
        $full = resolvePath($root, $realRoot, $path, allowMissing: false);
        $rel = normalizeRelative($path);
        echo json_encode(statEntry(basename($full), $full, $rel), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);

    case 'read':
        $path = $args['path'] ?? '';
        $full = resolvePath($root, $realRoot, $path, allowMissing: false);
        if (! is_file($full)) {
            fail("Not a file: {$path}");
        }
        $fp = @fopen($full, 'rb');
        if ($fp === false) {
            fail("Failed to open: {$path}");
        }
        while (! feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
        }
        fclose($fp);
        exit(0);

    case 'write':
        $path = $args['path'] ?? '';
        $full = resolvePath($root, $realRoot, $path);
        $fp = @fopen($full, 'wb');
        if ($fp === false) {
            fail("Failed to open for write: {$path}");
        }
        while (! feof(STDIN)) {
            $chunk = fread(STDIN, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if (@fwrite($fp, $chunk) === false) {
                fclose($fp);
                fail("Failed to write: {$path}");
            }
        }
        fclose($fp);
        exit(0);

    case 'mkdir':
        $path = $args['path'] ?? '';
        $full = resolvePath($root, $realRoot, $path);
        if (is_dir($full)) {
            exit(0);
        }
        if (! @mkdir($full, 0755, true) && ! is_dir($full)) {
            fail("Failed to mkdir: {$path}");
        }
        exit(0);

    case 'delete':
        $pathsJson = $args['paths'] ?? '[]';
        $paths = json_decode($pathsJson, true);
        if (! is_array($paths)) {
            fail('Invalid --paths JSON');
        }
        foreach ($paths as $p) {
            if (! is_string($p)) {
                continue;
            }
            $full = resolvePath($root, $realRoot, $p, allowMissing: false);
            deleteRecursive($full);
        }
        exit(0);

    case 'rename':
        $from = $args['from'] ?? '';
        $to = $args['to'] ?? '';
        $fromFull = resolvePath($root, $realRoot, $from, allowMissing: false);
        $toFull = resolvePath($root, $realRoot, $to);
        if (! @rename($fromFull, $toFull)) {
            fail("Failed to rename: {$from} → {$to}");
        }
        exit(0);

    case 'chmod':
        $path = $args['path'] ?? '';
        $mode = $args['mode'] ?? '';
        if (! preg_match('/^[0-7]{3,4}$/', (string) $mode)) {
            fail("Invalid mode: {$mode}");
        }
        $full = resolvePath($root, $realRoot, $path, allowMissing: false);
        $octal = octdec((string) $mode);
        if (! is_int($octal) || ! @chmod($full, $octal)) {
            fail("chmod failed: {$path}");
        }
        exit(0);

    case 'compress':
        $pathsJson = $args['paths'] ?? '[]';
        $paths = json_decode($pathsJson, true);
        if (! is_array($paths)) {
            fail('Invalid --paths JSON');
        }
        $zipPath = $args['zip'] ?? '';
        $zipFull = resolvePath($root, $realRoot, $zipPath);

        $zip = new ZipArchive;
        if ($zip->open($zipFull, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fail("Failed to create zip: {$zipPath}");
        }

        foreach ($paths as $p) {
            if (! is_string($p)) {
                continue;
            }
            $itemFull = resolvePath($root, $realRoot, $p, allowMissing: false);
            addPathToZip($zip, $itemFull, basename($itemFull));
        }
        $zip->close();
        exit(0);

    case 'decompress':
        $zipPath = $args['zip'] ?? '';
        $destDir = normalizeRelative($args['dest'] ?? '');
        $zipFull = resolvePath($root, $realRoot, $zipPath, allowMissing: false);

        $destFull = $destDir === '' ? $realRoot : resolvePath($root, $realRoot, $destDir);
        if (! is_dir($destFull) && ! @mkdir($destFull, 0755, true) && ! is_dir($destFull)) {
            fail("Failed to create destination: {$destDir}");
        }

        $zip = new ZipArchive;
        if ($zip->open($zipFull) !== true) {
            fail("Failed to open zip: {$zipPath}");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false || str_contains($entryName, '..')) {
                continue;
            }
            $targetRel = ($destDir === '' ? '' : $destDir.'/').$entryName;
            $targetFull = resolvePath($root, $realRoot, $targetRel);

            if (str_ends_with($entryName, '/')) {
                if (! is_dir($targetFull) && ! @mkdir($targetFull, 0755, true) && ! is_dir($targetFull)) {
                    fail("Failed to mkdir during extract: {$entryName}");
                }

                continue;
            }

            $parent = dirname($targetFull);
            if (! is_dir($parent)) {
                @mkdir($parent, 0755, true);
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            if (@file_put_contents($targetFull, $contents) === false) {
                fail("Failed to extract: {$entryName}");
            }
        }
        $zip->close();
        exit(0);

    case 'move-uploaded':
        $tmpSrc = $args['src'] ?? '';
        $destPath = $args['dest'] ?? '';
        if (! is_string($tmpSrc) || ! is_file($tmpSrc) || ! is_readable($tmpSrc)) {
            fail("Source not readable: {$tmpSrc}");
        }
        $destFull = resolvePath($root, $realRoot, $destPath);

        // copy + unlink (cross-filesystem-safe; rename fails between mounts)
        if (! @copy($tmpSrc, $destFull)) {
            fail("Failed to copy upload to: {$destPath}");
        }
        @unlink($tmpSrc);
        exit(0);

    case 'exists':
        $path = $args['path'] ?? '';
        try {
            $full = resolvePath($root, $realRoot, $path, allowMissing: false);
            echo file_exists($full) ? '1' : '0';
        } catch (Throwable) {
            echo '0';
        }
        exit(0);

    default:
        fail("Unknown action: {$action}");
}
