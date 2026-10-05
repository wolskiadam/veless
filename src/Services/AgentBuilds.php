<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Zbudowany program Veless (drukowanie) (Windows .exe / macOS .app w zip) leżący na serwerze.
 *
 * Pliki wgrywa GitHub Actions (workflow build-agent-app.yml) do storage/agent-builds/
 * razem z manifest.json: {"version": "2.0.0", "files": {"windows": {"file","sha256","size"}, "macos": {...}}}.
 * Katalog storage/ jest zablokowany dla WWW - pliki wydaje tylko PHP (panel albo agent z kluczem API).
 */
final class AgentBuilds
{
    public const PLATFORMS = [
        'windows' => ['label' => 'Windows', 'download_name' => 'Veless.exe'],
        'macos'   => ['label' => 'Mac (Apple Silicon)', 'download_name' => 'Veless-macos.zip'],
    ];

    public function __construct(private readonly string $root) {}

    public function dir(): string
    {
        return rtrim($this->root, '/') . '/storage/agent-builds';
    }

    /** @return array{version:string,files:array<string,array{file:string,sha256:string,size:int}>}|null */
    public function manifest(): ?array
    {
        $raw = @file_get_contents($this->dir() . '/manifest.json');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_string($data['version'] ?? null) || !is_array($data['files'] ?? null)) {
            return null;
        }
        return $data;
    }

    /** @return array{version:string,file:string,path:string,sha256:string,size:int}|null */
    public function build(string $platform): ?array
    {
        $m = $this->manifest();
        $f = $m['files'][$platform] ?? null;
        if (!isset(self::PLATFORMS[$platform]) || !is_array($f) || !preg_match('/^[A-Za-z0-9._-]+$/D', (string) ($f['file'] ?? ''))) {
            return null;
        }
        $path = $this->dir() . '/' . $f['file'];
        if (!is_file($path)) {
            return null;
        }
        return ['version' => (string) $m['version'], 'file' => (string) $f['file'], 'path' => $path,
            'sha256' => strtolower((string) ($f['sha256'] ?? '')), 'size' => (int) filesize($path)];
    }

    /** Wysyła plik programu do przeglądarki / agenta. */
    public static function stream(array $build, string $downloadName): never
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $build['size']);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($build['path']);
        exit;
    }

    /** Kod połączenia do wklejenia w programie: adres panelu + klucz API (CRM1:base64url(json)). */
    public static function connectionCode(string $baseUrl, string $apiKey): string
    {
        $json = json_encode(['u' => rtrim($baseUrl, '/'), 'k' => $apiKey], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return 'CRM1:' . rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }
}
