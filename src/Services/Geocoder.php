<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Geokodowanie adresów (kod pocztowy / miasto -> współrzędne) przez Nominatim (OpenStreetMap).
 * Wynik trafia do tabeli geocode_cache, więc OSM odpytujemy najwyżej raz na unikalny adres.
 *
 * Nominatim wymaga nagłówka User-Agent z kontaktem (polityka usługi) i max ~1 req/s.
 * Dlatego cache jest obowiązkowy, a publiczna strona klienta i tak nie powinna
 * generować ruchu większego niż liczba unikalnych miast/kodów.
 *
 * Zwraca ['lat'=>float,'lon'=>float] albo null (nie znaleziono / błąd sieci).
 */
final class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const UA = 'PASE-middleware/1.0 (admin@example.com)';

    public function __construct(private PDO $pdo) {}

    /**
     * @param array<string,?string> $addr klucze: postcode, city, country (jak w payloadzie zamówienia)
     * @return array{lat:float,lon:float}|null
     */
    public function geocodeAddress(array $addr): ?array
    {
        $parts = array_filter([
            trim((string) ($addr['postcode'] ?? '')),
            trim((string) ($addr['city'] ?? '')),
            $this->countryName((string) ($addr['country'] ?? '')),
        ]);
        $query = implode(', ', $parts);
        if (trim($query) === '') {
            return null;
        }
        return $this->geocode($query);
    }

    /** @return array{lat:float,lon:float}|null */
    public function geocode(string $query): ?array
    {
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
        if ($query === '') {
            return null;
        }
        $hash = sha1(mb_strtolower($query));

        $cached = $this->fromCache($hash);
        if ($cached !== null) {
            return $cached['found'] ? ['lat' => $cached['lat'], 'lon' => $cached['lon']] : null;
        }

        $result = $this->queryNominatim($query);
        $this->store($hash, $query, $result);
        return $result;
    }

    /** @return array{found:bool,lat:float,lon:float}|null */
    private function fromCache(string $hash): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT found, lat, lon FROM geocode_cache WHERE query_hash = ?');
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        return [
            'found' => (int) $row['found'] === 1,
            'lat'   => (float) $row['lat'],
            'lon'   => (float) $row['lon'],
        ];
    }

    /** @param array{lat:float,lon:float}|null $result */
    private function store(string $hash, string $query, ?array $result): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO geocode_cache (query_hash, query, lat, lon, found)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE lat = VALUES(lat), lon = VALUES(lon), found = VALUES(found)'
            );
            $stmt->execute([
                $hash,
                mb_substr($query, 0, 255),
                $result['lat'] ?? null,
                $result['lon'] ?? null,
                $result !== null ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            Logger::warn('Geocode cache store failed', ['err' => $e->getMessage()]);
        }
    }

    /** @return array{lat:float,lon:float}|null */
    private function queryNominatim(string $query): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'q'      => $query,
            'format' => 'json',
            'limit'  => 1,
        ]);
        try {
            $res = Http::request('GET', $url, [
                'User-Agent'      => self::UA,
                'Accept'          => 'application/json',
                'Accept-Language' => 'pl,en',
            ], null, 12);
        } catch (\Throwable $e) {
            Logger::warn('Geocode HTTP failed', ['err' => $e->getMessage()]);
            return null;
        }
        if (!$res->isSuccess()) {
            return null;
        }
        $data = $res->json();
        $first = $data[0] ?? null;
        if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
            return null;
        }
        return ['lat' => (float) $first['lat'], 'lon' => (float) $first['lon']];
    }

    /** Kod kraju (ISO-2) -> nazwa, żeby zapytanie do Nominatim było jednoznaczne. */
    private function countryName(string $code): string
    {
        $map = [
            'PL' => 'Polska', 'DE' => 'Deutschland', 'CZ' => 'Česko', 'SK' => 'Slovensko',
            'GB' => 'United Kingdom', 'US' => 'United States', 'FR' => 'France',
            'IT' => 'Italia', 'ES' => 'España', 'NL' => 'Nederland',
        ];
        $code = strtoupper(trim($code));
        return $map[$code] ?? $code;
    }
}
