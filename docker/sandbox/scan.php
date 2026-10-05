<?php
declare(strict_types=1);

/**
 * Statyczny przegląd kodu wtyczki (sandbox wtyczek, krok 1).
 *
 *   php docker/sandbox/scan.php <katalog-wtyczki> [plik-wyniku.json]
 *
 * Czyta pliki, niczego nie uruchamia. Każde znalezisko ma poziom:
 *   critical - blokuje wtyczkę (polecenia systemowe, eval, dołączanie kodu z sieci, ukryty PHP),
 *   review   - człowiek musi sprawdzić, po co (sieć, zapis plików, dostęp do kluczy, zaciemnianie),
 *   info     - dla przeglądającego (adresy w kodzie, zapytania SQL).
 * Wynik: JSON na stdout (albo do pliku). Kod wyjścia 0 - skan się udał, niezależnie od znalezisk.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class PluginScan
{
    /** Funkcje uruchamiające polecenia systemu albo dowolny kod. */
    private const CRITICAL_CALLS = [
        'exec' => 'uruchamia polecenie systemowe', 'shell_exec' => 'uruchamia polecenie systemowe',
        'system' => 'uruchamia polecenie systemowe', 'passthru' => 'uruchamia polecenie systemowe',
        'proc_open' => 'uruchamia proces', 'popen' => 'uruchamia proces', 'pcntl_exec' => 'podmienia proces',
        'eval' => 'wykonuje kod z tekstu', 'create_function' => 'wykonuje kod z tekstu',
        'assert' => 'może wykonać kod z tekstu', 'dl' => 'ładuje rozszerzenie PHP',
        'putenv' => 'zmienia środowisko procesu', 'ini_set' => 'zmienia ustawienia PHP',
        'set_include_path' => 'zmienia ścieżki dołączania kodu', 'register_shutdown_function' => null,
    ];

    /** Funkcje, które same w sobie są w porządku, ale przeglądający musi wiedzieć, po co są. */
    private const REVIEW_CALLS = [
        // sieć
        'curl_init' => 'sieć', 'curl_multi_init' => 'sieć', 'fsockopen' => 'sieć', 'pfsockopen' => 'sieć',
        'stream_socket_client' => 'sieć', 'socket_create' => 'sieć', 'socket_connect' => 'sieć',
        'mail' => 'sieć', 'dns_get_record' => 'sieć', 'gethostbyname' => 'sieć', 'get_headers' => 'sieć',
        // pliki
        'file_put_contents' => 'zapis plików', 'fwrite' => 'zapis plików', 'fputs' => 'zapis plików',
        'unlink' => 'zapis plików', 'rename' => 'zapis plików', 'copy' => 'zapis plików', 'rmdir' => 'zapis plików',
        'mkdir' => 'zapis plików', 'chmod' => 'zapis plików', 'chown' => 'zapis plików', 'touch' => 'zapis plików',
        'symlink' => 'zapis plików', 'link' => 'zapis plików', 'move_uploaded_file' => 'zapis plików',
        'tempnam' => 'zapis plików', 'scandir' => 'odczyt plików', 'glob' => 'odczyt plików',
        'opendir' => 'odczyt plików', 'readfile' => 'odczyt plików', 'file' => 'odczyt plików',
        'parse_ini_file' => 'odczyt plików',
        // zaciemnianie i niebezpieczne konwersje
        'base64_decode' => 'zaciemnianie', 'gzinflate' => 'zaciemnianie', 'gzuncompress' => 'zaciemnianie',
        'gzdecode' => 'zaciemnianie', 'str_rot13' => 'zaciemnianie', 'hex2bin' => 'zaciemnianie',
        'convert_uudecode' => 'zaciemnianie', 'strrev' => 'zaciemnianie', 'unserialize' => 'zaciemnianie',
        // wywołania po nazwie z tekstu
        'call_user_func' => 'wywołanie po nazwie', 'call_user_func_array' => 'wywołanie po nazwie',
        'forward_static_call' => 'wywołanie po nazwie', 'forward_static_call_array' => 'wywołanie po nazwie',
        'extract' => 'wywołanie po nazwie', 'parse_str' => 'wywołanie po nazwie',
        // sekrety i środowisko
        'getenv' => 'sekrety', 'phpinfo' => 'sekrety', 'get_defined_vars' => 'sekrety',
        'openssl_decrypt' => 'sekrety', 'openssl_private_decrypt' => 'sekrety',
        // sesje i nagłówki
        'session_id' => 'sesje', 'session_decode' => 'sesje', 'setcookie' => 'sesje',
        'header' => 'odpowiedź HTTP', 'header_remove' => 'odpowiedź HTTP',
        'set_error_handler' => 'obsługa błędów', 'set_exception_handler' => 'obsługa błędów',
        'error_reporting' => 'obsługa błędów',
    ];

    /** fopen/file_get_contents są w porządku dla plików wtyczki, podejrzane dla adresów i trybów zapisu. */
    private const STREAM_CALLS = ['fopen', 'file_get_contents', 'readfile', 'file', 'copy', 'opendir'];

    /** Tabele i pliki, które trzymają tokeny, hasła i klucze. */
    private const SENSITIVE_TEXT = [
        '/\b(admin_users|integrations|integration_accounts|mail_accounts|settings)\b/i' => 'tabela z sekretami',
        '/\b(password_hash|totp_secret|totp_recovery|access_token|refresh_token|webhook_secret|consumer_secret|session_version)\b/i' => 'pole z sekretem',
        '/totp\.key|\.env\b|storage\/security|plugins\.json|ADMIN_PASSWORD/i' => 'plik z kluczami',
        '/\b(DB_PASS|DB_USER|WORKER_HTTP_SECRET|ADMIN_DEFAULT_PASSWORD)\b/' => 'zmienna z hasłem',
    ];

    /** Klasy rdzenia, przez które idzie się do sekretów albo do innych wtyczek. */
    private const SENSITIVE_CLASSES = [
        'SecretStore' => 'odszyfrowuje klucze API', 'TwoFactorCipher' => 'klucz 2FA', 'TwoFactorService' => 'logowanie 2FA',
        'IntegrationRepository' => 'tokeny Allegro', 'IntegrationAccountRepository' => 'konta i klucze wszystkich integracji',
        'SettingsRepository' => 'ustawienia z kluczami API', 'MailAccounts' => 'hasła SMTP', 'Env' => 'plik .env',
        'PluginInstaller' => 'instalacja wtyczek', 'Handover' => 'przeniesienie danych', 'FullSystemPackage' => 'pełna kopia systemu',
        'BackupArchive' => 'kopie zapasowe', 'SectionBackup' => 'kopie zapasowe', 'DatabaseSnapshot' => 'zrzut bazy',
        'PagePermissions' => 'uprawnienia', 'ReflectionProperty' => 'dostęp do prywatnych pól', 'ReflectionClass' => 'dostęp do prywatnych pól',
    ];

    /** Rozszerzenia plików, które wolno mieć w paczce. Reszta - do przeglądu. */
    private const ALLOWED_EXT = ['php', 'js', 'css', 'json', 'md', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'html', 'csv', 'mo', 'po'];

    /** @var array<string,array{level:string,category:string,file:string,line:int,lines:list<int>,message:string,code:string}> */
    private array $findings = [];
    /** @var array<string,int> */
    private array $hosts = [];
    private string $root;
    /** 'extension' (bez kont i API, z założenia nie potrzebuje sekretów) albo 'integration'. */
    private string $kind;
    private int $files = 0;
    private int $lines = 0;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->kind = preg_match('/\bPaseExt\\\\/', (string) @file_get_contents($this->root . '/register.php')) ? 'extension' : 'integration';
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($this->root) + 1);
            if ($f->isLink()) {
                $this->add('critical', 'pliki', $rel, 0, 'Dowiązanie symboliczne w paczce (może wskazywać pliki poza wtyczką).', (string) readlink($f->getPathname()));
                continue;
            }
            if (!$f->isFile()) {
                continue;
            }
            $this->files++;
            $this->scanFile($f->getPathname(), $rel);
        }
        $this->findings = array_values($this->findings);
        usort($this->findings, static fn(array $a, array $b): int => [self::rank($a['level']), $a['file'], $a['line']] <=> [self::rank($b['level']), $b['file'], $b['line']]);
        arsort($this->hosts);
        $count = ['critical' => 0, 'review' => 0, 'info' => 0];
        foreach ($this->findings as $x) {
            $count[$x['level']]++;
        }
        return [
            'kind' => $this->kind,
            'files' => $this->files,
            'lines' => $this->lines,
            'counts' => $count,
            'hosts' => array_keys($this->hosts),
            'findings' => $this->findings,
        ];
    }

    private static function rank(string $level): int
    {
        return ['critical' => 0, 'review' => 1, 'info' => 2][$level] ?? 3;
    }

    private function add(string $level, string $category, string $file, int $line, string $message, string $code = ''): void
    {
        // To samo znalezisko w tym samym pliku = jeden wpis z listą linii (np. access_token w kliencie OAuth).
        $key = $level . "\0" . $category . "\0" . $file . "\0" . $message;
        if (isset($this->findings[$key])) {
            if (!in_array($line, $this->findings[$key]['lines'], true)) {
                $this->findings[$key]['lines'][] = $line;
            }
            return;
        }
        $code = trim(preg_replace('/\s+/', ' ', $code) ?? '');
        $this->findings[$key] = ['level' => $level, 'category' => $category, 'file' => $file, 'line' => $line, 'lines' => [$line],
            'message' => $message, 'code' => mb_strlen($code) > 160 ? mb_substr($code, 0, 157) . '...' : $code];
    }

    private function scanFile(string $path, string $rel): void
    {
        $base = basename($rel);
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $data = (string) file_get_contents($path);

        if ($base === '.htaccess' || $base === '.user.ini' || $base === 'php.ini') {
            $this->add('critical', 'pliki', $rel, 0, 'Plik konfiguracji serwera w paczce - może zdjąć blokadę katalogu albo zmienić ustawienia PHP.');
            return;
        }
        if (in_array($ext, ['phar', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'inc'], true)) {
            $this->add('critical', 'pliki', $rel, 0, "Plik .{$ext} - kod PHP poza zwykłymi plikami .php.");
            return;
        }
        if ($ext !== 'php' && preg_match('/<\?(php|=)/i', $data)) {
            $this->add('critical', 'pliki', $rel, 0, 'Kod PHP ukryty w pliku, który nie jest .php.');
        }
        if ($ext !== '' && !in_array($ext, self::ALLOWED_EXT, true) && $base !== '.disabled') {
            $this->add('review', 'pliki', $rel, 0, "Nietypowy plik .{$ext} w paczce.");
        }
        if ($ext === 'js' || $ext === 'html') {
            $this->scanText($rel, $data, true);
            return;
        }
        if ($ext !== 'php') {
            return;
        }
        $this->lines += substr_count($data, "\n") + 1;
        $this->scanPhp($rel, $data);
    }

    /** Adresy i odwołania do sekretów w zwykłym tekście (JS, HTML) i w literałach PHP. */
    private function scanText(string $rel, string $text, bool $isScript, int $lineOffset = 0): void
    {
        if (preg_match_all('#\b(?:https?|wss?|ftp)://([a-z0-9.-]+\.[a-z]{2,}|\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?#i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$host, $off]) {
                $host = strtolower($host);
                $this->hosts[$host] = ($this->hosts[$host] ?? 0) + 1;
                $line = $lineOffset + substr_count(substr($text, 0, $off), "\n") + 1;
                if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) {
                    $this->add('review', 'sieć', $rel, $line, "Adres IP zamiast nazwy serwera: {$host}.", $m[0][0][0] ?? $host);
                }
            }
        }
        if ($isScript) {
            foreach (['/\beval\s*\(/' => 'eval() w skrypcie przeglądarki', '/\bnew\s+Function\s*\(/' => 'new Function() w skrypcie przeglądarki',
                      '/document\.cookie/' => 'odczyt ciasteczek panelu', '/\bnavigator\.sendBeacon\s*\(/' => 'wysyłka danych w tle (sendBeacon)',
                      '/\b(?:fetch|XMLHttpRequest)\b[^;\n]*https?:\/\//' => 'zapytanie z przeglądarki na zewnętrzny adres',
                      '/<script[^>]+src=["\']?https?:/i' => 'skrypt z zewnętrznego serwera', '/\bimportScripts\s*\(/' => 'skrypt z zewnętrznego serwera',
                      '/\blocalStorage\b|\bsessionStorage\b/' => 'pamięć przeglądarki'] as $re => $msg) {
                if (preg_match_all($re, $text, $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[0] as [$snip, $off]) {
                        $level = in_array($msg, ['pamięć przeglądarki'], true) ? 'info' : (str_contains($msg, 'eval') || str_contains($msg, 'Function') || str_contains($msg, 'ciasteczek') ? 'critical' : 'review');
                        $this->add($level, 'przeglądarka', $rel, $lineOffset + substr_count(substr($text, 0, $off), "\n") + 1, ucfirst($msg) . '.', $snip);
                    }
                }
            }
        }
    }

    private function scanPhp(string $rel, string $code): void
    {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (\Throwable $e) {
            $this->add('critical', 'kod', $rel, (int) ($e->getLine() ?: 0), 'Plik nie parsuje się jako PHP: ' . $e->getMessage());
            $tokens = token_get_all($code);
        }
        $lineOf = static fn($t): int => is_array($t) ? $t[2] : 0;
        $sig = [];   // tokeny bez białych znaków i komentarzy, z zachowaniem numerów linii
        $line = 1;
        foreach ($tokens as $t) {
            if (is_array($t)) {
                $line = $t[2];
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $sig[] = [$t[0], $t[1], $t[2]];
            } else {
                $sig[] = [0, $t, $line];
            }
        }
        $n = count($sig);
        $snippet = static function (int $i) use ($sig, $n): string {
            $s = '';
            $line = $sig[$i][2];
            for ($j = max(0, $i - 3); $j < $n && $j < $i + 12; $j++) {
                if ($sig[$j][2] < $line - 1 || $sig[$j][2] > $line + 1) {
                    continue;
                }
                $s .= $sig[$j][1] . ' ';
            }
            return $s;
        };

        for ($i = 0; $i < $n; $i++) {
            [$type, $text, $ln] = $sig[$i];
            $prev = $sig[$i - 1] ?? [0, '', 0];
            $next = $sig[$i + 1] ?? [0, '', 0];

            // Odwrócony apostrof = shell_exec.
            if ($type === 0 && $text === '`') {
                $this->add('critical', 'kod', $rel, $ln, 'Odwrócone apostrofy `...` uruchamiają polecenie systemowe.', $snippet($i));
                continue;
            }
            if ($type === T_EVAL) {
                $this->add('critical', 'kod', $rel, $ln, 'eval() wykonuje kod z tekstu.', $snippet($i));
                continue;
            }
            if (in_array($type, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $this->checkInclude($rel, $sig, $i, $snippet($i));
                continue;
            }
            if ($type === T_HALT_COMPILER) {
                $this->add('critical', 'kod', $rel, $ln, '__halt_compiler() - dane doklejone za kodem (typowe dla ukrytych paczek).', $snippet($i));
                continue;
            }
            if ($type === T_GLOBAL) {
                $this->add('review', 'sekrety', $rel, $ln, 'Zmienne globalne (global) - dostęp do stanu aplikacji spoza wtyczki.', $snippet($i));
            }
            if ($type === T_VARIABLE && in_array($text, ['$_ENV', '$GLOBALS', '$_SESSION', '$_COOKIE', '$_FILES'], true)) {
                $this->add('review', 'sekrety', $rel, $ln, "Bezpośredni dostęp do {$text}.", $snippet($i));
            }
            if ($type === T_VARIABLE && $text === '$_SERVER' && preg_match('/PHP_AUTH|HTTP_COOKIE|HTTP_AUTHORIZATION|DB_|SECRET|PASS/i', $snippet($i))) {
                $this->add('review', 'sekrety', $rel, $ln, '$_SERVER z hasłem albo ciasteczkiem.', $snippet($i));
            }

            // Wywołanie po zmiennej: $f(...), $obj->$m(...), new $class.
            if ($type === T_VARIABLE && $next[1] === '(' && !in_array($prev[1], ['function', 'fn', '->', '::', '?->'], true) && $prev[0] !== T_FUNCTION && $prev[0] !== T_FN) {
                $this->add('info', 'wywołanie po nazwie', $rel, $ln, "Wywołanie funkcji albo closure ze zmiennej ({$text}(...)).", $snippet($i));
            }
            if ($type === T_NEW && $next[0] === T_VARIABLE) {
                $this->add('review', 'wywołanie po nazwie', $rel, $ln, 'Tworzenie obiektu klasy o nazwie ze zmiennej (new $x).', $snippet($i));
            }

            // Nazwa niebezpiecznej funkcji złożona z tekstu: 'sys' . 'tem', potem $f(...) albo call_user_func.
            if ($type === T_CONSTANT_ENCAPSED_STRING && ($prev[1] !== '.')) {
                $joined = substr($text, 1, -1);
                for ($j = $i + 1; $j + 1 < $n && $sig[$j][1] === '.' && $sig[$j + 1][0] === T_CONSTANT_ENCAPSED_STRING; $j += 2) {
                    $joined .= substr($sig[$j + 1][1], 1, -1);
                }
                $fn = strtolower(trim($joined, '\\ '));
                if (isset(self::CRITICAL_CALLS[$fn]) && self::CRITICAL_CALLS[$fn] !== null && !in_array($fn, ['ini_set', 'putenv', 'set_include_path', 'system'], true)
                    || in_array($fn, ['system', 'base64_decode', 'gzinflate', 'str_rot13'], true) && $j > $i + 1) {
                    $this->add('critical', 'zaciemnianie', $rel, $ln, "Nazwa funkcji {$fn}() zapisana jako tekst - typowe ukrywanie wywołania.", $snippet($i));
                }
            }

            // Literały: adresy, nazwy tabel z sekretami, długie zakodowane ciągi.
            if ($type === T_CONSTANT_ENCAPSED_STRING || $type === T_ENCAPSED_AND_WHITESPACE) {
                $value = $type === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;
                $this->scanText($rel, $value, (bool) preg_match('/<script|document\.|\bfetch\s*\(|XMLHttpRequest|new\s+Image/i', $value), $ln - 1);
                foreach (self::SENSITIVE_TEXT as $re => $what) {
                    if (preg_match($re, $value, $mm)) {
                        $level = $what === 'tabela z sekretami' && !preg_match('/\b(select|update|insert|delete|from|join|into)\b/i', $value) ? 'info' : 'review';
                        if ($this->kind === 'integration' && $what === 'pole z sekretem') {
                            $level = 'info';   // integracja czyta własne klucze z konfiguracji konta - to normalne
                        }
                        if ($this->kind === 'extension' && ($what === 'plik z kluczami' || $what === 'zmienna z hasłem'
                            || $what === 'tabela z sekretami' && preg_match('/\b(admin_users|integrations|integration_accounts|mail_accounts)\b/i', $mm[0]) && $level === 'review')) {
                            $level = 'critical';
                        }
                        $this->add($level, 'sekrety', $rel, $ln, ucfirst($what) . ": {$mm[0]}.", $snippet($i));
                    }
                }
                if (strlen($value) >= 200 && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $value) && !preg_match('/\s{2,}/', $value)) {
                    $this->add('review', 'zaciemnianie', $rel, $ln, 'Długi ciąg w base64 (' . strlen($value) . ' znaków) - sprawdź, co zawiera.', substr($value, 0, 60));
                }
                if (preg_match_all('/\\\\x[0-9a-f]{2}/i', $value) > 8) {
                    $this->add('review', 'zaciemnianie', $rel, $ln, 'Tekst zapisany kodami \\xNN.', substr($value, 0, 60));
                }
                if (preg_match('/^\s*(select|update|insert|delete|create|alter|drop|truncate|grant|replace)\b/i', $value, $mm)) {
                    $verb = strtoupper($mm[1]);
                    $level = in_array($verb, ['DROP', 'TRUNCATE', 'GRANT', 'ALTER'], true) ? 'review' : 'info';
                    $this->add($level, 'baza danych', $rel, $ln, "Zapytanie SQL {$verb}.", $value);
                }
                continue;
            }

            if ($type !== T_STRING && $type !== T_NAME_FULLY_QUALIFIED && $type !== T_NAME_QUALIFIED) {
                continue;
            }
            $name = strtolower(ltrim((string) substr($text, (int) strrpos('\\' . $text, '\\')), '\\'));
            $short = (string) substr($text, (int) strrpos('\\' . $text, '\\'));
            $isCall = $next[1] === '(' && !in_array($prev[1], ['->', '::', '?->'], true) && $prev[0] !== T_FUNCTION && $prev[0] !== T_NEW && $prev[0] !== T_CONST;

            if (isset(self::SENSITIVE_CLASSES[$short]) && (in_array($next[1], ['::', '('], true) || $prev[0] === T_NEW) && $prev[0] !== T_USE) {
                $secretClass = in_array($short, ['SecretStore', 'TwoFactorCipher', 'Env', 'MailAccounts', 'IntegrationAccountRepository', 'IntegrationRepository'], true);
                $level = $this->kind === 'extension' && $secretClass ? 'critical' : 'review';
                $why = $level === 'critical' ? ' Rozszerzenie nie ma kont ani kluczy API, więc nie powinno tego potrzebować.' : '';
                $this->add($level, 'sekrety', $rel, $ln, "Używa {$short} (" . self::SENSITIVE_CLASSES[$short] . ').' . $why, $snippet($i));
            }
            if (isset(self::SENSITIVE_CLASSES[$short]) && $prev[0] === T_USE) {
                $this->add('info', 'sekrety', $rel, $ln, "Importuje {$short} (" . self::SENSITIVE_CLASSES[$short] . ').', $snippet($i));
            }
            if (!$isCall) {
                continue;
            }
            if (array_key_exists($name, self::CRITICAL_CALLS)) {
                if ($name === 'register_shutdown_function') {
                    $this->add('review', 'kod', $rel, $ln, 'register_shutdown_function() - kod uruchamiany po zakończeniu żądania.', $snippet($i));
                    continue;
                }
                if ($name === 'ini_set' && preg_match('/ini_set\s*\(\s*[\'"](memory_limit|max_execution_time|default_socket_timeout)/', $snippet($i))) {
                    $this->add('info', 'kod', $rel, $ln, 'ini_set() limitu pamięci/czasu.', $snippet($i));
                    continue;
                }
                $this->add('critical', 'kod', $rel, $ln, "{$name}() " . self::CRITICAL_CALLS[$name] . '.', $snippet($i));
                continue;
            }
            $args = self::argsOf($sig, $i);
            if (in_array($name, ['file_put_contents', 'fopen', 'fwrite', 'rename', 'copy', 'unlink', 'mkdir', 'symlink', 'touch', 'chmod', 'move_uploaded_file'], true)
                && preg_match('#[\'"]/?(public|src|config|cli|integrations|extensions|lang|sql|index\.php|\.htaccess)(/|[\'"])#', $args, $mm)
                && ($name !== 'fopen' || preg_match('/,\s*[\'"][waxc+]/i', $args))) {
                $this->add('critical', 'zapis plików', $rel, $ln, "{$name}() zapisuje w kodzie aplikacji ({$mm[1]}/) - tak podrzuca się furtkę.", $snippet($i));
                continue;
            }
            if (in_array($name, self::STREAM_CALLS, true)) {
                $s = $args;
                if (preg_match('#[\'"](?:https?|ftp|php|data|phar|expect|zip|glob)://#i', $s)) {
                    $level = preg_match('#[\'"](?:phar|expect|data|php://filter)#i', $s) ? 'critical' : 'review';
                    $this->add($level, 'sieć', $rel, $ln, "{$name}() na adresie albo strumieniu specjalnym.", $s);
                } elseif ($name === 'fopen' && preg_match('/,\s*[\'"][waxc]/i', $s)) {
                    $this->add('review', 'zapis plików', $rel, $ln, 'fopen() w trybie zapisu.', $s);
                } elseif ($name === 'fopen' || $name === 'file_get_contents') {
                    $this->add('info', 'odczyt plików', $rel, $ln, "{$name}() - odczyt pliku albo adresu ze zmiennej.", $s);
                } else {
                    $this->add('review', self::REVIEW_CALLS[$name] ?? 'pliki', $rel, $ln, "{$name}().", $s);
                }
                continue;
            }
            if (isset(self::REVIEW_CALLS[$name])) {
                $level = in_array($name, ['header', 'error_reporting', 'set_error_handler', 'set_exception_handler', 'glob', 'scandir'], true) ? 'info' : 'review';
                $this->add($level, self::REVIEW_CALLS[$name], $rel, $ln, "{$name}().", $snippet($i));
            }
        }
    }

    /** Tekst argumentów wywołania: od "(" za nazwą funkcji do pasującego ")". */
    private static function argsOf(array $sig, int $i): string
    {
        $depth = 0;
        $out = '';
        for ($j = $i + 1, $n = count($sig); $j < $n && $j < $i + 200; $j++) {
            $t = $sig[$j][1];
            if ($t === '(' || $t === '[' || $t === '{') {
                $depth++;
            } elseif ($t === ')' || $t === ']' || $t === '}') {
                $depth--;
            }
            $out .= $t . ' ';
            if ($depth === 0) {
                break;
            }
        }
        return $out;
    }

    /** include/require tylko plików wtyczki: stała ścieżka względem __DIR__. */
    private function checkInclude(string $rel, array $sig, int $i, string $snippet): void
    {
        $ln = $sig[$i][2];
        $expr = '';
        $hasVar = false;
        for ($j = $i + 1; $j < count($sig) && $sig[$j][1] !== ';' && $j < $i + 40; $j++) {
            $expr .= $sig[$j][1];
            if ($sig[$j][0] === T_VARIABLE || $sig[$j][0] === T_STRING && ($sig[$j + 1][1] ?? '') === '(' && !in_array(strtolower($sig[$j][1]), ['dirname', 'basename'], true)) {
                $hasVar = true;
            }
        }
        if (preg_match('#(https?|ftp|php|data|phar|expect)://#i', $expr)) {
            $this->add('critical', 'kod', $rel, $ln, 'Dołącza kod z adresu sieciowego albo strumienia.', $snippet);
        } elseif (str_contains($expr, '..')) {
            $this->add('review', 'kod', $rel, $ln, 'Dołącza plik spoza katalogu wtyczki (..).', $snippet);
        } elseif ($hasVar) {
            $this->add('review', 'kod', $rel, $ln, 'Dołącza plik o nazwie ze zmiennej.', $snippet);
        }
    }
}

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Użycie: php docker/sandbox/scan.php <katalog-wtyczki> [wynik.json]\n");
    exit(2);
}
$result = (new PluginScan((string) realpath($dir)))->run();
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if (isset($argv[2])) {
    file_put_contents($argv[2], $json . "\n");
} else {
    echo $json, "\n";
}
