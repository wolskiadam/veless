<?php
declare(strict_types=1);

/**
 * „Fałszywy internet” sandboxu wtyczek. Kontener CRM nie ma wyjścia do sieci, a jego DNS
 * wskazuje tutaj: każda nazwa (api.allegro.pl, zbieracz.example...) dostaje adres tego
 * kontenera, a każde połączenie jest odbierane i zapisywane:
 *   - HTTP (80, 8080) i HTTPS (443, 8443): metoda, adres, nagłówki i treść żądania,
 *     HTTPS odszyfrowane certyfikatem wystawionym w locie przez CA sandboxu,
 *   - SMTP (25, 587, 2525, 465): nadawca, odbiorcy i treść maila,
 *   - inne porty: pierwsze bajty.
 * Odpowiedź to zawsze „200 {}” albo „250 OK”, żeby wtyczka szła dalej i pokazała, co wysyła.
 *
 *   php sink.php <ip-tego-kontenera> <katalog-wyników>
 * Pisze <katalog>/network.jsonl (jedna linia na zdarzenie) i <katalog>/ca/ca.crt.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$ip  = $argv[1] ?? '127.0.0.1';
$out = rtrim($argv[2] ?? '/out', '/');
@mkdir("$out/ca/hosts", 0755, true);
$log = fopen("$out/network.jsonl", 'a');

const MAX_BODY = 262144;
const HTTP_PORTS = [80, 8080, 8000, 3000, 5000];
const TLS_HTTP_PORTS = [443, 8443, 9443];
const SMTP_PORTS = [25, 587, 2525];
const TLS_SMTP_PORTS = [465, 993, 995];
const RAW_PORTS = [21, 22, 23, 110, 143, 1433, 3306, 5432, 6379, 9200, 11211, 27017, 4444, 1337, 31337];

function record(array $event): void
{
    global $log;
    $event = ['t' => microtime(true)] + $event;
    fwrite($log, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush($log);
}

// ---------------------------------------------------------------- CA i certyfikaty na żądanie

function caFiles(string $dir): array
{
    $crt = "$dir/ca.crt";
    $key = "$dir/ca.key";
    if (!is_file($crt) || !is_file($key)) {
        $pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Sandbox wtyczek CRM - tylko testy', 'organizationName' => 'CRM plugin sandbox'], $pk, ['digest_alg' => 'sha256']);
        $cfg = sys_get_temp_dir() . '/ca-ext.cnf';
        file_put_contents($cfg, "[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
        $cert = openssl_csr_sign($csr, null, $pk, 30, ['digest_alg' => 'sha256', 'config' => $cfg, 'x509_extensions' => 'v3_ca'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($pk, $keyPem);
        file_put_contents($key, $keyPem);
        file_put_contents($crt, $pem);
    }
    return [$crt, $key];
}

/** Certyfikat dla nazwy z SNI (albo adresu IP), podpisany przez CA sandboxu. Zwraca ścieżkę PEM (cert + klucz). */
function leafFor(string $name, string $dir): string
{
    $safe = preg_replace('/[^a-z0-9.-]/i', '_', $name) ?: 'default';
    $path = "$dir/hosts/$safe.pem";
    if (is_file($path)) {
        return $path;
    }
    [$caCrt, $caKey] = caFiles($dir);
    $isIp = filter_var($name, FILTER_VALIDATE_IP) !== false;
    $cfg = sys_get_temp_dir() . "/leaf-$safe.cnf";
    file_put_contents($cfg, "[req]\ndistinguished_name=dn\n[dn]\n[v3_leaf]\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=" . ($isIp ? "IP:$name" : "DNS:$name") . "\n");
    $pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => substr($name, 0, 64)], $pk, ['digest_alg' => 'sha256', 'config' => $cfg]);
    $cert = openssl_csr_sign($csr, file_get_contents($caCrt), file_get_contents($caKey), 30,
        ['digest_alg' => 'sha256', 'config' => $cfg, 'x509_extensions' => 'v3_leaf'], random_int(1, PHP_INT_MAX));
    openssl_x509_export($cert, $pem);
    openssl_pkey_export($pk, $keyPem);
    file_put_contents($path, $pem . $keyPem);
    return $path;
}

/** Nazwa serwera z TLS ClientHello (rozszerzenie server_name), bez zdejmowania danych z gniazda. */
function peekSni($conn): string
{
    $data = '';
    $deadline = microtime(true) + 3;
    while (strlen($data) < 5 || strlen($data) < 5 + unpack('n', substr($data, 3, 2))[1]) {
        $r = [$conn]; $w = $e = null;
        if (microtime(true) > $deadline || !stream_select($r, $w, $e, 0, 200000)) {
            if (microtime(true) > $deadline) {
                break;
            }
            continue;
        }
        $chunk = stream_socket_recvfrom($conn, 16384, STREAM_PEEK);
        if ($chunk === false || $chunk === '' || strlen($chunk) === strlen($data)) {
            if ($chunk === '' || $chunk === false) {
                break;
            }
        }
        $data = $chunk;
        if (strlen($data) >= 16384) {
            break;
        }
    }
    if (strlen($data) < 43 || $data[0] !== "\x16") {
        return '';
    }
    $p = 5 + 4 + 2 + 32;                         // nagłówek rekordu, handshake, wersja, random
    $p += 1 + ord($data[$p] ?? "\0");            // session id
    $p += 2 + (unpack('n', substr($data, $p, 2))[1] ?? 0);   // cipher suites
    $p += 1 + ord($data[$p] ?? "\0");            // compression
    $end = $p + 2 + (unpack('n', substr($data, $p, 2))[1] ?? 0);
    $p += 2;
    while ($p + 4 <= $end && $p + 4 <= strlen($data)) {
        $type = unpack('n', substr($data, $p, 2))[1];
        $len = unpack('n', substr($data, $p + 2, 2))[1];
        if ($type === 0 && $len > 5) {
            $nameLen = unpack('n', substr($data, $p + 7, 2))[1];
            return strtolower(substr($data, $p + 9, $nameLen));
        }
        $p += 4 + $len;
    }
    return '';
}

// ---------------------------------------------------------------- protokoły

/** Bufor odczytu: to, co przyszło za znacznikiem, zostaje na następne wywołanie (SMTP pipelining, keep-alive). */
$GLOBALS['pending'] = '';

function readUntil($conn, string $marker, int $max, float $timeout = 3.0): string
{
    $buf = $GLOBALS['pending'];
    $deadline = microtime(true) + $timeout;
    while (!str_contains($buf, $marker) && strlen($buf) < $max && microtime(true) < $deadline) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || ($chunk === '' && feof($conn))) {
            break;
        }
        if ($chunk === '') {
            usleep(5000);
            continue;
        }
        $buf .= $chunk;
    }
    $pos = strpos($buf, $marker);
    if ($pos === false) {
        $GLOBALS['pending'] = '';
        return $buf;
    }
    $GLOBALS['pending'] = (string) substr($buf, $pos + strlen($marker));
    return substr($buf, 0, $pos + strlen($marker));
}

function readExact($conn, int $len, float $timeout = 3.0): string
{
    $buf = $GLOBALS['pending'];
    $deadline = microtime(true) + $timeout;
    while (strlen($buf) < $len && microtime(true) < $deadline) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || ($chunk === '' && feof($conn))) {
            break;
        }
        if ($chunk === '') {
            usleep(5000);
        }
        $buf .= $chunk;
    }
    $GLOBALS['pending'] = (string) substr($buf, $len);
    return substr($buf, 0, $len);
}

function handleHttp($conn, array $base): void
{
    for ($n = 0; $n < 20; $n++) {               // keep-alive: kilka żądań na jednym połączeniu
        // Kolejne żądanie na tym samym połączeniu czekamy krótko: sink obsługuje połączenia po kolei.
        $head = readUntil($conn, "\r\n\r\n", 65536, $n === 0 ? 3.0 : 0.5);
        if ($head === '') {
            return;
        }
        [$headers, $rest] = explode("\r\n\r\n", $head, 2) + [1 => ''];
        $rest = '';
        $lines = explode("\r\n", $headers);
        $request = array_shift($lines);
        $hdr = [];
        foreach ($lines as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $hdr[strtolower(trim($k))] = trim($v);
            }
        }
        $body = $rest;
        if (isset($hdr['content-length'])) {
            $body = readExact($conn, min((int) $hdr['content-length'], MAX_BODY));
        } elseif (str_contains(strtolower($hdr['transfer-encoding'] ?? ''), 'chunked')) {
            $body = readUntil($conn, "0\r\n\r\n", MAX_BODY);
            $plain = '';
            $p = 0;
            while (($nl = strpos($body, "\r\n", $p)) !== false) {
                $size = hexdec(trim(substr($body, $p, $nl - $p)));
                if ($size <= 0) {
                    break;
                }
                $plain .= substr($body, $nl + 2, (int) $size);
                $p = $nl + 2 + (int) $size + 2;
            }
            $body = $plain;
        }
        if (str_contains(strtolower($hdr['content-encoding'] ?? ''), 'gzip')) {
            $body = @gzdecode($body) ?: $body;
        }
        [$method, $target] = explode(' ', $request . '  ', 3);
        record($base + ['proto' => $base['proto'] ?? 'http', 'host' => $hdr['host'] ?? ($base['sni'] ?? ''), 'method' => $method,
            'path' => $target, 'headers' => $hdr, 'body_b64' => base64_encode(substr($body, 0, MAX_BODY))]);
        $resp = '{}';
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($resp) . "\r\n"
            . (strtolower($hdr['connection'] ?? '') === 'close' ? "Connection: close\r\n" : '') . "\r\n" . $resp);
        if (strtolower($hdr['connection'] ?? '') === 'close' || str_starts_with(strtoupper($request), 'HTTP/1.0')) {
            return;
        }
    }
}

function handleSmtp($conn, array $base): void
{
    fwrite($conn, "220 sandbox ESMTP\r\n");
    $mail = ['from' => '', 'to' => [], 'data' => ''];
    for ($n = 0; $n < 100; $n++) {
        $line = readUntil($conn, "\r\n", 4096, 5);
        if ($line === '') {
            break;
        }
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($conn, "250-sandbox\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n");
        } elseif ($cmd === 'AUTH') {
            $mail['auth'] = trim(substr(trim($line), 5));
            if (str_starts_with(strtoupper($mail['auth']), 'LOGIN')) {
                fwrite($conn, "334 VXNlcm5hbWU6\r\n");
                $mail['auth'] .= ' ' . trim(readUntil($conn, "\r\n", 4096));
                fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
                $mail['auth'] .= ' ' . trim(readUntil($conn, "\r\n", 4096));
            }
            fwrite($conn, "235 OK\r\n");
        } elseif ($cmd === 'MAIL') {
            $mail['from'] = trim(substr(trim($line), 10));
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'RCPT') {
            $mail['to'][] = trim(substr(trim($line), 8));
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'DATA') {
            fwrite($conn, "354 dalej\r\n");
            $mail['data'] = substr(readUntil($conn, "\r\n.\r\n", MAX_BODY, 5), 0, MAX_BODY);
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'QUIT') {
            fwrite($conn, "221 do widzenia\r\n");
            break;
        } elseif ($cmd === 'STAR') {
            fwrite($conn, "454 TLS niedostępny\r\n");
        } else {
            fwrite($conn, "250 OK\r\n");
        }
    }
    record($base + ['proto' => $base['proto'] ?? 'smtp', 'mail_from' => $mail['from'], 'mail_to' => $mail['to'],
        'auth_b64' => base64_encode($mail['auth'] ?? ''), 'body_b64' => base64_encode($mail['data'])]);
}

function handleTcp($conn, int $port, string $peer, string $caDir): void
{
    $GLOBALS['pending'] = '';
    stream_set_blocking($conn, false);
    $base = ['port' => $port, 'peer' => $peer];
    if (in_array($port, TLS_HTTP_PORTS, true) || in_array($port, TLS_SMTP_PORTS, true)) {
        $sni = peekSni($conn);
        $base['sni'] = $sni;
        stream_set_blocking($conn, true);
        stream_set_timeout($conn, 5);
        stream_context_set_option($conn, 'ssl', 'local_cert', leafFor($sni !== '' ? $sni : $GLOBALS['ip'], $caDir));
        stream_context_set_option($conn, 'ssl', 'verify_peer', false);
        $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($ok !== true) {
            // Klient nie ufa CA sandboxu (własna lista CA, przypięty certyfikat) - zapisujemy chociaż nazwę.
            record($base + ['proto' => 'tls', 'tls_failed' => true]);
            return;
        }
        stream_set_blocking($conn, false);
        $base['proto'] = in_array($port, TLS_HTTP_PORTS, true) ? 'https' : 'smtps';
        in_array($port, TLS_HTTP_PORTS, true) ? handleHttp($conn, $base) : handleSmtp($conn, $base);
        return;
    }
    if (in_array($port, HTTP_PORTS, true)) {
        handleHttp($conn, $base);
    } elseif (in_array($port, SMTP_PORTS, true)) {
        handleSmtp($conn, $base);
    } else {
        $data = readUntil($conn, "\0\0never\0\0", 65536, 2);
        record($base + ['proto' => 'tcp', 'body_b64' => base64_encode($data)]);
    }
}

// ---------------------------------------------------------------- DNS

function dnsAnswer(string $query, string $ip): ?array
{
    if (strlen($query) < 17) {
        return null;
    }
    $id = substr($query, 0, 2);
    $p = 12;
    $labels = [];
    while ($p < strlen($query) && ($len = ord($query[$p])) > 0 && $len < 64) {
        $labels[] = substr($query, $p + 1, $len);
        $p += 1 + $len;
    }
    $qEnd = $p + 5;
    $qtype = unpack('n', substr($query, $p + 1, 2))[1] ?? 0;
    $question = substr($query, 12, $qEnd - 12);
    $name = strtolower(implode('.', $labels));
    $flags = "\x81\x80";                                     // odpowiedź, rekurencja dostępna, NOERROR
    if ($qtype === 1) {
        $answer = "\xc0\x0c" . pack('nnNn', 1, 1, 60, 4) . inet_pton($ip);
        return [$name, $qtype, $id . $flags . pack('nnnn', 1, 1, 0, 0) . $question . $answer];
    }
    return [$name, $qtype, $id . $flags . pack('nnnn', 1, 0, 0, 0) . $question];   // AAAA i inne: brak rekordu, klient użyje IPv4
}

// ---------------------------------------------------------------- pętla

caFiles("$out/ca");
$servers = [];
$udp = stream_socket_server("udp://0.0.0.0:53", $errno, $errstr, STREAM_SERVER_BIND);
if ($udp === false) {
    fwrite(STDERR, "DNS: $errstr\n");
    exit(1);
}
$servers[] = $udp;
$ports = [];
foreach (array_merge(HTTP_PORTS, TLS_HTTP_PORTS, SMTP_PORTS, TLS_SMTP_PORTS, RAW_PORTS) as $port) {
    $s = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        stream_context_create(['ssl' => ['allow_self_signed' => true, 'verify_peer' => false]]));
    if ($s !== false) {
        $servers[] = $s;
        $ports[(int) $s] = $port;
    }
}
file_put_contents("$out/sink.ready", (string) count($ports));
fwrite(STDOUT, "sink: DNS + " . count($ports) . " portów na $ip\n");

while (true) {
    $r = $servers;
    $w = $e = null;
    if (@stream_select($r, $w, $e, 5) < 1) {
        continue;
    }
    foreach ($r as $s) {
        if ($s === $udp) {
            $pkt = stream_socket_recvfrom($udp, 1500, 0, $from);
            $ans = $pkt === false ? null : dnsAnswer($pkt, $ip);
            if ($ans !== null) {
                [$name, $qtype, $resp] = $ans;
                stream_socket_sendto($udp, $resp, 0, $from);
                if ($qtype === 1) {
                    record(['proto' => 'dns', 'name' => $name, 'peer' => $from]);
                }
            }
            continue;
        }
        $conn = @stream_socket_accept($s, 1, $peer);
        if ($conn === false) {
            continue;
        }
        try {
            handleTcp($conn, $ports[(int) $s], (string) $peer, "$out/ca");
        } catch (\Throwable $ex) {
            record(['proto' => 'error', 'port' => $ports[(int) $s], 'message' => $ex->getMessage()]);
        } finally {
            @fclose($conn);
        }
    }
}
