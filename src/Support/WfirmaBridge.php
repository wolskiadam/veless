<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Odczyt dokumentów z wFirma na kluczach integracji CRM - dla rozszerzeń (Księgowość porównuje księgi z wFirma).
 * Klucze nie wychodzą z rdzenia: rozszerzenie dostaje tylko odpowiedź API. Dozwolone są wyłącznie zapytania
 * do odczytu (dane firmy, wyszukiwanie faktur i wydatków), więc rozszerzenie nie wystawi ani nie usunie dokumentu.
 * Zapytanie o konto jest w sandboxie wtyczek na liście odczytów rdzenia (docker/sandbox/report.php).
 */
final class WfirmaBridge
{
    public const BASE_URL = 'https://api2.wfirma.pl';
    /** Ścieżka => metoda HTTP; `companies/get` z ID firmy z konta integracji. */
    private const ALLOWED = ['companies/get' => 'GET', 'invoices/find' => 'POST', 'expenses/find' => 'POST'];

    /** @return array<string,mixed>|null konfiguracja pierwszego aktywnego konta wFirma (z odszyfrowanymi kluczami) */
    private static function config(PDO $pdo): ?array
    {
        try {
            $st = $pdo->prepare("SELECT config FROM integration_accounts WHERE type = 'wfirma' AND is_active = 1 ORDER BY id LIMIT 1");
            $st->execute();
            $raw = $st->fetchColumn();
        } catch (\PDOException) {
            return null;
        }
        if ($raw === false) {
            return null;
        }
        $cfg = SecretStore::openConfig(json_decode((string) $raw, true) ?: [], false);
        foreach (['access_key', 'secret_key', 'app_key', 'company_id'] as $k) {
            if (trim((string) ($cfg[$k] ?? '')) === '') {
                return null;
            }
        }
        return $cfg;
    }

    /** Czy w CRM jest aktywna integracja wFirma z kompletem kluczy. */
    public static function available(PDO $pdo): bool
    {
        return self::config($pdo) !== null;
    }

    /**
     * Zapytanie do API wFirma (tylko odczyt) na kluczach integracji CRM.
     * @return array{status:int,json:array}
     */
    public static function call(PDO $pdo, string $path, ?array $body = null): array
    {
        if (!isset(self::ALLOWED[$path])) {
            throw new \RuntimeException('Przez CRM można tylko czytać z wFirma (dane firmy, faktury, wydatki).');
        }
        $cfg = self::config($pdo) ?? throw new \RuntimeException('W CRM nie ma aktywnej integracji wFirma z kluczami API (Integracje → wFirma).');
        $company = trim((string) $cfg['company_id']);
        $base = rtrim(trim((string) ($cfg['base_url'] ?? '')) ?: self::BASE_URL, '/');
        $url = $base . '/' . $path . ($path === 'companies/get' ? '/' . rawurlencode($company) : '')
            . '?inputFormat=json&outputFormat=json&company_id=' . rawurlencode($company);
        $method = self::ALLOWED[$path];
        $r = Http::request($method, $url, [
            'accessKey' => trim((string) $cfg['access_key']), 'secretKey' => trim((string) $cfg['secret_key']), 'appKey' => trim((string) $cfg['app_key']),
            'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ], $method === 'POST' ? ($body ?? []) : null, 60);
        Logger::apiResponse('wfirma', $method, $url, $r->status);
        return ['status' => $r->status, 'json' => $r->json()];
    }
}
