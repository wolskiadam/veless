<?php
declare(strict_types=1);

// Wypis z informacji o aktualizacjach: unsubscribe.php?e=<email>&s=<podpis>.
require __DIR__ . '/lib.php';

$email = trim((string) ($_GET['e'] ?? ''));
$sig = (string) ($_GET['s'] ?? '');
try {
    if ($email === '' || !hash_equals(unsubscribe_sig($email), $sig)) {
        http_response_code(400);
        page('Wypis', '<h1>Nieprawidłowy link</h1><p>Ten link do wypisu jest niepełny. Skopiuj go w całości z e-maila.</p>');
    }
    append_csv('unsubscribed.csv', [gmdate('c'), strtolower($email)]);
} catch (Throwable $e) {
    error_log('[veless-site] ' . $e->getMessage());
    http_response_code(500);
    page('Wypis', '<h1>Coś poszło nie tak</h1><p>Spróbuj ponownie za chwilę.</p>');
}
page('Wypis', '<h1>Wypisano</h1><p>Adres ' . h($email) . ' nie będzie już dostawał informacji o aktualizacjach.</p><p><a href="index.html">← Wróć na stronę</a></p>');
