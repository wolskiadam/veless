<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use ZipArchive;

/**
 * Kopie zapasowe z panelu, robione OSOBNO dla części systemu:
 *   - settings  - ustawienia systemu (integracje, statusy, automatyzacje, szablony, e-mail...),
 *   - orders    - dane zamówień (zamówienia, historia, wiadomości z załącznikami, dokumenty, przesyłki),
 *   - products  - katalog produktów (produkty, mapowania SKU, szablony ofert).
 *
 * Archiwum to ZIP szyfrowany AES-256 hasłem podanym przy tworzeniu. W środku:
 *   manifest.json         - co jest w kopii, liczby wierszy i SHA-256 każdego pliku,
 *   data/<tabela>.jsonl   - wiersze tabeli (jeden obiekt JSON w linii),
 *   files/uploads/...     - pliki z storage/ wskazane przez wiersze (załączniki, grafiki wydruków),
 *   zamowienia.csv        - (tylko zamówienia) czytelne zestawienie do otwarcia w Excelu.
 * Komentarz archiwum (NIEszyfrowany) zawiera tylko rodzaj kopii, datę i opis - żeby lista
 * kopii w panelu działała bez podawania hasła. Żadnych danych w komentarzu.
 *
 * Przywracanie ZASTĘPUJE tabele danej części (jedna transakcja), ale:
 *   - najpierw sprawdza hasło i sumy kontrolne CAŁEGO archiwum,
 *   - automatycznie robi kopię bieżącego stanu tej części (tym samym hasłem),
 *   - nie rusza znaczników pracy systemu (kursor Allegro, czasy synchronizacji...),
 *   - zostawia aktualne tokeny API (Allegro rotuje refresh token - stary już nie działa),
 *   - przyjmuje tylko tabele i kolumny znane tej części - nic spoza listy nie trafi do bazy.
 */
final class SectionBackup
{
    public const FORMAT = 'crm-section-v1';

    /** Części kopii: etykieta, opis i tabele (kolejność = kolejność przywracania). */
    public const PARTS = [
        'settings' => [
            'label' => 'Ustawienia systemu',
            'desc'  => 'Integracje i klucze API, konta e-mail, statusy, automatyzacje, szablony e-mail i wydruku (z grafikami), własne pola produktów, szablony opisów Allegro, układy widoków.',
            'tables' => [
                'settings', 'integrations', 'integration_accounts', 'mail_accounts', 'order_statuses',
                'automation_rules', 'email_templates', 'print_templates', 'print_assets', 'product_fields',
                'allegro_desc_templates', 'user_layouts',
            ],
        ],
        'orders' => [
            'label' => 'Dane zamówień',
            'desc'  => 'Zamówienia, historia zmian, wiadomości z klientami (z załącznikami), wystawione dokumenty, przesyłki. Dodatkowo plik CSV do otwarcia w Excelu.',
            'tables' => [
                'woo_orders', 'order_logs', 'order_messages', 'order_documents', 'document_issue_operations',
                'shipments', 'audit_events',
            ],
        ],
        'products' => [
            'label' => 'Katalog produktów',
            'desc'  => 'Produkty (stany, ceny, własne pola), mapowania SKU, szablony ofert, zapamiętane szczegóły ofert Allegro.',
            'tables' => ['products', 'product_mappings', 'offer_templates', 'allegro_offer_details'],
        ],
    ];

    /** Kolumny wskazujące pliki w storage/ (ścieżka względna, np. uploads/abc.png). */
    private const FILE_COLUMNS = [
        'print_templates' => ['image_path'],
        'print_assets'    => ['file_path'],
        'order_messages'  => ['attachment_path'],
    ];

    /** Ustawienia będące stanem pracy systemu, nie konfiguracją - nie eksportujemy i nie nadpisujemy. */
    private const RUNTIME_SETTINGS = '/^(SYNC_[A-Z]+_AT|TOKENS_REFRESH_AT|WORKER_LAST_RUN_AT|OPS_ALERT_LAST_[A-Z_]+|PRINT_AGENT_LAST_SEEN|ALLEGRO_LAST_ORDER_EVENT_ID|TRACKING_CARRIERS_CACHE|NOTIF_ORDERS_SEEN_\d+|NOTIF_ORDERS_DISMISSED_\d+|PAUSE_[A-Z]+|QUEUE_CLEANUP_AT|ORDERS_AUTOARCHIVE_AT|PRINT_LAST_JOB_AT|PRINT_AGENT_VERSION)$/D';

    /** Tokeny API: przy przywracaniu zostają bieżące, jeśli konto/integracja już istnieje. */
    private const TOKEN_TABLES = ['integrations' => 'platform_name', 'integration_accounts' => 'id'];
    private const TOKEN_COLUMNS = ['access_token', 'refresh_token', 'expires_at'];

    /** Kolumna z ID zamówienia (woo_orders.woo_order_id) w tabelach danych zamówień - do kopii z okresu. */
    private const ORDER_KEY = [
        'woo_orders' => 'woo_order_id', 'order_logs' => 'woo_order_id', 'order_messages' => 'woo_order_id',
        'order_documents' => 'woo_order_id', 'document_issue_operations' => 'order_id',
        'shipments' => 'woo_order_id', 'audit_events' => 'order_id',
    ];

    /** Rozszerzenia plików dopuszczone przy odtwarzaniu (jak w Attachments). */
    private const FILE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv'];

    public const MIN_PASSWORD = 8;
    private const NAME_RE = '/^crm-(settings|orders|products|upload)-\d{8}-\d{6}-[a-f0-9]{8}\.zip$/D';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $storageDir,
        private readonly string $appVersion = '',
    ) {}

    // ------------------------------------------------------------------ katalog i lista

    /** Katalog kopii (storage/backups/sections), tworzony z blokadą dostępu z WWW. */
    public function dir(): string
    {
        $dir = rtrim($this->storageDir, '/') . '/backups/sections';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu kopii (storage/backups/sections).');
        }
        foreach ([dirname($dir), $dir] as $d) {
            if (!is_file($d . '/.htaccess')) { @file_put_contents($d . '/.htaccess', "Require all denied\n"); }
        }
        return $dir;
    }

    /** Pełna ścieżka zapisanej kopii - tylko nazwy wygenerowane przez system (brak path traversal). */
    public function path(string $file): string
    {
        if (!preg_match(self::NAME_RE, $file)) { throw new \RuntimeException('Nieprawidłowa nazwa kopii.'); }
        $path = $this->dir() . '/' . $file;
        if (!is_file($path) || is_link($path)) { throw new \RuntimeException('Nie ma takiej kopii.'); }
        return $path;
    }

    /** @return list<array{file:string,part:string,label:string,note:string,period:?array,created_at:string,size:int}> najnowsze pierwsze */
    public function list(): array
    {
        $out = [];
        foreach (scandir($this->dir()) ?: [] as $file) {
            if (!preg_match(self::NAME_RE, $file)) { continue; }
            $meta = self::readComment($this->dir() . '/' . $file);
            $part = (string) ($meta['part'] ?? '');
            $out[] = [
                'file'       => $file,
                'part'       => $part,
                'label'      => self::PARTS[$part]['label'] ?? 'Nieznana',
                'note'       => (string) ($meta['note'] ?? ''),
                'period'     => self::cleanPeriod($meta['period'] ?? null),
                'created_at' => (string) ($meta['created_at'] ?? ''),
                'size'       => (int) filesize($this->dir() . '/' . $file),
            ];
        }
        usort($out, static fn($a, $b) => strcmp($b['created_at'] . $b['file'], $a['created_at'] . $a['file']));
        return $out;
    }

    public function delete(string $file): void
    {
        if (!unlink($this->path($file))) { throw new \RuntimeException('Nie udało się usunąć kopii.'); }
    }

    /** Bieżąca liczba wierszy w tabelach części (null = tabela nie istnieje). @return array<string,?int> */
    public function currentCounts(string $part): array
    {
        $out = [];
        foreach (self::tables($part) as $table) {
            $out[$table] = $this->tableExists($table)
                ? (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::q($table))->fetchColumn() : null;
        }
        return $out;
    }

    // ------------------------------------------------------------------ eksport

    /** Tworzy zaszyfrowaną kopię części i zapisuje ją w katalogu kopii. Zwraca nazwę pliku. */
    /**
     * @param array{from:string,to:string}|null $period tylko dla zamówień: daty Y-m-d (włącznie) wg daty złożenia zamówienia
     */
    public function export(string $part, string $password, string $note = '', ?array $period = null): string
    {
        $tables = self::tables($part);
        if ($period !== null) {
            if ($part !== 'orders') { throw new \RuntimeException('Okres można wybrać tylko dla danych zamówień.'); }
            $period = self::cleanPeriod($period);
            if ($period === null) { throw new \RuntimeException('Podaj poprawny okres (data „od” nie może być późniejsza niż „do”).'); }
        }
        self::checkPassword($password);
        self::requireZip();
        $note = mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', $note) ?? ''), 0, 120);
        $createdAt = gmdate('c');

        $dir   = $this->dir();
        $stage = $dir . '/stage-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0700)) { throw new \RuntimeException('Nie można utworzyć katalogu roboczego kopii.'); }
        $name    = 'crm-' . $part . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $final   = $dir . '/' . $name;
        $partial = $final . '.partial';
        $entries = []; // nazwa w ZIP => ścieżka źródłowa
        $manifest = ['format' => self::FORMAT, 'part' => $part, 'created_at' => $createdAt,
            'app_version' => $this->appVersion, 'note' => $note, 'tables' => [], 'missing_tables' => [],
            'files' => [], 'missing_files' => 0, 'period' => $period];
        // Filtr okresu: zamówienia złożone w zakresie + wszystkie powiązane z nimi wiersze.
        $range = $period ? [$period['from'] . ' 00:00:00', $period['to'] . ' 23:59:59'] : [];
        $inPeriod = 'SELECT ' . self::q('woo_order_id') . ' FROM ' . self::q('woo_orders')
            . ' WHERE ' . self::q('date_created') . ' >= ? AND ' . self::q('date_created') . ' <= ?';
        try {
            $filesToCopy = [];
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            // Jeden spójny odczyt wszystkich tabel części.
            if ($driver === 'mysql') { $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); }
            $this->pdo->beginTransaction();
            try {
                foreach ($tables as $table) {
                    if (!$this->tableExists($table)) { $manifest['missing_tables'][] = $table; continue; }
                    $path = $stage . '/' . $table . '.jsonl';
                    $out = fopen($path, 'xb');
                    if (!$out) { throw new \RuntimeException('Nie można zapisać danych kopii.'); }
                    $rows = 0;
                    // Duże tabele (payload zamówień) czytamy strumieniowo, bez ładowania całości do pamięci.
                    if ($driver === 'mysql') { $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); }
                    try {
                        if ($period) {
                            $key = self::ORDER_KEY[$table];
                            $sql = $table === 'woo_orders'
                                ? 'SELECT * FROM ' . self::q($table) . ' WHERE ' . self::q('date_created') . ' >= ? AND ' . self::q('date_created') . ' <= ?'
                                : 'SELECT * FROM ' . self::q($table) . ' WHERE ' . self::q($key) . ' IN (' . $inPeriod . ')';
                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute($range);
                        } else {
                            $stmt = $this->pdo->query('SELECT * FROM ' . self::q($table));
                        }
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            if ($table === 'settings' && preg_match(self::RUNTIME_SETTINGS, (string) $row['setting_key'])) { continue; }
                            foreach (self::FILE_COLUMNS[$table] ?? [] as $col) {
                                $rel = (string) ($row[$col] ?? '');
                                if ($rel !== '' && self::safeStoragePath($rel)) { $filesToCopy[$rel] = true; }
                            }
                            // Sekrety w archiwum (chronionym hasłem kopii) jawne - kopia wróci też na instalację z innym kluczem.
                            $row = \Pase\Support\SecretStore::openRow($table, $row);
                            $line = json_encode(array_map(self::encode(...), $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                            if (fwrite($out, $line) !== strlen($line)) { throw new \RuntimeException('Brak miejsca na dysku przy zapisie kopii.'); }
                            ++$rows;
                        }
                        $stmt->closeCursor();
                    } finally {
                        fclose($out);
                        if ($driver === 'mysql') { $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
                    }
                    $entries['data/' . $table . '.jsonl'] = $path;
                    $manifest['tables'][$table] = ['rows' => $rows];
                }
                if ($part === 'orders' && $this->tableExists('woo_orders')) {
                    $entries['zamowienia.csv'] = $this->ordersCsv($stage . '/zamowienia.csv', $range);
                }
            } finally {
                if ($this->pdo->inTransaction()) { $this->pdo->commit(); }
            }
            foreach (array_keys($filesToCopy) as $rel) {
                $src = rtrim($this->storageDir, '/') . '/' . $rel;
                if (is_file($src) && !is_link($src)) { $entries['files/' . $rel] = $src; }
                else { ++$manifest['missing_files']; }
            }

            $zip = new ZipArchive();
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new \RuntimeException('Nie można utworzyć archiwum kopii.'); }
            $closed = false;
            try {
                foreach ($entries as $entry => $src) {
                    $hash = hash_file('sha256', $src);
                    $manifest['files'][$entry] = ['size' => filesize($src), 'sha256' => $hash];
                    if (!$zip->addFile($src, $entry) || !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password)) {
                        throw new \RuntimeException('Nie można zaszyfrować pliku w kopii.');
                    }
                }
                if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                    || !$zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password)) {
                    throw new \RuntimeException('Nie można zaszyfrować spisu kopii.');
                }
                $zip->setArchiveComment(json_encode(['format' => self::FORMAT, 'part' => $part, 'created_at' => $createdAt, 'note' => $note, 'period' => $period], JSON_UNESCAPED_UNICODE));
                if (!$zip->close()) { throw new \RuntimeException('Nie można zapisać archiwum kopii (brak miejsca?).'); }
                $closed = true;
            } finally {
                if (!$closed) { @$zip->close(); }
            }
            chmod($partial, 0600);
            $this->verify($partial, $password); // odczyt kontrolny całego archiwum
            if (!rename($partial, $final)) { throw new \RuntimeException('Nie można zapisać gotowej kopii.'); }
            return $name;
        } finally {
            if (is_file($partial)) { @unlink($partial); }
            foreach (glob($stage . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($stage);
        }
    }

    /** Czytelne zestawienie zamówień (CSV ze średnikami, UTF-8 z BOM - Excel otwiera poprawnie). */
    /** @param array{0:string,1:string}|array{} $range */
    private function ordersCsv(string $path, array $range = []): string
    {
        $wanted = ['order_number' => 'Numer', 'woo_order_id' => 'ID', 'date_created' => 'Data', 'status' => 'Status',
            'total' => 'Kwota', 'currency' => 'Waluta', 'customer_name' => 'Klient', 'customer_email' => 'E-mail'];
        $cols = array_values(array_intersect(array_keys($wanted), $this->columns('woo_orders')));
        $out = fopen($path, 'xb');
        if (!$out) { throw new \RuntimeException('Nie można zapisać CSV zamówień.'); }
        try {
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(static fn($c) => $wanted[$c], $cols), ';', '"', '');
            $order = in_array('date_created', $cols, true) ? ' ORDER BY ' . self::q('date_created') : '';
            $where = $range ? ' WHERE ' . self::q('date_created') . ' >= ? AND ' . self::q('date_created') . ' <= ?' : '';
            $stmt = $this->pdo->prepare('SELECT ' . implode(',', array_map(self::q(...), $cols)) . ' FROM ' . self::q('woo_orders') . $where . $order);
            $stmt->execute($range);
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                // Wartości zaczynające się od =,+,-,@ Excel traktuje jak formuły - neutralizujemy.
                $row = array_map(static fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && !is_numeric($v) ? "'" . $v : $v, $row);
                fputcsv($out, $row, ';', '"', '');
            }
        } finally { fclose($out); }
        return $path;
    }

    // ------------------------------------------------------------------ sprawdzanie

    /**
     * Sprawdza hasło i sumy kontrolne wszystkich plików. Zwraca manifest.
     * @return array<string,mixed>
     */
    public function verify(string $archive, string $password): array
    {
        self::requireZip();
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('To nie jest poprawne archiwum ZIP.'); }
        try {
            $zip->setPassword($password);
            $stat = $zip->statName('manifest.json');
            if (!$stat || $stat['size'] > 8 * 1024 * 1024) { throw new \RuntimeException('To nie jest kopia utworzona w tym systemie (brak spisu).'); }
            $raw = @$zip->getFromName('manifest.json');
            if ($raw === false) { throw new \RuntimeException('Złe hasło do kopii albo uszkodzony plik.'); }
            $manifest = json_decode($raw, true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT || !isset(self::PARTS[$manifest['part'] ?? ''])
                || !is_array($manifest['files'] ?? null) || !is_array($manifest['tables'] ?? null)) {
                throw new \RuntimeException('Nieobsługiwany format kopii.');
            }
            if ($zip->numFiles !== count($manifest['files']) + 1) { throw new \RuntimeException('Zawartość kopii nie zgadza się ze spisem.'); }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                if (($entry['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) { throw new \RuntimeException('Kopia zawiera niezaszyfrowany plik.'); }
                $name = $entry['name'];
                if ($name === 'manifest.json') { continue; }
                $expected = $manifest['files'][$name] ?? null;
                if (!is_array($expected) || $entry['size'] !== (int) $expected['size']) { throw new \RuntimeException('Zawartość kopii nie zgadza się ze spisem.'); }
                $stream = $zip->getStream($name);
                if (!$stream) { throw new \RuntimeException('Nie można odczytać pliku z kopii.'); }
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                fclose($stream);
                if (!hash_equals((string) $expected['sha256'], hash_final($ctx))) { throw new \RuntimeException('Kopia jest uszkodzona (niezgodna suma kontrolna: ' . $name . ').'); }
            }
            return $manifest;
        } finally {
            $zip->close();
        }
    }

    // ------------------------------------------------------------------ przywracanie

    /**
     * Przywraca część z zapisanej kopii. Przed zmianami robi automatyczną kopię bieżącego stanu.
     * @return array{part:string,tables:array<string,int>,files:int,safety:string,period:?array,orders:?int}
     */
    public function restore(string $file, string $password): array
    {
        $archive  = $this->path($file);
        $manifest = $this->verify($archive, $password);
        $part     = (string) $manifest['part'];
        $allowed  = self::tables($part);

        // Kopia z okresu zastępuje TYLKO zamówienia, które zawiera - reszta zostaje nietknięta.
        $period = $part === 'orders' ? self::cleanPeriod($manifest['period'] ?? null) : null;
        $orderIds = $period ? $this->orderIdsFromArchive($archive, $password) : null;

        // Kopia bezpieczeństwa bieżącego stanu (całej części) - przywrócenie da się cofnąć.
        $safety = $this->export($part, $password, 'Automatycznie przed przywróceniem ' . substr((string) $manifest['created_at'], 0, 10));

        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Nie można otworzyć kopii.'); }
        $zip->setPassword($password);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $counts = [];
        try {
            $this->pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');
            $this->pdo->beginTransaction();
            try {
                foreach ($allowed as $table) {
                    if (!isset($manifest['tables'][$table]) || !$this->tableExists($table)) { continue; }
                    $counts[$table] = $this->restoreTable($zip, $table, (int) $manifest['tables'][$table]['rows'], $orderIds);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                throw $e;
            }
        } finally {
            $this->pdo->exec($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
        }

        // Pliki (załączniki, grafiki) - dopisujemy brakujące; istniejących o tej samej nazwie nie psujemy.
        $restoredFiles = 0;
        try {
            foreach (array_keys($manifest['files']) as $entry) {
                if (!str_starts_with($entry, 'files/')) { continue; }
                $rel = substr($entry, 6);
                if (!self::safeStoragePath($rel)) { continue; }
                $dest = rtrim($this->storageDir, '/') . '/' . $rel;
                if (is_file($dest) && hash_file('sha256', $dest) === $manifest['files'][$entry]['sha256']) { continue; }
                if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) { throw new \RuntimeException('Nie można utworzyć katalogu plików.'); }
                $tmp = $dest . '.restore-' . bin2hex(random_bytes(4));
                $in = $zip->getStream($entry);
                $out = fopen($tmp, 'xb');
                if (!$in || !$out) { throw new \RuntimeException('Nie można odtworzyć pliku ' . $rel); }
                stream_copy_to_stream($in, $out);
                fclose($in); fclose($out);
                if (hash_file('sha256', $tmp) !== $manifest['files'][$entry]['sha256'] || !rename($tmp, $dest)) {
                    @unlink($tmp);
                    throw new \RuntimeException('Nie można odtworzyć pliku ' . $rel);
                }
                ++$restoredFiles;
            }
        } finally {
            $zip->close();
        }
        return ['part' => $part, 'tables' => $counts, 'files' => $restoredFiles, 'safety' => $safety,
            'period' => $period, 'orders' => $orderIds === null ? null : count($orderIds)];
    }

    /** @param list<string>|null $orderIds null = zastąp całą tabelę; lista = zastąp tylko wiersze tych zamówień */
    private function restoreTable(ZipArchive $zip, string $table, int $expectedRows, ?array $orderIds = null): int
    {
        $columns = array_flip($this->columns($table));

        // Stan, którego nie nadpisujemy: znaczniki pracy systemu i aktualne tokeny API.
        $keepTokens = [];
        if (isset(self::TOKEN_TABLES[$table])) {
            $key = self::TOKEN_TABLES[$table];
            foreach ($this->pdo->query('SELECT * FROM ' . self::q($table))->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (trim((string) ($row['access_token'] ?? '')) !== '' || trim((string) ($row['refresh_token'] ?? '')) !== '') {
                    $keepTokens[(string) $row[$key]] = array_intersect_key($row, array_flip(self::TOKEN_COLUMNS));
                }
            }
        }
        if ($table === 'settings') {
            $del = $this->pdo->prepare('DELETE FROM settings WHERE setting_key = ?');
            foreach ($this->pdo->query('SELECT setting_key FROM settings')->fetchAll(PDO::FETCH_COLUMN) as $k) {
                if (!preg_match(self::RUNTIME_SETTINGS, (string) $k)) { $del->execute([$k]); }
            }
        } elseif ($orderIds !== null) {
            $key = self::ORDER_KEY[$table] ?? null;
            if ($key === null || !isset($columns[$key])) { throw new \RuntimeException('Tabela ' . $table . ' nie pozwala na przywrócenie wybranego okresu.'); }
            foreach (array_chunk($orderIds, 500) as $chunk) {
                $this->pdo->prepare('DELETE FROM ' . self::q($table) . ' WHERE ' . self::q($key) . ' IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
            }
        } else {
            $this->pdo->exec('DELETE FROM ' . self::q($table));
        }

        $stream = $zip->getStream('data/' . $table . '.jsonl');
        if (!$stream) { throw new \RuntimeException('Brak danych tabeli ' . $table . ' w kopii.'); }
        $rows = 0; $stmts = [];
        $idSet = $orderIds === null ? [] : array_flip($orderIds);
        try {
            while (($line = fgets($stream)) !== false) {
                if (trim($line) === '') { continue; }
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row)) { throw new \RuntimeException('Uszkodzone dane tabeli ' . $table); }
                ++$rows;
                if ($table === 'settings' && preg_match(self::RUNTIME_SETTINGS, (string) ($row['setting_key'] ?? ''))) { continue; }
                // Tylko kolumny istniejące w bieżącej bazie (kopia ze starszej/nowszej wersji też wejdzie).
                $row = \Pase\Support\SecretStore::sealRow($table, array_map(self::decode(...), array_intersect_key($row, $columns)));
                if (isset(self::TOKEN_TABLES[$table])) {
                    $id = (string) ($row[self::TOKEN_TABLES[$table]] ?? '');
                    if (isset($keepTokens[$id])) { $row = array_merge($row, $keepTokens[$id]); }
                }
                if ($row === []) { continue; }
                // Kopia z okresu: wiersz musi należeć do zamówienia z kopii (nic spoza zakresu nie wejdzie).
                if ($orderIds !== null && !isset($idSet[(string) ($row[self::ORDER_KEY[$table]] ?? '')])) {
                    throw new \RuntimeException('Dane tabeli ' . $table . ' wykraczają poza zamówienia z kopii.');
                }
                $sig = implode(',', array_keys($row));
                if (!isset($stmts[$sig])) {
                    $stmts[$sig] = $this->pdo->prepare('INSERT INTO ' . self::q($table) . ' (' . implode(',', array_map(self::q(...), array_keys($row)))
                        . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
                }
                $stmts[$sig]->execute(array_values($row));
            }
        } finally { fclose($stream); }
        if ($rows !== $expectedRows) { throw new \RuntimeException('Niepełne dane tabeli ' . $table . ' w kopii.'); }
        return $rows;
    }

    // ------------------------------------------------------------------ wgrywanie kopii z komputera

    /** Przyjmuje plik wgrany przez przeglądarkę. Zwraca nazwę zapisanej kopii. */
    public function importUpload(string $tmpPath): string
    {
        $meta = self::readComment($tmpPath);
        if (($meta['format'] ?? '') !== self::FORMAT || !isset(self::PARTS[$meta['part'] ?? ''])) {
            throw new \RuntimeException('To nie jest kopia z panelu tego systemu (pełne kopie z serwera odtwarza się według BACKUPS.md).');
        }
        $name = 'crm-upload-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $dest = $this->dir() . '/' . $name;
        $ok = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
        if (!$ok) { throw new \RuntimeException('Nie udało się zapisać wgranej kopii.'); }
        chmod($dest, 0600);
        return $name;
    }

    // ------------------------------------------------------------------ pomocnicze

    /** ID zamówień zawartych w kopii (z danych woo_orders). @return list<string> */
    private function orderIdsFromArchive(string $archive, string $password): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Nie można otworzyć kopii.'); }
        $zip->setPassword($password);
        $ids = [];
        try {
            $stream = $zip->getStream('data/woo_orders.jsonl');
            if ($stream) {
                while (($line = fgets($stream)) !== false) {
                    if (trim($line) === '') { continue; }
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (!isset($row['woo_order_id']) || is_array($row['woo_order_id'])) { throw new \RuntimeException('Uszkodzone dane zamówień w kopii.'); }
                    $ids[(string) $row['woo_order_id']] = true;
                }
                fclose($stream);
            }
        } finally { $zip->close(); }
        return array_map('strval', array_keys($ids));
    }

    /** Okres w formacie {from,to} (Y-m-d, from <= to) albo null. @return array{from:string,to:string}|null */
    public static function cleanPeriod(mixed $period): ?array
    {
        if (!is_array($period)) { return null; }
        $out = [];
        foreach (['from', 'to'] as $k) {
            $v = (string) ($period[$k] ?? '');
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v) { return null; }
            $out[$k] = $v;
        }
        return $out['from'] <= $out['to'] ? $out : null;
    }

    /** @return list<string> */
    public static function tables(string $part): array
    {
        if (!isset(self::PARTS[$part])) { throw new \RuntimeException('Nieznany rodzaj kopii.'); }
        return self::PARTS[$part]['tables'];
    }

    public static function checkPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \RuntimeException('Hasło do kopii musi mieć co najmniej ' . self::MIN_PASSWORD . ' znaków.');
        }
    }

    private static function requireZip(): void
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new \RuntimeException('Serwer nie obsługuje szyfrowanych ZIP (rozszerzenie PHP zip z AES-256).');
        }
    }

    /** @return array<string,mixed> */
    private static function readComment(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) { return []; }
        $comment = (string) $zip->getArchiveComment();
        $zip->close();
        $data = json_decode($comment, true);
        return is_array($data) ? $data : [];
    }

    /** Tylko pliki w storage/uploads o dozwolonych rozszerzeniach - nic wykonywalnego, bez wychodzenia z katalogu. */
    private static function safeStoragePath(string $rel): bool
    {
        return (bool) preg_match('#^uploads/[A-Za-z0-9][A-Za-z0-9._-]{0,190}$#D', $rel)
            && !str_contains($rel, '..')
            && in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::FILE_EXT, true);
    }

    private static function encode(mixed $v): mixed
    {
        return is_string($v) && !preg_match('//u', $v) ? ['b64' => base64_encode($v)] : $v;
    }

    private static function decode(mixed $v): mixed
    {
        if (is_array($v)) {
            $raw = isset($v['b64']) && is_string($v['b64']) ? base64_decode($v['b64'], true) : false;
            if ($raw === false) { throw new \RuntimeException('Uszkodzona wartość w kopii.'); }
            return $raw;
        }
        return is_bool($v) ? (int) $v : $v;
    }

    private static function q(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new \RuntimeException('Nieprawidłowa nazwa w bazie.'); }
        return '`' . $name . '`';
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::q($table) . ' LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_column($this->pdo->query('PRAGMA table_info(' . self::q($table) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        return $this->pdo->query('SHOW COLUMNS FROM ' . self::q($table))->fetchAll(PDO::FETCH_COLUMN);
    }
}
