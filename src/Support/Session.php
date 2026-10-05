<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Sesja panelu - jawnie skonfigurowana, zamiast zdawania się na ustawienia hostingu.
 *
 * Domyślne PHP daje sesję na ~24 minuty (session.gc_maxlifetime = 1440) i ciasteczko
 * ginące przy zamknięciu przeglądarki, a sprzątanie plików sesji odpala się losowo,
 * przy ~1% żądań. Efekt: wylogowania w nieprzewidywalnych momentach.
 *
 * Trzy rzeczy, które to naprawiają:
 *
 *  1. WŁASNY KATALOG NA PLIKI SESJI (storage/sessions). Na shared hostingu sesje
 *     lądują we wspólnym /tmp, gdzie sprzątaczka INNEGO konta na tym samym serwerze
 *     potrafi skasować nasze pliki wcześniej, niż wynika z naszych ustawień. To
 *     najczęstsza przyczyna "wylogowuje mimo długiej sesji".
 *  2. DŁUGIE ŻYCIE ciasteczka i pliku sesji (domyślnie 30 dni).
 *  3. PRZESUWANY TERMIN: każda aktywność odsuwa wygaśnięcie. Bez tego sesja
 *     wygasałaby po 30 dniach od zalogowania, nawet gdyby ktoś pracował codziennie.
 *
 * Osobna nazwa ciasteczka (PASE_ADMIN) oddziela sesję panelu od sesji publicznej
 * strony zamówienia - inaczej obie dzieliłyby jeden identyfikator przy różnych
 * ustawieniach, co kończy się wzajemnym unieważnianiem.
 */
final class Session
{
    public const COOKIE_NAME = 'PASE_ADMIN';

    /** Ile dni trzymać zalogowanie panelu. Nadpisywalne z .env: SESSION_ADMIN_DAYS. */
    private const DEFAULT_DAYS = 30;

    /** Jak często odświeżać ciasteczko (sekundy) - nie ma sensu przy każdym żądaniu. */
    private const REFRESH_EVERY = 3600;

    /** Czas życia sesji panelu w sekundach (SESSION_ADMIN_DAYS, domyślnie 30 dni). */
    public static function lifetime(): int
    {
        return max(1, (int) (Env::get('SESSION_ADMIN_DAYS', (string) self::DEFAULT_DAYS) ?? self::DEFAULT_DAYS)) * 86400;
    }

    public static function startAdmin(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = self::lifetime();

        self::useOwnSavePath($lifetime);

        // Plik sesji ma przeżyć tak długo jak ciasteczko - inaczej serwer skasuje
        // dane, mimo że przeglądarka wciąż przysyła poprawny identyfikator.
        @ini_set('session.gc_maxlifetime', (string) $lifetime);
        @ini_set('session.use_strict_mode', '1');   // nie akceptuj ID wymyślonego przez klienta

        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,      // JavaScript nie dotknie ciasteczka sesji
            'samesite' => 'Lax',     // chroni przed CSRF z obcych stron, a linki z maili działają
        ]);

        session_start();

        self::slideExpiry($lifetime);
    }

    /**
     * Przesuwa termin wygaśnięcia przy aktywności. Ciasteczko odświeżamy najwyżej
     * raz na godzinę - częściej nic by nie dało, a każde odświeżenie to nagłówek
     * Set-Cookie na odpowiedzi.
     */
    private static function slideExpiry(int $lifetime): void
    {
        $now  = time();
        $last = (int) ($_SESSION['pase_seen_at'] ?? 0);

        if ($last !== 0 && ($now - $last) < self::REFRESH_EVERY) {
            return;
        }

        $isNewSession = $last === 0;
        $_SESSION['pase_seen_at'] = $now;

        // Świeża sesja dostała już ciasteczko z pełnym terminem od session_start() -
        // drugie Set-Cookie w tej samej odpowiedzi niczego by nie wniosło.
        if ($isNewSession) {
            return;
        }

        if (session_id() !== '' && !headers_sent()) {
            setcookie(session_name(), session_id(), [
                'expires'  => $now + $lifetime,
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /**
     * Własny katalog na pliki sesji. Gdy nie da się go utworzyć (uprawnienia),
     * zostajemy przy domyślnym - lepsza krótsza sesja niż brak logowania w ogóle.
     */
    private static function useOwnSavePath(int $lifetime): void
    {
        $dir = PASE_ROOT . '/storage/sessions';

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            Logger::warn('Sesja: nie mogę utworzyć ' . $dir . ' - zostaje katalog domyślny PHP');
            return;
        }
        if (!is_writable($dir)) {
            Logger::warn('Sesja: brak prawa zapisu do ' . $dir . ' - zostaje katalog domyślny PHP');
            return;
        }

        @ini_set('session.save_path', $dir);

        // We własnym katalogu automatyczne sprzątanie PHP bywa wyłączone
        // (gc_probability=0 na wielu hostingach), więc kasujemy stare pliki sami.
        self::collectGarbage($dir, $lifetime);
    }

    /** Kasuje pliki sesji starsze niż czas życia. Odpala się rzadko, żeby nie obciążać. */
    private static function collectGarbage(string $dir, int $lifetime): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }

        $cutoff = time() - $lifetime;
        foreach (glob($dir . '/sess_*') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }
        // Za proxy/load balancerem PHP nie widzi HTTPS bezpośrednio.
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
