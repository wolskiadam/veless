<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Support\AppMode;
use Pase\Support\Env;
use Pase\Support\Http;
use Pase\Support\Logger;
use ZipArchive;

/**
 * Przekazanie pracy między instalacją online (hosting) a CRM na komputerze (Docker, AppMode).
 *
 * Zawsze pracuje tylko jedna strona. Ta, która oddaje pracę, przechodzi w stan „wstrzymana”
 * (HANDOVER_AWAY): worker nic nie robi, webhooki są ignorowane, panel nie przyjmuje zmian.
 * Dzięki temu zamówienie nie zostanie obsłużone dwa razy (maile, stany, statusy w kanałach).
 *
 * Komputer nie ma publicznego adresu, więc wszystko zaczyna się na komputerze:
 *   - „Pobierz z online”  (pull): serwer wstrzymuje się i buduje pakiet, komputer go pobiera i wgrywa.
 *   - „Wyślij na online”  (push): komputer wstrzymuje się, buduje pakiet i wysyła, serwer go wgrywa.
 * Strony łączą się kodem połączenia wygenerowanym w panelu online (adres + sekret, public/handover.php).
 *
 * Ciężka praca (pakiet, wgrywanie bazy, przesył) dzieje się w workerze (cli/worker.php → tick()),
 * bez limitu czasu żądania WWW. Panel tylko zleca zadanie i pokazuje postęp.
 *
 * Pakiet: ZIP szyfrowany AES-256 sekretem połączenia - migawka bazy (DatabaseSnapshot), pliki
 * z storage/ (załączniki, dokumenty, grafiki) i klucz 2FA. Bez kodu i bez .env (każda strona ma własne).
 * Wgranie podmienia tabele atomowo (RENAME TABLE) i zostawia ustawienia, które należą do danej
 * instalacji (adres panelu, stan przekazania, wstrzymania, częstotliwość pobierania zamówień).
 */
final class Handover
{
    public const FORMAT = 'crm-handover-v1';
    public const CHUNK = 4 * 1024 * 1024;
    public const CODE_PREFIX = 'CRM1:';

    private const TOKEN_HASH = 'HANDOVER_TOKEN_HASH';
    private const PEER_CODE  = 'HANDOVER_PEER_CODE';
    private const AWAY       = 'HANDOVER_AWAY';
    private const TASK       = 'HANDOVER_TASK';

    /** Ustawienia należące do instalacji - przy wgrywaniu zostają te z odbiorcy. */
    private const KEEP_SETTINGS = '^(APP_BASE_URL|HANDOVER_[A-Z_]+|PAUSE_[A-Z]+|WORKER_LAST_RUN_AT|SYNC_ORDERS_EVERY|LOCAL_ORDERS_CURSOR_[0-9]+)$';

    /** Katalogi i pliki storage/, których nie przenosimy (stan pracy, kopie, logi, sesje). */
    private const SKIP_STORAGE = ['backups', 'sessions', 'cache', 'plugin_backups', 'installer', 'handover', 'logs', 'stats',
        'security', 'version.json', 'ADMIN_PASSWORD.txt', 'agent-builds'];

    private const STAGE = 'hov_';
    private const OLD   = 'hovold_';

    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly string $root,
    ) {}

    // ================================================================== stan

    /** @return array{to:string,since:int}|null kto teraz pracuje zamiast tej instalacji */
    public function away(): ?array
    {
        $v = json_decode((string) $this->settings->get(self::AWAY, ''), true);
        return is_array($v) && isset($v['to'], $v['since']) ? ['to' => (string) $v['to'], 'since' => (int) $v['since']] : null;
    }

    public function isAway(): bool
    {
        return $this->away() !== null;
    }

    public function setAway(string $to): void
    {
        $this->settings->setMany([self::AWAY => json_encode(['to' => $to, 'since' => time()])]);
    }

    public function clearAway(): void
    {
        $this->settings->setMany([self::AWAY => '']);
    }

    /** Webhooki wstrzymanej instalacji: 200 bez działania (druga strona sama dociągnie zmiany). */
    public static function exitWhenAway(PDO $pdo): void
    {
        try {
            $away = (new self($pdo, new SettingsRepository($pdo), PASE_ROOT))->away();
        } catch (\Throwable) {
            return;
        }
        if ($away !== null) {
            http_response_code(200);
            echo 'ok';
            exit;
        }
    }

    /** @return array<string,mixed>|null bieżące zadanie przekazania (status: pending|running|ready|done|failed) */
    public function task(): ?array
    {
        $v = json_decode((string) $this->settings->get(self::TASK, ''), true);
        return is_array($v) && isset($v['type']) ? $v : null;
    }

    /** @param array<string,mixed>|null $task */
    private function saveTask(?array $task): void
    {
        $this->settings->setMany([self::TASK => $task === null ? '' : json_encode($task + ['at' => time()], JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $patch */
    private function updateTask(array $patch): array
    {
        $task = array_merge($this->task() ?? [], $patch);
        $this->saveTask($task);
        return $task;
    }

    public function clearTask(): void
    {
        $this->saveTask(null);
    }

    /** Zadanie w toku (zlecone albo wykonywane) - drugiego nie zlecamy. */
    public function busy(): bool
    {
        $t = $this->task();
        return $t !== null && in_array($t['status'] ?? '', ['pending', 'running'], true);
    }

    // ================================================================== parowanie (strona online)

    /** Nowy kod połączenia; poprzedni przestaje działać. Pokazywany raz, w bazie tylko skrót. */
    public function newPairingCode(string $baseUrl): string
    {
        $token = bin2hex(random_bytes(32));
        $this->settings->setMany([self::TOKEN_HASH => hash('sha256', $token)]);
        return self::CODE_PREFIX . rtrim($baseUrl, '/') . '#' . $token;
    }

    public function paired(): bool
    {
        return (string) $this->settings->get(self::TOKEN_HASH, '') !== '';
    }

    public function verifyToken(string $token): bool
    {
        $hash = (string) $this->settings->get(self::TOKEN_HASH, '');
        return $hash !== '' && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 && hash_equals($hash, hash('sha256', $token));
    }

    /** @return array{url:string,token:string} */
    public static function parseCode(string $code): array
    {
        $code = trim($code);
        if (!str_starts_with($code, self::CODE_PREFIX) || !str_contains($code, '#')) {
            throw new \RuntimeException('To nie jest kod połączenia z panelu online (zaczyna się od „' . self::CODE_PREFIX . '”).');
        }
        [$url, $token] = explode('#', substr($code, strlen(self::CODE_PREFIX)), 2);
        if (!preg_match('#^https?://#i', $url) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \RuntimeException('Kod połączenia jest niepełny - skopiuj go jeszcze raz z panelu online.');
        }
        return ['url' => rtrim($url, '/'), 'token' => $token];
    }

    // ================================================================== strona komputera: zlecenia z panelu

    public function savePeerCode(string $code): void
    {
        self::parseCode($code);
        $this->settings->setMany([self::PEER_CODE => trim($code)]);
    }

    public function peerUrl(): string
    {
        try { return self::parseCode((string) $this->settings->get(self::PEER_CODE, ''))['url']; } catch (\Throwable) { return ''; }
    }

    /** Zleca pobranie danych z online (worker wykona przy najbliższym przebiegu). */
    public function requestPull(): void
    {
        $this->guardRequest();
        $this->saveTask(['type' => 'pull', 'status' => 'pending', 'step' => 'Czeka na worker']);
    }

    /** Zleca wysłanie danych na online. Ta instalacja od razu przestaje przyjmować zmiany. */
    public function requestPush(): void
    {
        $this->guardRequest();
        if ($this->isAway()) {
            throw new \RuntimeException('Ta instalacja jest wstrzymana - nie ma czego wysyłać. Najpierw pobierz dane z online.');
        }
        $this->setAway('online');
        $this->saveTask(['type' => 'push', 'status' => 'pending', 'step' => 'Czeka na worker']);
    }

    private function guardRequest(): void
    {
        if (!AppMode::isLocal()) {
            throw new \RuntimeException('Przekazanie zleca się z CRM na komputerze (tryb lokalny).');
        }
        if ($this->busy()) {
            throw new \RuntimeException('Przekazanie już trwa - poczekaj na jego koniec.');
        }
        self::parseCode((string) $this->settings->get(self::PEER_CODE, ''));
    }

    /** Ręczne wznowienie pracy na tej instalacji (np. gdy druga strona padła). */
    public function resumeHere(): void
    {
        $this->clearAway();
        $this->clearTask();
        $this->removeTree($this->dir(), false);
    }

    // ================================================================== strona online: żądania z public/handover.php

    /** Komputer chce przejąć pracę: wstrzymujemy się od razu, pakiet zbuduje worker. */
    public function serverStartExport(): void
    {
        $away = $this->away();
        $task = $this->task();
        if ($away !== null && !($away['to'] === 'local' && in_array($task['type'] ?? '', ['export'], true))) {
            throw new \RuntimeException('Online jest wstrzymany, bo pracę przejął komputer. Wyślij dane z komputera na online zamiast je pobierać.');
        }
        if ($away === null) {
            $this->setAway('local');
        }
        $this->saveTask(['type' => 'export', 'status' => 'pending', 'step' => 'Czeka na worker']);
    }

    public function serverStartUpload(): string
    {
        $away = $this->away();
        if ($away === null || $away['to'] !== 'local') {
            throw new \RuntimeException('Online pracuje - wysłanie danych z komputera nadpisałoby nowsze dane. Najpierw pobierz dane z online na komputer.');
        }
        $id = bin2hex(random_bytes(8));
        $this->removeTree($this->dir(), false);
        if (file_put_contents($this->dir() . '/in-' . $id . '.part', '') === false) {
            throw new \RuntimeException('Nie można zapisać pliku na serwerze (brak miejsca?).');
        }
        $this->saveTask(['type' => 'upload', 'status' => 'running', 'step' => 'Odbieranie danych', 'id' => $id]);
        return $id;
    }

    public function serverAppendUpload(string $id, int $offset, string $chunk): int
    {
        $path = $this->uploadPath($id);
        clearstatcache(true, $path);
        if (filesize($path) !== $offset) {
            throw new \RuntimeException('Zła kolejność części pliku.');
        }
        if (file_put_contents($path, $chunk, FILE_APPEND | LOCK_EX) !== strlen($chunk)) {
            throw new \RuntimeException('Nie można zapisać części pliku (brak miejsca?).');
        }
        clearstatcache(true, $path);
        return (int) filesize($path);
    }

    public function serverFinishUpload(string $id, string $sha256): void
    {
        $path = $this->uploadPath($id);
        if (!hash_equals((string) hash_file('sha256', $path), $sha256)) {
            throw new \RuntimeException('Plik dotarł uszkodzony - spróbuj jeszcze raz.');
        }
        $final = $this->dir() . '/in-' . $id . '.zip';
        rename($path, $final);
        $this->saveTask(['type' => 'import', 'status' => 'pending', 'step' => 'Czeka na worker', 'file' => basename($final)]);
    }

    /** Komputer zrezygnował albo nie wgrał danych - online wraca do pracy. */
    public function serverAbort(): void
    {
        $this->resumeHere();
    }

    /** Ścieżka gotowego pakietu do pobrania (zadanie export w stanie ready). */
    public function readyExportPath(): string
    {
        $task = $this->task();
        if (($task['type'] ?? '') !== 'export' || ($task['status'] ?? '') !== 'ready') {
            throw new \RuntimeException('Pakiet nie jest gotowy.');
        }
        return $this->safePath((string) $task['file']);
    }

    // ================================================================== worker

    /** Wywoływane na początku każdego przebiegu workera (także gdy instalacja jest wstrzymana). */
    public function tick(): void
    {
        $task = $this->task();
        if ($task === null || ($task['status'] ?? '') !== 'pending') {
            return;
        }
        @set_time_limit(0);
        $this->updateTask(['status' => 'running', 'step' => 'Start']);
        try {
            match ($task['type']) {
                'export' => $this->runExport(),
                'import' => $this->runImport((string) $task['file']),
                'pull'   => $this->runPull(),
                'push'   => $this->runPush(),
                default  => throw new \RuntimeException('Nieznane zadanie przekazania.'),
            };
        } catch (\Throwable $e) {
            Logger::error('Przekazanie: ' . $e->getMessage());
            $this->updateTask(['status' => 'failed', 'step' => 'Błąd', 'error' => $e->getMessage()]);
            // Komputer, który wysyłał dane, wraca do pracy - online ich nie przejął.
            if ($task['type'] === 'push') {
                $this->clearAway();
            }
        }
    }

    private function runExport(): void
    {
        $this->updateTask(['step' => 'Pakowanie danych']);
        $file = $this->buildPackage($this->currentTokenForPackage());
        $this->updateTask(['status' => 'ready', 'step' => 'Pakiet gotowy', 'file' => basename($file),
            'size' => filesize($file), 'sha256' => hash_file('sha256', $file)]);
    }

    private function runImport(string $file): void
    {
        $this->updateTask(['step' => 'Wgrywanie danych']);
        $path = $this->safePath($file);
        $manifest = $this->importPackage($path, $this->currentTokenForPackage());
        @unlink($path);
        $this->catchUpOrders((int) $manifest['created_at']);
        $this->clearAway();
        $this->saveTask(['type' => 'import', 'status' => 'done', 'step' => 'Online przejął pracę']);
    }

    private function runPull(): void
    {
        ['url' => $url, 'token' => $token] = self::parseCode((string) $this->settings->get(self::PEER_CODE, ''));
        $this->updateTask(['step' => 'Online się wstrzymuje i pakuje dane']);
        $this->call($url, $token, 'export');
        $ready = $this->waitForPeer($url, $token, 'export', 'ready');

        $this->updateTask(['step' => 'Pobieranie danych']);
        $local = $this->dir() . '/pull-' . bin2hex(random_bytes(8)) . '.zip';
        $out = fopen($local, 'xb');
        try {
            for ($offset = 0; $offset < (int) $ready['size'];) {
                $res = Http::request('POST', $url . '/handover.php?action=download&offset=' . $offset, $this->headers($token), '', 120);
                if (!$res->isSuccess() || $res->body === '') {
                    throw new \RuntimeException('Pobieranie z online przerwane (HTTP ' . $res->status . ').');
                }
                fwrite($out, $res->body);
                $offset += strlen($res->body);
                $this->updateTask(['step' => 'Pobieranie danych (' . (int) round(100 * $offset / max(1, (int) $ready['size'])) . '%)']);
            }
        } finally {
            fclose($out);
        }
        if (!hash_equals((string) $ready['sha256'], (string) hash_file('sha256', $local))) {
            throw new \RuntimeException('Pobrany plik jest uszkodzony - spróbuj jeszcze raz.');
        }

        $this->updateTask(['step' => 'Wgrywanie danych']);
        try {
            $manifest = $this->importPackage($local, $token);
        } catch (\Throwable $e) {
            // Komputer nie przejął danych - online wraca do pracy.
            try { $this->call($url, $token, 'abort'); } catch (\Throwable) {}
            throw $e;
        } finally {
            @unlink($local);
        }
        try { $this->call($url, $token, 'finish'); } catch (\Throwable) {}
        $this->catchUpOrders((int) $manifest['created_at']);
        $this->clearAway();
        $this->saveTask(['type' => 'pull', 'status' => 'done', 'step' => 'Ten komputer przejął pracę']);
    }

    private function runPush(): void
    {
        ['url' => $url, 'token' => $token] = self::parseCode((string) $this->settings->get(self::PEER_CODE, ''));
        $this->updateTask(['step' => 'Pakowanie danych']);
        $file = $this->buildPackage($token);
        try {
            $id = (string) ($this->call($url, $token, 'upload_start')['id'] ?? '');
            $size = (int) filesize($file);
            $in = fopen($file, 'rb');
            try {
                for ($offset = 0; $offset < $size;) {
                    $chunk = (string) fread($in, self::CHUNK);
                    $this->call($url, $token, 'upload', ['id' => $id, 'offset' => $offset], $chunk);
                    $offset += strlen($chunk);
                    $this->updateTask(['step' => 'Wysyłanie danych (' . (int) round(100 * $offset / max(1, $size)) . '%)']);
                }
            } finally {
                fclose($in);
            }
            $this->call($url, $token, 'upload_finish', ['id' => $id, 'sha256' => (string) hash_file('sha256', $file)]);
        } finally {
            @unlink($file);
        }
        $this->updateTask(['step' => 'Online wgrywa dane']);
        $this->waitForPeer($url, $token, 'import', 'done');
        $this->saveTask(['type' => 'push', 'status' => 'done', 'step' => 'Online przejął pracę']);
    }

    /**
     * Zmiany w sklepach z czasu przekazania (np. opłacenie) - webhooki trafiały wtedy do wstrzymanej
     * strony. Dociągamy zamówienia zmienione od chwili spakowania danych (z zapasem godziny).
     */
    private function catchUpOrders(int $since): void
    {
        try {
            (new Scheduler($this->pdo, $this->settings, new \Pase\Queue\Queue($this->pdo)))
                ->pullModifiedSince(gmdate('Y-m-d\TH:i:s', $since - 3600));
        } catch (\Throwable $e) {
            Logger::warn('Przekazanie: dociąganie zmienionych zamówień nie powiodło się: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> zadanie drugiej strony, gdy osiągnie status $want */
    private function waitForPeer(string $url, string $token, string $type, string $want, int $timeout = 1800): array
    {
        $until = time() + $timeout;
        while (time() < $until) {
            $task = $this->call($url, $token, 'status')['task'] ?? null;
            if (is_array($task) && ($task['type'] ?? '') === $type) {
                if (($task['status'] ?? '') === $want) { return $task; }
                if (($task['status'] ?? '') === 'failed') {
                    throw new \RuntimeException('Online: ' . ($task['error'] ?? 'błąd'));
                }
                $this->updateTask(['step' => 'Online: ' . ($task['step'] ?? '…')]);
            }
            sleep(5);
        }
        throw new \RuntimeException('Online nie odpowiedział na czas - sprawdź, czy działa tam cron workera.');
    }

    /** @param array<string,scalar> $query @return array<string,mixed> */
    private function call(string $url, string $token, string $action, array $query = [], string $body = ''): array
    {
        $res = Http::request('POST', $url . '/handover.php?' . http_build_query(['action' => $action] + $query), $this->headers($token), $body, 120);
        $data = $res->json();
        if (!$res->isSuccess()) {
            throw new \RuntimeException((string) ($data['error'] ?? ('Online odpowiedział kodem ' . $res->status . '.')));
        }
        return $data;
    }

    /** @return array<string,string> */
    private function headers(string $token): array
    {
        return ['X-CRM-Handover' => $token, 'Content-Type' => 'application/octet-stream'];
    }

    /**
     * Hasło pakietu = sekret połączenia. Online zna tylko skrót, więc dostaje sekret z żądania
     * (public/handover.php zapisuje go na czas zadania w pliku poza WWW).
     */
    private function currentTokenForPackage(): string
    {
        $t = trim((string) @file_get_contents($this->dir() . '/.token'));
        if (!$this->verifyToken($t)) {
            throw new \RuntimeException('Brak sekretu połączenia - wygeneruj nowy kod i spróbuj jeszcze raz.');
        }
        return $t;
    }

    /** Wywoływane przez public/handover.php po sprawdzeniu sekretu. */
    public function rememberToken(string $token): void
    {
        file_put_contents($this->dir() . '/.token', $token, LOCK_EX);
        @chmod($this->dir() . '/.token', 0600);
    }

    // ================================================================== pakiet

    /** Buduje zaszyfrowany pakiet (baza + storage + klucz 2FA). @return string ścieżka */
    public function buildPackage(string $password): string
    {
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new \RuntimeException('Brak szyfrowania ZIP AES-256 (rozszerzenie PHP zip).');
        }
        $stage = $this->dir() . '/stage-' . bin2hex(random_bytes(8));
        mkdir($stage, 0700);
        $name = $this->dir() . '/out-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $this->dropStaging();
            $tz = (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
            $this->pdo->exec("SET time_zone = '+00:00'");
            try {
                $db = DatabaseSnapshot::export($this->pdo, $stage . '/database.jsonl');
            } finally {
                $this->pdo->prepare('SET time_zone = ?')->execute([$tz]);
            }
            $files = ['database.jsonl' => $stage . '/database.jsonl'];
            foreach ($this->storageFiles() as $rel => $path) { $files['storage/' . $rel] = $path; }
            $key = $this->totpKeyPath();
            if (is_file($key)) { $files['secrets/totp.key'] = $key; }

            $manifest = ['format' => self::FORMAT, 'created_at' => time(), 'mode' => AppMode::isLocal() ? 'local' : 'server',
                'db_tables' => $db['tables'], 'files' => []];
            $zip = new ZipArchive();
            if ($zip->open($name, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new \RuntimeException('Nie można utworzyć pakietu.'); }
            $zip->setPassword($password);
            foreach ($files as $entry => $path) {
                if (!$zip->addFile($path, $entry) || !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    $zip->close();
                    throw new \RuntimeException('Nie można dodać pliku do pakietu: ' . $entry);
                }
                $manifest['files'][$entry] = ['size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
            }
            $zip->addFromString('package.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $zip->setEncryptionName('package.json', ZipArchive::EM_AES_256);
            if (!$zip->close()) { throw new \RuntimeException('Nie można zapisać pakietu (brak miejsca na dysku?).'); }
            @chmod($name, 0600);
            return $name;
        } catch (\Throwable $e) {
            @unlink($name);
            throw $e;
        } finally {
            $this->removeTree($stage, true);
        }
    }

    /**
     * Wgrywa pakiet: rozpakowanie i sprawdzenie sum, baza do tabel tymczasowych, jedna atomowa
     * podmiana (RENAME TABLE), potem pliki i klucz 2FA. Błąd przed podmianą nie zmienia niczego.
     *
     * @return array<string,mixed> manifest
     */
    public function importPackage(string $zipPath, string $password): array
    {
        $work = $this->dir() . '/work-' . bin2hex(random_bytes(8));
        mkdir($work, 0700);
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) { throw new \RuntimeException('Nie można otworzyć pakietu.'); }
            $zip->setPassword($password);
            $manifest = json_decode((string) $zip->getFromName('package.json'), true);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
                $zip->close();
                throw new \RuntimeException('Pakiet nie pasuje do tego połączenia albo jest uszkodzony.');
            }
            foreach ($manifest['files'] as $entry => $meta) {
                if (!self::safeEntry((string) $entry)) { $zip->close(); throw new \RuntimeException('Niedozwolona ścieżka w pakiecie.'); }
                $target = $work . '/' . $entry;
                if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
                $data = $zip->getFromName((string) $entry);
                if ($data === false || strlen($data) !== (int) $meta['size'] || !hash_equals((string) $meta['sha256'], hash('sha256', $data))) {
                    $zip->close();
                    throw new \RuntimeException('Pakiet jest uszkodzony (' . $entry . ').');
                }
                file_put_contents($target, $data);
            }
            $zip->close();

            $key = $this->totpKeyPath();
            if (is_file($work . '/secrets/totp.key') && !$this->writable($key)) {
                throw new \RuntimeException('Nie można zapisać klucza 2FA: ' . $key);
            }

            $this->restoreDatabase($work . '/database.jsonl', (int) $manifest['created_at']);

            // Pliki: dokładamy i nadpisujemy (nie kasujemy plików, których pakiet nie ma).
            $storage = rtrim($this->root, '/') . '/storage/';
            foreach (array_keys($manifest['files']) as $entry) {
                if (!str_starts_with((string) $entry, 'storage/')) { continue; }
                $dest = $storage . substr((string) $entry, 8);
                if (!is_dir(dirname($dest))) { @mkdir(dirname($dest), 0775, true); }
                if (!@copy($work . '/' . $entry, $dest)) { Logger::warn('Przekazanie: nie skopiowano pliku ' . $entry); }
            }
            if (is_file($work . '/secrets/totp.key')) {
                if (!is_dir(dirname($key))) { @mkdir(dirname($key), 0700, true); }
                copy($work . '/secrets/totp.key', $key);
                @chmod($key, 0600);
                \Pase\Support\SecretStore::useKeyFile(null); // klucz sekretów pochodzi z tego pliku - wczytaj nowy
            }
            return $manifest;
        } finally {
            $this->removeTree($work, true);
        }
    }

    private function restoreDatabase(string $snapshot, int $createdAt): void
    {
        $this->dropStaging();
        $existing = array_flip($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $tz = (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $this->pdo->exec("SET time_zone = '+00:00'");
        $in = fopen($snapshot, 'rb');
        $loaded = []; $table = null; $columns = []; $batch = []; $complete = false;
        $flush = function () use (&$batch, &$table, &$columns): void {
            if ($batch === [] || $table === null) { return; }
            $cols = implode(',', array_map(self::id(...), $columns));
            $row = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            $this->pdo->prepare('INSERT INTO ' . self::id(self::STAGE . $table) . " ($cols) VALUES " . implode(',', array_fill(0, count($batch), $row)))
                ->execute(array_merge(...$batch));
            $batch = [];
        };
        try {
            $header = json_decode((string) fgets($in), true);
            if (($header['format'] ?? '') !== 'crm-db-v1' || ($header['driver'] ?? '') !== 'mysql') {
                throw new \RuntimeException('Nieobsługiwany format bazy w pakiecie.');
            }
            while (($line = fgets($in)) !== false) {
                $rec = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (isset($rec['table'])) {
                    $flush();
                    $table = (string) $rec['table'];
                    self::id($table);
                    if (isset($existing[$table])) {
                        // Struktura odbiorcy: inna wersja CRM po drugiej stronie nie psuje tabel.
                        $this->pdo->exec('CREATE TABLE ' . self::id(self::STAGE . $table) . ' LIKE ' . self::id($table));
                    } else {
                        $sql = preg_replace('/^CREATE TABLE\s+`' . preg_quote($table, '/') . '`/i', 'CREATE TABLE ' . self::id(self::STAGE . $table), (string) $rec['schema'], 1, $n);
                        if ($n !== 1) { throw new \RuntimeException('Nieprawidłowa definicja tabeli ' . $table); }
                        $this->pdo->exec($sql);
                    }
                    $columns = $this->pdo->query('SHOW COLUMNS FROM ' . self::id(self::STAGE . $table))->fetchAll(PDO::FETCH_COLUMN);
                    $loaded[] = $table;
                } elseif (isset($rec['row']) && $table !== null) {
                    $decode = static fn($v) => $v === null ? null : base64_decode((string) $v, true);
                    if (array_diff($columns, array_keys($rec['row'])) !== []) {
                        // Kolumny, których nadawca nie ma: domyślne wartości odbiorcy zamiast NULL.
                        $flush();
                        $use = array_values(array_intersect($columns, array_keys($rec['row'])));
                        $this->pdo->prepare('INSERT INTO ' . self::id(self::STAGE . $table) . ' (' . implode(',', array_map(self::id(...), $use)) . ') VALUES ('
                            . implode(',', array_fill(0, count($use), '?')) . ')')->execute(array_map(static fn($c) => $decode($rec['row'][$c]), $use));
                        continue;
                    }
                    $batch[] = array_map(static fn($c) => $decode($rec['row'][$c]), $columns);
                    if (count($batch) >= 200) { $flush(); }
                } elseif (isset($rec['end'])) {
                    $flush();
                    $complete = true;
                }
            }
            if (!$complete) { throw new \RuntimeException('Baza w pakiecie jest niepełna.'); }

            // Ustawienia tej instalacji zostają, kursory zamówień Woo startują od chwili spakowania.
            if (in_array('settings', $loaded, true) && isset($existing['settings'])) {
                $this->pdo->prepare('DELETE FROM ' . self::id(self::STAGE . 'settings') . ' WHERE setting_key REGEXP ?')->execute([self::KEEP_SETTINGS]);
                $this->pdo->prepare('INSERT INTO ' . self::id(self::STAGE . 'settings') . ' SELECT * FROM `settings` WHERE setting_key REGEXP ? AND setting_key NOT LIKE ?')
                    ->execute([self::KEEP_SETTINGS, 'LOCAL\_ORDERS\_CURSOR\_%']);
            }

            // Jedna atomowa podmiana wszystkich tabel.
            $renames = [];
            foreach ($loaded as $t) {
                if (isset($existing[$t])) { $renames[] = self::id($t) . ' TO ' . self::id(self::OLD . $t); }
                $renames[] = self::id(self::STAGE . $t) . ' TO ' . self::id($t);
            }
            $this->pdo->exec('RENAME TABLE ' . implode(', ', $renames));
        } catch (\Throwable $e) {
            $this->dropStaging();
            throw $e;
        } finally {
            fclose($in);
            $this->pdo->prepare('SET time_zone = ?')->execute([$tz]);
        }
        $this->dropStaging();
        Logger::info('Przekazanie: wgrano ' . count($loaded) . ' tabel z pakietu z ' . gmdate('c', $createdAt));
    }

    private function dropStaging(): void
    {
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (str_starts_with((string) $t, self::STAGE) || str_starts_with((string) $t, self::OLD)) {
                $this->pdo->exec('DROP TABLE ' . self::id((string) $t));
            }
        }
    }

    /** @return array<string,string> ścieżka względna w storage/ => bezwzględna */
    private function storageFiles(): array
    {
        $base = rtrim($this->root, '/') . '/storage';
        $files = [];
        $walk = function (string $dir, string $prefix) use (&$walk, &$files): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                $rel = $prefix === '' ? $entry : $prefix . '/' . $entry;
                if ($prefix === '' && in_array($entry, self::SKIP_STORAGE, true)) { continue; }
                if (preg_match('/\.(log|lock|part)$/', $entry)) { continue; }
                $path = $dir . '/' . $entry;
                if (is_link($path)) { continue; }
                if (is_dir($path)) { $walk($path, $rel); continue; }
                if (is_file($path) && is_readable($path)) { $files[$rel] = $path; }
            }
        };
        if (is_dir($base)) { $walk($base, ''); }
        return $files;
    }

    // ================================================================== pomocnicze

    public function dir(): string
    {
        $dir = rtrim($this->root, '/') . '/storage/handover';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu storage/handover.');
        }
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
        return $dir;
    }

    private function uploadPath(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new \RuntimeException('Nieprawidłowy identyfikator przesyłki.'); }
        $path = $this->dir() . '/in-' . $id . '.part';
        if (!is_file($path)) { throw new \RuntimeException('Nie ma takiej przesyłki.'); }
        return $path;
    }

    private function safePath(string $file): string
    {
        if (!preg_match('/^(out|in)-[a-f0-9]{16}\.zip$/D', $file)) { throw new \RuntimeException('Nieprawidłowa nazwa pakietu.'); }
        $path = $this->dir() . '/' . $file;
        if (!is_file($path)) { throw new \RuntimeException('Nie ma pakietu.'); }
        return $path;
    }

    private static function safeEntry(string $entry): bool
    {
        return $entry === 'database.jsonl' || $entry === 'secrets/totp.key'
            || (str_starts_with($entry, 'storage/') && !str_contains($entry, '..') && !str_contains($entry, "\0") && !str_contains($entry, '\\'));
    }

    private function totpKeyPath(): string
    {
        return (string) Env::get('TOTP_KEY_FILE', rtrim($this->root, '/') . '/storage/security/totp.key');
    }

    private function writable(string $path): bool
    {
        return is_file($path) ? is_writable($path) : (is_dir(dirname($path)) ? is_writable(dirname($path)) : is_writable(dirname($path, 2)));
    }

    private static function id(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name)) { throw new \RuntimeException('Nieobsługiwana nazwa tabeli: ' . $name); }
        return '`' . $name . '`';
    }

    private function removeTree(string $path, bool $self): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..' || (!$self && in_array($e, ['.htaccess', '.token'], true))) { continue; }
            $this->removeTree($path . '/' . $e, true);
        }
        if ($self) { @rmdir($path); }
    }
}
