<?php
declare(strict_types=1);

namespace Pase\Support;

use Pase\Repository\SettingsRepository;

/**
 * Tryb pracy instalacji (APP_MODE w .env):
 *
 *   - `server` (domyślnie) - hosting z publicznym adresem. Zamówienia Woo i płatności PayU
 *     przychodzą webhookami, harmonogram tylko dociąga pominięte.
 *   - `local` - instalacja w Dockerze na komputerze użytkownika (docker-compose.yml).
 *     Z zewnątrz nic do CRM nie dociera, więc worker sam odpytuje kanały: zamówienia co
 *     minutę (Woo po dacie modyfikacji, żeby łapać też zmiany statusu), płatności PayU co
 *     minutę. Gdy komputer jest wyłączony, zamówienia czekają w kanałach i ściągają się
 *     po włączeniu.
 */
final class AppMode
{
    public static function isLocal(): bool
    {
        return strtolower(trim((string) Env::get('APP_MODE', 'server'))) === 'local';
    }

    /**
     * Ustawienia startowe trybu lokalnego, zapisywane raz (tylko gdy klucza jeszcze nie ma),
     * żeby użytkownik mógł je potem zmienić w panelu.
     */
    public static function applyLocalDefaults(SettingsRepository $settings): void
    {
        if (!self::isLocal()) {
            return;
        }
        $url = rtrim((string) Env::get('APP_URL', ''), '/');
        $defaults = [
            // Bez webhooków to jedyna droga nowych zamówień - co minutę.
            'SYNC_ORDERS_EVERY' => '1',
            // Adres panelu do linków w mailach i resetu hasła (zwykle http://localhost:8081).
            'APP_BASE_URL'      => $url !== '' ? $url : 'http://localhost:8081',
        ];
        $all = $settings->all();
        $missing = array_filter($defaults, static fn(string $v, string $k): bool => ($all[$k] ?? '') === '', ARRAY_FILTER_USE_BOTH);
        if ($missing !== []) {
            $settings->setMany($missing);
        }
    }
}
