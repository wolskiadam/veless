<?php
declare(strict_types=1);

namespace Pase\Plugin;

/**
 * Uprawnienia wtyczki do pokazania na stronie Wtyczki: do których części CRM sięga.
 * Integracja - z deklarowanych zdolności (Capability), rozszerzenie - z haków, pod które
 * podpina się jego boot() (Hooks::probe). Każde uprawnienie to [etykieta, objaśnienie].
 */
final class PluginPermissions
{
    /** @return list<array{0:string,1:string}> */
    public static function forIntegration(PluginManifest $m): array
    {
        $byCap = [
            Capability::ORDER_SOURCE => ['Pobieranie zamówień', 'Dodaje zamówienia z tego kanału do listy zamówień i zmienia ich status.'],
            Capability::WAREHOUSE    => ['Produkty i stany', 'Czyta i zmienia produkty, stany magazynowe i ceny.'],
            Capability::COURIER      => ['Przesyłki i etykiety', 'Nadaje przesyłki z danymi odbiorcy z zamówienia i pobiera etykiety.'],
            Capability::INVOICING    => ['Faktury i dokumenty', 'Wystawia dokumenty sprzedaży na dane nabywcy z zamówienia.'],
            Capability::SMS          => ['SMS do klientów', 'Wysyła SMS-y na numery telefonów klientów.'],
        ];
        $out = [['Połączenie zewnętrzne', 'Wysyła i pobiera dane z serwisu ' . $m->name . ' przez konto dodane w Integracjach.']];
        foreach ($m->capabilities as $cap) {
            $out[] = $byCap[$cap] ?? [Capability::label($cap), ''];
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    public static function forExtension(string $slug, ?\Pase\Plugin\Contract\ExtensionPlugin $ext): array
    {
        if ($ext === null) {
            return [];
        }
        ExtensionRegistry::boot(); // działające rozszerzenia najpierw - probe() nie może ich uruchomić w swoim środku
        $names = Hooks::probe($slug, [$ext, 'boot']);

        // Kolejność jak w menu panelu; prefiks nazwy haka => uprawnienie.
        $areas = [
            'orders.'         => ['Lista zamówień', 'Dodaje lub zmienia kolumny, ikony i przyciski na liście zamówień.'],
            'order_view.'     => ['Karta zamówienia', 'Dodaje sekcje na karcie zamówienia.'],
            'packing.'        => ['Pakowanie', 'Zmienia ekran pakowania i reaguje na spakowanie zamówienia.'],
            'asset.packing'   => ['Pakowanie', ''],
            'automation.'     => ['Automatyzacje', 'Dodaje akcje lub zdarzenia do automatyzacji i dostaje każde zdarzenie zamówienia.'],
            'statistics.'     => ['Statystyki', 'Dodaje kafelki do statystyk.'],
            'allegro_offer'   => ['Oferty Allegro', 'Dodaje narzędzia do formularza i listy ofert Allegro.'],
            'cron.'           => ['Praca w tle', 'Uruchamia własne zadania w tle (cron co minutę), np. pobieranie danych z zewnętrznej usługi.'],
            'page'            => ['Własne strony', 'Dodaje własne strony do panelu (także pozycje w menu).'],
            'admin.menu'      => ['Menu panelu', 'Może dodawać, usuwać i przestawiać pozycje menu.'],
            'admin.'          => ['Kod na każdej stronie panelu', 'Wstawia własne style lub skrypty na wszystkie strony panelu.'],
            'asset.admin'     => ['Kod na każdej stronie panelu', ''],
        ];
        $out = [];
        foreach ($areas as $prefix => [$label, $hint]) {
            foreach ($names as $i => $name) {
                if (str_starts_with($name, $prefix)) {
                    $out[$label] ??= [$label, $hint];
                    if ($out[$label][1] === '') { $out[$label][1] = $hint; }
                    unset($names[$i]);
                }
            }
        }
        foreach ($names as $name) { // hak spoza katalogu - pokaż nazwę
            $out[$name] = [$name, 'Hak ' . $name . '.'];
        }
        $connects = trim($ext->manifest()->connects);
        if ($connects !== '') {
            $out = ['' => ['Połączenie zewnętrzne', 'Wysyła i pobiera dane z: ' . $connects . '.']] + $out;
        }
        return array_values($out);
    }
}
