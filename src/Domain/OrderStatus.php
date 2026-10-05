<?php
declare(strict_types=1);

namespace Pase\Domain;

/**
 * Statusy zamówienia w systemie PASE - JEDNO źródło prawdy.
 *
 * PASE ma własną, stałą listę statusów. Status z WooCommerce (i w przyszłości
 * Allegro) jest mapowany na status PASE przy imporcie. Zmiana statusu w PASE
 * będzie docelowo odsyłana z powrotem do platform.
 */
final class OrderStatus
{
    // Kanoniczne statusy PASE (wartości zapisywane w bazie).
    public const NEW         = 'new';
    public const PROCESSING  = 'processing';
    public const SHIPPED     = 'shipped';
    public const CANCELLED   = 'cancelled';
    public const REFUNDED    = 'refunded';

    /** Etykiety do wyświetlania w panelu. */
    public const LABELS = [
        self::NEW        => 'Nowe',
        self::PROCESSING => 'W realizacji',
        self::SHIPPED    => 'Wysłane',
        self::CANCELLED  => 'Anulowane',
        self::REFUNDED   => 'Zwrot',
    ];

    /** Mapowanie statusów WooCommerce -> status PASE. */
    private const WOO_MAP = [
        'pending'    => self::NEW,
        'on-hold'    => self::NEW,
        'processing' => self::PROCESSING,
        'completed'  => self::SHIPPED,
        'cancelled'  => self::CANCELLED,
        'failed'     => self::CANCELLED,
        'refunded'   => self::REFUNDED,
    ];

    /** Mapowanie zwrotne PASE -> status WooCommerce (do synchronizacji). */
    private const TO_WOO_MAP = [
        self::NEW        => 'pending',
        self::PROCESSING => 'processing',
        self::SHIPPED    => 'completed',
        self::CANCELLED  => 'cancelled',
        self::REFUNDED   => 'refunded',
    ];

    /** Mapowanie statusów realizacji Allegro (fulfillment.status) -> status PASE. */
    private const ALLEGRO_MAP = [
        'new'                 => self::NEW,
        'processing'          => self::PROCESSING,
        'ready_for_shipment'  => self::PROCESSING,
        'sent'                => self::SHIPPED,
        'picked_up'           => self::SHIPPED,
        'cancelled'           => self::CANCELLED,
    ];

    /**
     * Mapowanie zwrotne PASE -> status realizacji Allegro (do synchronizacji).
     * NEW i REFUNDED nie mają sensownego odpowiednika do wysłania (Allegro samo
     * ustawia NEW, a zwroty obsługuje przez osobny proces reklamacji/zwrotów) -
     * null oznacza "nie wysyłaj nic".
     */
    private const TO_ALLEGRO_MAP = [
        self::PROCESSING => 'PROCESSING',
        self::SHIPPED    => 'SENT',
        self::CANCELLED  => 'CANCELLED',
    ];

    // ============================================================
    //  Mapowania ustawiane przez użytkownika (Konfiguracja → Statusy zamówień)
    //
    //  Stałe powyżej zostają jako wartości domyślne - działają, dopóki nikt nic
    //  nie zmapował ręcznie, i ratują sytuację, gdyby baza była niedostępna.
    //  Ładowanie jest leniwe: loader rejestruje się przy starcie (config/database.php),
    //  ale zapytanie leci dopiero przy pierwszym realnym mapowaniu statusu, więc
    //  samo wyświetlenie listy zamówień nic nie kosztuje.
    // ============================================================

    /** @var (callable():array<string,array<string,string>>)|null */
    private static $mapLoader = null;
    private static ?array $customMaps = null;

    public static function useMapLoader(?callable $loader): void
    {
        self::$mapLoader  = $loader;
        self::$customMaps = null;   // zmiana loadera unieważnia to, co już wczytane
    }

    /** Czyści zapamiętane mapowania - wołane po zapisie zmian w panelu. */
    public static function forgetCustomMaps(): void
    {
        self::$customMaps = null;
    }

    private static function customMaps(): array
    {
        if (self::$customMaps !== null) {
            return self::$customMaps;
        }
        self::$customMaps = ['in_woo' => [], 'in_allegro' => [], 'out_woo' => [], 'out_allegro' => []];

        if (self::$mapLoader !== null) {
            try {
                self::$customMaps = array_merge(self::$customMaps, (self::$mapLoader)());
            } catch (\Throwable $e) {
                // Brak bazy albo kolumn nie może wywrócić mapowania - zostają domyślne.
            }
        }
        return self::$customMaps;
    }

    /** Mapuje status Woo na status PASE. Nieznany -> NEW (bezpieczny default). */
    public static function fromWoo(?string $wooStatus): string
    {
        $key = strtolower(trim((string) $wooStatus));
        return self::customMaps()['in_woo'][$key] ?? self::WOO_MAP[$key] ?? self::NEW;
    }

    /** Mapuje status realizacji Allegro na status PASE. Nieznany -> NEW (bezpieczny default). */
    public static function fromAllegro(?string $allegroFulfillmentStatus): string
    {
        $key = strtolower(trim((string) $allegroFulfillmentStatus));
        return self::customMaps()['in_allegro'][$key] ?? self::ALLEGRO_MAP[$key] ?? self::NEW;
    }

    /**
     * Mapuje status PASE na status WooCommerce (do odsyłania zmian). Null jeśli brak.
     *
     * Rozróżnienie, które tu robimy: BRAK wpisu znaczy "użyj domyślnego", a wpis
     * pusty znaczy "świadomie nic nie wysyłaj". Dlatego sprawdzamy istnienie klucza,
     * a nie samą wartość - inaczej nie dałoby się wyłączyć synchronizacji statusu.
     */
    public static function toWoo(string $paseStatus): ?string
    {
        $custom = self::customMaps()['out_woo'];
        if (array_key_exists($paseStatus, $custom)) {
            return $custom[$paseStatus] !== '' ? $custom[$paseStatus] : null;
        }
        return self::TO_WOO_MAP[$paseStatus] ?? null;
    }

    /** Mapuje status PASE na status realizacji Allegro (do odsyłania zmian). Null jeśli brak. */
    public static function toAllegro(string $paseStatus): ?string
    {
        $custom = self::customMaps()['out_allegro'];
        if (array_key_exists($paseStatus, $custom)) {
            return $custom[$paseStatus] !== '' ? $custom[$paseStatus] : null;
        }
        return self::TO_ALLEGRO_MAP[$paseStatus] ?? null;
    }

    /** Status formularza zakupu Allegro (checkout-form.status) - po polsku. */
    public const ALLEGRO_CHECKOUT_LABELS = [
        'BOUGHT'               => 'Kupione — kupujący nie uzupełnił jeszcze danych',
        'FILLED_IN'            => 'Dane uzupełnione — czeka na płatność',
        'READY_FOR_PROCESSING' => 'Opłacone — gotowe do realizacji',
        'CANCELLED'            => 'Anulowane przez kupującego',
    ];

    /**
     * Status zamówienia w kanale sprzedaży po polsku (do wyświetlania).
     * Allegro: status zakupu + status realizacji, np. „Opłacone — gotowe do realizacji · realizacja: nowe”.
     * WooCommerce: np. „w realizacji”. Nieznany status - pokazujemy surową wartość.
     */
    public static function channelLabel(array $payload, ?string $fallback = null): string
    {
        $plain = static fn(?string $label): string => $label === null ? '' : trim((string) (explode('—', $label, 2)[1] ?? $label));

        if (isset($payload['lineItems'])) { // zamówienie z Allegro
            $checkout = (string) ($payload['status'] ?? '');
            $out = self::ALLEGRO_CHECKOUT_LABELS[$checkout] ?? $checkout;
            $ful = (string) ($payload['fulfillment']['status'] ?? '');
            if ($ful !== '') {
                $out .= ($out !== '' ? ' · ' : '') . 'realizacja: ' . ($plain(self::ALLEGRO_STATUSES[$ful] ?? null) ?: $ful);
            }
            return $out !== '' ? $out : (string) $fallback;
        }

        if (isset($payload['tiktok'])) { // zamówienie z TikTok Shop
            $st = (string) ($payload['status'] ?? $fallback ?? '');
            return \Pase\Services\TiktokShop::STATUS_LABELS[$st] ?? $st;
        }

        $st = (string) ($payload['status'] ?? $fallback ?? '');
        return $plain(self::WOO_STATUSES[$st] ?? null) ?: $st;
    }

    /** Statusy WooCommerce - do selektorów w panelu. */
    public const WOO_STATUSES = [
        'pending'    => 'pending — oczekuje na płatność',
        'processing' => 'processing — w realizacji',
        'on-hold'    => 'on-hold — wstrzymane',
        'completed'  => 'completed — zrealizowane',
        'cancelled'  => 'cancelled — anulowane',
        'refunded'   => 'refunded — zwrócone',
        'failed'     => 'failed — nieudane',
    ];

    /**
     * Statusy realizacji Allegro. NEW ustawia samo Allegro przy zakupie - jest na
     * liście dla kierunku "z Allegro do PASE", ale wysyłanie go nie miałoby sensu.
     */
    public const ALLEGRO_STATUSES = [
        'NEW'                => 'NEW — nowe',
        'PROCESSING'         => 'PROCESSING — w realizacji',
        'READY_FOR_SHIPMENT' => 'READY_FOR_SHIPMENT — gotowe do wysyłki',
        'SENT'               => 'SENT — wysłane',
        'PICKED_UP'          => 'PICKED_UP — odebrane',
        'CANCELLED'          => 'CANCELLED — anulowane',
        'SUSPENDED'          => 'SUSPENDED — wstrzymane',
    ];

    /** Statusy, których nie wysyłamy z PASE do Allegro (Allegro ustawia je samo). */
    public const ALLEGRO_STATUSES_NOT_SENDABLE = ['NEW'];

    /** Etykieta dla statusu PASE (lub sama wartość, jeśli nieznana). */
    public static function label(?string $status): string
    {
        return self::LABELS[(string) $status] ?? (string) $status;
    }

    /** Lista [wartość => etykieta] do dropdownów. */
    public static function options(): array
    {
        return self::LABELS;
    }

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }
}
