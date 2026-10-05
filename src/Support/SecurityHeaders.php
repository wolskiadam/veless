<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Nagłówki bezpieczeństwa panelu (public/admin/auth.php).
 *
 * Wymuszane od razu: brak osadzania panelu w obcych ramkach (clickjacking), brak zgadywania typu
 * pliku, brak wtyczek <object>/<embed> i podmiany <base>. Pełne CSP działa na razie w trybie
 * Report-Only: panel ma dużo skryptów i stylów inline, więc najpierw zbieramy zgłoszenia
 * (public/csp_report.php -> storage/app.log), a dopiero potem włączamy blokowanie (nonce).
 */
final class SecurityHeaders
{
    /** Część wymuszana - nic w panelu jej nie potrzebuje. */
    public const ENFORCED = "frame-ancestors 'self'; object-src 'none'; base-uri 'self'";

    /** Docelowe CSP w trybie raportowania (bez blokowania). */
    public const REPORT_ONLY = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob: https:; "
        . "media-src 'self' blob:; "
        . "connect-src 'self'; "
        . "frame-src 'self' data: blob:; "
        . "worker-src 'self' blob:; "
        . "object-src 'none'; base-uri 'self'; form-action 'self' https:; frame-ancestors 'self'";

    public static function forPanel(string $reportUri = '../csp_report.php'): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Content-Security-Policy: ' . self::ENFORCED);
        header('Content-Security-Policy-Report-Only: ' . self::REPORT_ONLY . '; report-uri ' . $reportUri);
    }
}
