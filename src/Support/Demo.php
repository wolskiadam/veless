<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Publiczna wersja demonstracyjna (DEMO_MODE=1 w .env), np. demo.veless.pl.
 *
 * Każdy może wejść na wspólne konto demo (login i hasło: demo), więc instalacja:
 *   - nie łączy się z niczym na zewnątrz (Http, wyszukiwarka firm, SMTP),
 *   - nie przyjmuje plików (wtyczki, załączniki, kopie zapasowe, CSV),
 *   - nie pozwala zmienić kont, haseł, 2FA, integracji ani kanałów wysyłki,
 *   - wraca do stanu wyjściowego co noc (cli/demo_reset.php z crona).
 */
final class Demo
{
    public const USERNAME = 'demo';
    public const PASSWORD = 'demo';

    /** Strony całkiem wyłączone (pobierają lub odtwarzają dane systemu albo pliki agenta). */
    private const BLOCKED_PAGES = [
        'backup.php', 'url_migration.php', 'agent_download.php', 'agent_app_download.php',
        'forgot_password.php', 'reset_password.php',
    ];

    /** Strony tylko do oglądania: zapis (POST) jest zablokowany. */
    private const READONLY_PAGES = [
        'users.php', 'security.php', 'plugins.php', 'ext.php',
        'integrations.php', 'integration_edit.php', 'allegro_connect.php', 'tiktokshop_connect.php',
        'allegro_settings.php', 'email_settings.php', 'sync_settings.php', 'client_settings.php',
        'payu.php', 'payu_refund.php', 'gs1.php', 'login_quick.php', 'company.php',
    ];

    public static function on(): bool
    {
        return Env::bool('DEMO_MODE', false);
    }

    /** Czy żądanie do tej strony panelu trzeba w demo odrzucić. */
    public static function blocks(string $script, string $method, bool $hasFiles): bool
    {
        if (!self::on()) {
            return false;
        }
        $script = basename($script);
        if ($hasFiles || in_array($script, self::BLOCKED_PAGES, true)) {
            return true;
        }
        return strtoupper($method) === 'POST' && in_array($script, self::READONLY_PAGES, true);
    }

    /** Wywoływane na początku każdej strony WWW: odrzuca zablokowane żądania. */
    public static function guardRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $hasFiles = false;
        foreach ($_FILES as $f) {
            $names = (array) ($f['name'] ?? []);
            if (array_filter($names, static fn($n) => $n !== '' && $n !== null) !== []) {
                $hasFiles = true;
                break;
            }
        }
        if (!self::blocks((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $hasFiles)) {
            return;
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $back = htmlspecialchars((string) ($_SERVER['HTTP_REFERER'] ?? '/admin/'), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Wersja demo</title><body style="font-family:system-ui,sans-serif;background:#f6f2ec;color:#2b2420;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
            . '<div style="max-width:440px;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08)">'
            . '<h1 style="font-size:20px;margin:0 0 10px">To jest wersja demonstracyjna</h1>'
            . '<p style="line-height:1.5;margin:0 0 18px">Ta funkcja jest wyłączona w demo: nie wysyłamy niczego na zewnątrz, nie przyjmujemy plików '
            . 'i nie zmieniamy kont ani integracji. Zainstaluj Veless u siebie, żeby z niej skorzystać.</p>'
            . '<a href="' . $back . '" style="color:#9c6b2e;font-weight:600">← Wróć</a></div>';
        exit;
    }

    /** Pasek na górze panelu. */
    public static function bannerHtml(): string
    {
        if (!self::on()) {
            return '';
        }
        return '<div style="background:#2b2420;color:#f6f2ec;text-align:center;font-size:13px;padding:7px 12px;line-height:1.4">'
            . 'Wersja demonstracyjna Veless z fikcyjnymi danymi. Wysyłka, integracje i pliki są wyłączone, a dane wracają do stanu wyjściowego co noc.'
            . '</div>';
    }
}
