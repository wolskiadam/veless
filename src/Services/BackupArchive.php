<?php
declare(strict_types=1);
namespace Pase\Services;

use ZipArchive;

final class BackupArchive
{
    private static function name(string $name): void
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, ':')
            || preg_match('/[\x00-\x1f]/', $name) || in_array('..', explode('/', $name), true)
            || in_array('.', explode('/', $name), true) || str_contains($name, '//') || str_ends_with($name, '/')) {
            throw new \RuntimeException('Unsafe backup entry');
        }
    }

    private static function password(string $key): void
    {
        if (strlen($key) < 32) { throw new \RuntimeException('Backup key must have at least 32 characters'); }
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new \RuntimeException('Backup requires ext-zip with AES-256 support');
        }
    }

    /** @param array<string,string> $files logical path => source absolute path */
    public static function create(string $destination, array $files, string $key): void
    {
        self::password($key);
        if (file_exists($destination) || is_link($destination)) { throw new \RuntimeException('Backup destination already exists'); }
        $partial = $destination . '.partial-' . bin2hex(random_bytes(8));
        $zip = new ZipArchive(); $opened = false;
        try {
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new \RuntimeException('Cannot create backup'); }
            $opened = true;
            $zip->setPassword($key);
            $manifest = ['format' => 'crm-backup-v1', 'created_at' => gmdate('c'), 'files' => []];
            foreach ($files as $name => $path) {
                self::name($name);
                if ($name === 'manifest.json' || !is_file($path) || is_link($path)) { throw new \RuntimeException('Invalid backup source'); }
                $hash = hash_file('sha256', $path); $size = filesize($path);
                if ($hash === false || $size === false || !$zip->addFile($path, $name)
                    || !$zip->setEncryptionName($name, ZipArchive::EM_AES_256)) { throw new \RuntimeException('Cannot add encrypted backup entry'); }
                $manifest['files'][$name] = ['size' => $size, 'sha256' => $hash];
            }
            if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR))
                || !$zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256)) { throw new \RuntimeException('Cannot encrypt backup manifest'); }
            if (!$zip->close()) { throw new \RuntimeException('Cannot finalize backup'); }
            $opened = false; chmod($partial, 0600);
            self::verify($partial, $key);
            // Atomic no-overwrite publication on the same filesystem.
            if (!link($partial, $destination)) { throw new \RuntimeException('Cannot publish backup without overwriting'); }
        } finally {
            if ($opened) { $zip->close(); }
            if (is_file($partial)) { unlink($partial); }
        }
    }

    /** Validates every decrypted byte; optionally restores into a NEW private directory only. */
    public static function verify(string $archive, string $key, ?string $restoreDirectory = null): array
    {
        self::password($key);
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Cannot open backup'); }
        $zip->setPassword($key);
        try {
            $stat = $zip->statName('manifest.json');
            if (!$stat || $stat['size'] > 16 * 1024 * 1024) { throw new \RuntimeException('Missing/oversized backup manifest'); }
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) { throw new \RuntimeException('Wrong backup key or damaged archive'); }
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['format'] ?? '') !== 'crm-backup-v1' || !is_array($manifest['files'] ?? null)
                || $zip->numFiles !== count($manifest['files']) + 1) { throw new \RuntimeException('Invalid backup manifest'); }
            $seen = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i); $name = $entry['name']; self::name($name);
                if (isset($seen[$name]) || ($entry['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) { throw new \RuntimeException('Duplicate or unencrypted entry'); }
                $seen[$name] = true; $total += $entry['size'];
                if ($total > 20 * 1024 * 1024 * 1024) { throw new \RuntimeException('Backup exceeds 20 GiB restore limit'); }
            }
            // No extractTo(): explicit paths and streaming prevent traversal and symlink restoration.
            if ($restoreDirectory !== null && (file_exists($restoreDirectory) || is_link($restoreDirectory)
                || !is_dir(dirname($restoreDirectory)) || !mkdir($restoreDirectory, 0700))) {
                throw new \RuntimeException('Restore requires a new directory with an existing parent');
            }
            foreach ($manifest['files'] as $name => $expected) {
                self::name($name);
                if ($name === 'manifest.json' || !isset($seen[$name])) { throw new \RuntimeException('Missing backup entry'); }
                $stream = $zip->getStream($name);
                if (!$stream) { throw new \RuntimeException('Cannot decrypt backup entry'); }
                $out = null;
                try {
                    if ($restoreDirectory !== null) {
                        $path = $restoreDirectory . '/' . $name;
                        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) { throw new \RuntimeException('Cannot create restore directory'); }
                        $out = fopen($path, 'xb');
                        if (!$out) { throw new \RuntimeException('Cannot restore file'); }
                        chmod($path, 0600);
                    }
                    $hash = hash_init('sha256'); $bytes = 0;
                    while (!feof($stream)) {
                        $chunk = fread($stream, 1024 * 1024);
                        if ($chunk === false) { throw new \RuntimeException('Backup read failed'); }
                        if ($chunk === '' && !feof($stream)) { throw new \RuntimeException('Backup stream stalled'); }
                        $bytes += strlen($chunk); hash_update($hash, $chunk);
                        if ($out && fwrite($out, $chunk) !== strlen($chunk)) { throw new \RuntimeException('Restore write failed'); }
                    }
                    if ($bytes !== $expected['size'] || !hash_equals($expected['sha256'], hash_final($hash))) { throw new \RuntimeException('Backup checksum mismatch'); }
                } finally { fclose($stream); if ($out) { fclose($out); } }
            }
            return $manifest;
        } finally { $zip->close(); }
    }
}
