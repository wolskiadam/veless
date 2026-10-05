<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;

/**
 * Boxy (sekcje) widoczne na publicznej stronie zamówienia klienta (order.php).
 * Administrator włącza/wyłącza je w Konfiguracja → Strona klienta.
 *
 * Jedno źródło prawdy dla panelu (checkboxy) i strony klienta (warunek widoczności).
 * Flaga w settings: CLIENT_BOX_<KLUCZ> = '1'|'0'. Brak wpisu = domyślnie włączony.
 */
final class ClientPageBoxes
{
    /** @return array<string,string> klucz boxa => etykieta (kolejność = kolejność na stronie) */
    public static function catalog(): array
    {
        return [
            'status'          => 'Status zamówienia (pasek kroków)',
            'delivery_status' => 'Status dostawy',
            'products'        => 'Lista produktów',
            'delivery'        => 'Adres dostawy',
            'tracking'        => 'Śledzenie przesyłki (nr listu)',
            'messages'        => 'Historia i wiadomości z obsługą',
        ];
    }

    public static function settingKey(string $box): string
    {
        return 'CLIENT_BOX_' . strtoupper($box);
    }

    /** Klucz w settings przechowujący kolejność boxów (lista kluczy rozdzielona przecinkami). */
    public static function orderSettingKey(): string
    {
        return 'CLIENT_BOX_ORDER';
    }

    /**
     * Uporządkowana lista kluczy boxów wg ustawień administratora.
     * Zapisana kolejność ma priorytet; klucze spoza zapisu (nowe boxy) dochodzą
     * na koniec w kolejności z katalogu. Nieznane klucze (usunięte boxy) są pomijane.
     *
     * @param array<string,?string> $allSettings wynik SettingsRepository::all()
     * @return string[]
     */
    public static function order(array $allSettings): array
    {
        $catalog = array_keys(self::catalog());
        $saved   = (string) ($allSettings[self::orderSettingKey()] ?? '');
        $ordered = array_values(array_filter(
            array_map('trim', explode(',', $saved)),
            static fn($k) => in_array($k, $catalog, true)
        ));
        // Dołóż boxy z katalogu, których nie ma w zapisanej kolejności (np. nowo dodane).
        foreach ($catalog as $box) {
            if (!in_array($box, $ordered, true)) {
                $ordered[] = $box;
            }
        }
        return $ordered;
    }

    /**
     * Mapa: klucz boxa => bool (czy włączony). Czyta z settings; brak wpisu = włączony.
     * @param array<string,?string> $allSettings wynik SettingsRepository::all()
     * @return array<string,bool>
     */
    public static function fromSettings(array $allSettings): array
    {
        $out = [];
        foreach (array_keys(self::catalog()) as $box) {
            $val = $allSettings[self::settingKey($box)] ?? '1';
            $out[$box] = $val !== '0';
        }
        return $out;
    }

    /** Wygodny odczyt jednego boxa bez ładowania całości (gdy mamy tylko PDO/repo). */
    public static function isEnabled(SettingsRepository $settings, string $box): bool
    {
        return ($settings->get(self::settingKey($box), '1') ?? '1') !== '0';
    }
}
