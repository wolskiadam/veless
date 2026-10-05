<?php
declare(strict_types=1);

// Eksport listy adresów do CSV: export.php?key=<export_key z config.php>.
// Jeden wiersz na adres (najnowszy zapis), bez osób wypisanych, z gotowym linkiem do wypisu.
require __DIR__ . '/lib.php';

$key = (string) site_config()['export_key'];
if ($key === '' || str_starts_with($key, 'ZMIEN') || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Brak dostępu.');
}

$out = [];
$gone = array_flip(array_map(static fn($r) => strtolower((string) ($r[1] ?? '')), read_csv('unsubscribed.csv')));
foreach (read_csv('subscribers.csv') as $r) {
    $email = strtolower((string) ($r[1] ?? ''));
    if ($email === '' || isset($gone[$email])) { continue; }
    $out[$email] = [$r[0], $email, $r[2] ?? '', site_url('unsubscribe.php?e=' . rawurlencode($email) . '&s=' . unsubscribe_sig($email))];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="veless-adresy-' . gmdate('Y-m-d') . '.csv"');
$fh = fopen('php://output', 'wb');
fputcsv($fh, ['data_zapisu', 'email', 'zgoda', 'link_wypisu'], ',', '"', '');
foreach ($out as $row) { fputcsv($fh, $row, ',', '"', ''); }
