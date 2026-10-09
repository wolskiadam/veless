<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Plugin\PluginRegistry;

/**
 * Ikony na liście zamówień: logo kanału (źródła) i logo przewoźnika (kolumna „Dostawa”).
 *
 * Logo kanału daje WTYCZKA integracji (PluginManifest::$logo, znacznik <svg>) - rdzeń nie zna
 * logotypów sklepów. Wtyczka bez logo dostaje neutralny kwadrat w swoim kolorze z pierwszą literą nazwy.
 *
 * Przewoźnika rozpoznajemy po słowach kluczowych: najpierw z nadanej przesyłki (shipments.courier_code),
 * a gdy jej nie ma albo nazwa nic nie mówi (np. „Wysyłam z Allegro”) - z metody dostawy wybranej przez
 * klienta (shipping_lines[0].method_title albo surowe Allegro delivery.method.name). Te same słowa i ta
 * sama kolejność działają w SQL do sortowania kolumny (sortJoinSql/sortOrderSql), więc kolejność wierszy
 * zgadza się z ikonami.
 */
final class OrderChannelIcons
{
    /**
     * Przewoźnik => [nazwa, słowa kluczowe]. Kolejność ma znaczenie (pierwsze trafienie wygrywa):
     * „Allegro One Box” to Allegro One, ale „Allegro Paczkomaty InPost” to InPost.
     */
    public const CARRIERS = [
        'allegro_one' => ['Allegro One', ['allegro one', 'one box', 'one punkt', 'one kurier']],
        'inpost'      => ['InPost', ['inpost', 'paczkomat']],
        'dpd'         => ['DPD', ['dpd']],
        'dhl'         => ['DHL', ['dhl']],
        'gls'         => ['GLS', ['gls']],
        'ups'         => ['UPS', ['ups']],
        'fedex'       => ['FedEx', ['fedex']],
        'poczta'      => ['Poczta Polska', ['pocztex', 'poczta', 'envelo']],
        'orlen'       => ['ORLEN Paczka', ['orlen', 'ruch']],
        'pickup'      => ['Odbiór osobisty', ['odbiór osobisty', 'odbior osobisty', 'odbiór w sklepie', 'local pickup', 'local_pickup']],
    ];

    /** Loga przewoźników (viewBox 0 0 48 24). */
    private const CARRIER_SVG = [
        'allegro_one' => '<rect width="48" height="24" rx="5" fill="#00a650"/><text x="24" y="16.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" font-weight="700" fill="#fff">One</text>',
        'inpost'      => '<rect width="48" height="24" rx="5" fill="#ffcd00"/><text x="24" y="15.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="10.5" font-weight="700" fill="#1d1d1b">InPost</text>',
        'dpd'         => '<rect width="48" height="24" rx="5" fill="#dc0032"/><text x="24" y="16.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" font-weight="700" fill="#fff">dpd</text>',
        'dhl'         => '<rect width="48" height="24" rx="5" fill="#ffcc00"/><text x="24" y="16.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" font-weight="900" font-style="italic" fill="#d40511">DHL</text>',
        'gls'         => '<rect width="48" height="24" rx="5" fill="#061ab1"/><text x="24" y="16.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" font-weight="700" fill="#ffd100">GLS</text>',
        'ups'         => '<rect width="48" height="24" rx="5" fill="#351c15"/><text x="24" y="16.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" font-weight="700" fill="#ffb500">UPS</text>',
        'fedex'       => '<rect x=".5" y=".5" width="47" height="23" rx="5" fill="#fff" stroke="#d9dce3"/><text x="24" y="16" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" font-weight="700"><tspan fill="#4d148c">Fed</tspan><tspan fill="#ff6600">Ex</tspan></text>',
        'poczta'      => '<rect width="48" height="24" rx="5" fill="#e30613"/><text x="24" y="15.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="9.5" font-weight="700" fill="#fff">Pocztex</text>',
        'orlen'       => '<rect width="48" height="24" rx="5" fill="#e4032e"/><text x="24" y="15.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="10" font-weight="700" fill="#fff">ORLEN</text>',
        'pickup'      => '<rect x=".5" y=".5" width="47" height="23" rx="5" fill="#eef0f6" stroke="#d9dce3"/><g transform="translate(14 2)" fill="none" stroke="#4b5563" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9l2-5h12l2 5"/><path d="M4 9h16v1.5a2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 1-5 0V9"/><path d="M5.5 13v6h13v-6"/><path d="M10 19v-3.5h4V19"/></g>',
    ];

    /** Neutralna ciężarówka dla nierozpoznanego przewoźnika (viewBox 0 0 24 24). */
    private const TRUCK_SVG = '<g fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h11v10H3z"/><path d="M14 9h4l3 3.5V16h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></g>';

    /** Zamówienia dodane ręcznie w CRM nie mają wtyczki - ikona z rdzenia (viewBox 0 0 24 24). */
    private const MANUAL_SVG = '<rect width="24" height="24" rx="6" fill="#2f7a45"/><g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5.5l4 4L10 18H6v-4z"/><path d="M12.5 7.5l4 4"/></g>';

    /** Kanał nieznany / bez wtyczki: neutralna torba zakupowa (viewBox 0 0 24 24). */
    private const NEUTRAL_SVG = '<rect width="24" height="24" rx="6" fill="#eef0f6"/><g fill="none" stroke="#6b7280" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9h12l-1 10H7z"/><path d="M9.5 9V7.5a2.5 2.5 0 0 1 5 0V9"/></g>';

    /** Klucz przewoźnika rozpoznany z tekstu (nazwa kuriera albo metody dostawy); null = nie rozpoznano. */
    public static function carrierKey(?string $text): ?string
    {
        $hay = mb_strtolower(trim((string) $text));
        if ($hay === '') {
            return null;
        }
        foreach (self::CARRIERS as $key => [, $words]) {
            foreach ($words as $word) {
                if (str_contains($hay, $word)) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Nazwa metody dostawy wybranej przez klienta (Woo/TikTok/ręczne: shipping_lines, surowe Allegro: delivery). */
    public static function deliveryMethod(array $payload): string
    {
        $title = $payload['shipping_lines'][0]['method_title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $title = $payload['delivery']['method']['name'] ?? null;
        }
        return is_string($title) ? trim($title) : '';
    }

    /**
     * Dostawa zamówienia do kolumny „Dostawa”.
     * @param array<int,array<string,mixed>> $shipments przesyłki zamówienia w kolejności nadania (id rosnąco)
     * @return array{key: ?string, name: string, method: string} name = nazwa przewoźnika albo surowy tekst
     */
    public static function delivery(array $payload, array $shipments = []): array
    {
        $method = self::deliveryMethod($payload);
        $courier = '';
        foreach ($shipments as $sh) {
            if (in_array((string) ($sh['status'] ?? ''), ['cancelled', 'canceled', 'failed'], true)) { continue; }
            $code = trim((string) ($sh['courier_code'] ?? ''));
            if ($code !== '') { $courier = $code; }   // ostatnia aktywna przesyłka wygrywa
        }
        $key = self::carrierKey($courier) ?? self::carrierKey($method);
        $name = $key !== null ? self::CARRIERS[$key][0] : ($method !== '' ? $method : $courier);
        $detail = trim(implode(' · ', array_unique(array_filter([$method, $courier], static fn(string $s): bool => $s !== ''))));
        return ['key' => $key, 'name' => $name, 'method' => $detail];
    }

    /** HTML komórki „Dostawa”: logo przewoźnika, neutralna ciężarówka z nazwą albo „—”. */
    public static function deliveryCell(array $delivery): string
    {
        $key = $delivery['key'] ?? null;
        $title = (string) ($delivery['method'] ?? '');
        if ($key !== null && isset(self::CARRIER_SVG[$key])) {
            $label = self::CARRIERS[$key][0];
            $tooltip = $title !== '' && mb_strtolower($title) !== mb_strtolower($label) ? $label . ' — ' . $title : $label;
            return '<span class="carrier-logo" title="' . htmlspecialchars($tooltip) . '" role="img" aria-label="' . htmlspecialchars($tooltip) . '">'
                . '<svg viewBox="0 0 48 24" aria-hidden="true">' . self::CARRIER_SVG[$key] . '</svg></span>';
        }
        $name = (string) ($delivery['name'] ?? '');
        if ($name === '') {
            return '<span style="color:var(--ink-3)">—</span>';
        }
        return '<span class="carrier-other" title="' . htmlspecialchars($title !== '' ? $title : $name) . '">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true">' . self::TRUCK_SVG . '</svg>'
            . '<span class="carrier-other-name">' . htmlspecialchars($name) . '</span></span>';
    }

    /**
     * Logo kanału (znacznik <svg>) - z manifestu wtyczki integracji; bez logo w manifeście neutralny
     * kwadrat w kolorze wtyczki z pierwszą literą nazwy; bez wtyczki neutralna ikona.
     */
    public static function sourceLogo(?string $type): string
    {
        if ($type === 'manual') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true">' . self::MANUAL_SVG . '</svg>';
        }
        $manifest = null;
        if ($type !== null && $type !== '') {
            try {
                $manifest = PluginRegistry::get($type)?->manifest();
            } catch (\Throwable) {
                $manifest = null;
            }
        }
        if ($manifest === null) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true">' . self::NEUTRAL_SVG . '</svg>';
        }
        $logo = trim($manifest->logo);
        if ($logo !== '' && str_starts_with($logo, '<svg')) {
            return $logo;
        }
        $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $manifest->color) ? $manifest->color : '#6b7280';
        $letter = mb_strtoupper(mb_substr(trim($manifest->name) !== '' ? $manifest->name : $manifest->type, 0, 1));
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="' . $color . '"/>'
            . '<text x="12" y="16.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" font-weight="700" fill="#fff">'
            . htmlspecialchars($letter) . '</text></svg>';
    }

    /** Nazwa kanału z manifestu wtyczki (null = brak wtyczki). */
    public static function sourceName(string $type): ?string
    {
        try {
            return PluginRegistry::get($type)?->manifest()->name;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * LEFT JOIN do sortowania po kolumnie „Dostawa”: tekst kuriera i metody dostawy liczony RAZ na zamówienie
     * (DISTINCT wymusza materializację podzapytania zamiast wklejania go do ORDER BY), bo payload to duży JSON, a CASE niżej sięga po tekst wiele razy.
     * Wymaga aliasu wo = woo_orders. $driver: PDO::ATTR_DRIVER_NAME ('mysql' albo 'sqlite' w testach).
     */
    public static function sortJoinSql(string $driver): string
    {
        $json = static fn(string $path): string => $driver === 'sqlite'
            ? "json_extract(d.payload, '{$path}')"
            : "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(d.payload, '{$path}')), 'null')";
        $method = "CASE WHEN d.payload IS NOT NULL AND JSON_VALID(d.payload) THEN COALESCE(NULLIF(TRIM("
            . $json('$.shipping_lines[0].method_title') . "), ''), " . $json('$.delivery.method.name') . ") END";
        $courier = "(SELECT s.courier_code FROM shipments s WHERE s.woo_order_id = d.woo_order_id AND s.courier_code IS NOT NULL"
            . " AND TRIM(s.courier_code) <> '' AND COALESCE(s.status, '') NOT IN ('cancelled', 'canceled', 'failed') ORDER BY s.id DESC LIMIT 1)";
        return "LEFT JOIN (SELECT DISTINCT d.woo_order_id, LOWER(TRIM({$courier})) AS courier_txt, LOWER(TRIM({$method})) AS method_txt"
            . " FROM woo_orders d) dsort ON dsort.woo_order_id = wo.woo_order_id";
    }

    /** Wyrażenie sortujące (po sortJoinSql): klucz przewoźnika jak w carrierKey(), nierozpoznane - po nazwie metody. */
    public static function sortKeySql(): string
    {
        $case = static function (string $col): string {
            $sql = 'CASE';
            foreach (self::CARRIERS as $key => [, $words]) {
                $likes = array_map(static fn(string $w): string => "{$col} LIKE '%" . str_replace("'", "''", $w) . "%'", $words);
                $sql .= ' WHEN ' . implode(' OR ', $likes) . " THEN '{$key}'";
            }
            return $sql . ' END';
        };
        return 'COALESCE(' . $case('dsort.courier_txt') . ', ' . $case('dsort.method_txt')
            . ", dsort.method_txt, dsort.courier_txt, '')";
    }
}
