<?php
declare(strict_types=1);

// Zapis adresu e-mail i wydanie podpisanego linku do pobrania (POST z formularza),
// a potem samo pobranie paczki (GET ?t=<link>).
require __DIR__ . '/lib.php';

$cfg = site_config();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = (string) ($_GET['t'] ?? '');
        if (!verify_download_token($token)) {
            back_with_error('link');
        }
        $file = (string) $cfg['download_file'];
        if ($file !== '' && is_file($file)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename((string) $cfg['download_name']) . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
        if ((string) $cfg['download_url'] !== '') {
            header('Location: ' . $cfg['download_url'], true, 302);
            exit;
        }
        throw new RuntimeException('Brak download_file i download_url w config.php.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    // Pole-pułapka: wypełniają je tylko boty. Udajemy sukces, nic nie zapisujemy.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        page('Dziękujemy', '<h1>Dziękujemy!</h1><p>Sprawdź skrzynkę e-mail.</p>');
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        back_with_error('email');
    }
    if (($_POST['consent'] ?? '') !== '1') {
        back_with_error('zgoda');
    }
    if (rate_limited()) {
        back_with_error('limit');
    }

    append_csv('subscribers.csv', [gmdate('c'), $email, 'zgoda-v1', ip_hash()]);

    $exp = time() + 3600 * max(1, (int) $cfg['link_hours']);
    $link = site_url('download.php?t=' . download_token($email, $exp));

    if ((string) $cfg['notify_email'] !== '') {
        @mail((string) $cfg['notify_email'], 'Veless: nowe pobranie', "Nowy zapis: {$email}\n", 'Content-Type: text/plain; charset=utf-8');
    }
    if (!empty($cfg['send_link_email'])) {
        $from = (string) $cfg['mail_from'];
        $body = "Dzień dobry,\n\ndziękujemy za zainteresowanie Veless. Link do pobrania (ważny {$cfg['link_hours']} h):\n{$link}\n\n"
            . "Wypisanie z informacji o aktualizacjach:\n" . site_url('unsubscribe.php?e=' . rawurlencode($email) . '&s=' . unsubscribe_sig($email)) . "\n";
        @mail($email, '=?UTF-8?B?' . base64_encode('Veless – link do pobrania') . '?=', $body,
            "Content-Type: text/plain; charset=utf-8" . ($from !== '' ? "\r\nFrom: {$from}" : ''));
    }

    page('Pobierz', '<h1>Dziękujemy!</h1>'
        . '<p>Twój link do pobrania Veless jest gotowy. Działa przez ' . (int) $cfg['link_hours'] . ' h.</p>'
        . '<p><a class="btn" href="' . h($link) . '">Pobierz Veless</a></p>'
        . '<p>Instrukcja instalacji jest w pliku <code>README.md</code> w paczce.</p>'
        . '<p><a href="index.html">← Wróć na stronę</a></p>');
} catch (Throwable $e) {
    error_log('[veless-site] ' . $e->getMessage());
    back_with_error('serwer');
}
