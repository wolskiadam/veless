<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Wersja systemu w stopce panelu: „Veless v1.0.<build> | <data wdrożenia>".
 *
 * Główny sposób: wdrożenie z GitHub Actions (deploy.yml) zapisuje config/build.json
 * z identyfikatorem wdrożenia i godziną. Nowy identyfikator = build + 1, od razu.
 * Poniższy opis (odcisk plików) to tryb zapasowy, gdy build.json nie istnieje.
 *
 * Numer główny (VERSION) zmieniamy ręcznie przy dużych zmianach. Numer kompilacji rośnie
 * sam: system co kilka minut liczy „odcisk" plików kodu (ścieżki, rozmiary, daty zmian).
 * Gdy po wgraniu plików przez FTP odcisk się zmieni, build rośnie o 1, a datą wdrożenia
 * staje się czas najnowszego wgranego pliku (po polsku).
 *
 * Wgrywanie wielu plików trwa chwilę - nowy build liczymy dopiero, gdy od ostatniej zmiany
 * pliku minęły 2 minuty, żeby jedno wdrożenie nie podbiło numeru kilka razy.
 * Stan trzymamy w storage/version.json (nie w bazie).
 */
final class AppVersion
{
    public const NAME    = 'Veless';
    public const VERSION = '1.0';

    private const CHECK_EVERY = 300;   // co ile sekund sprawdzać pliki
    private const SETTLE      = 120;   // ile sekund „ciszy" po ostatnim pliku przed nowym buildem
    private const DIRS = ['src', 'public', 'integrations', 'extensions', 'config', 'lang', 'cli'];

    /** @return array{name:string,version:string,build:int,date:string,label:string,commit:string} */
    public static function current(): array
    {
        $file  = PASE_ROOT . '/storage/version.json';
        $state = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];

        // Znacznik wdrożenia z GitHub Actions (config/build.json): każde wdrożenie = +1 do buildu
        // od razu po wgraniu, z dokładną godziną. Gdy go brak (np. kopia lokalna) - stary sposób po plikach.
        $deploy = self::deployStamp();
        if ($deploy !== null) {
            if (($state['deploy_id'] ?? null) !== $deploy['id']) {
                $state['build']       = (int) ($state['build'] ?? 0) + 1;
                $state['deploy_id']   = $deploy['id'];
                $state['deployed_at'] = $deploy['deployed_at'];
                $state['commit']      = $deploy['commit'];
                if (is_dir(dirname($file)) || @mkdir(dirname($file), 0700, true)) {
                    @file_put_contents($file, json_encode($state), LOCK_EX);
                }
            }
        } elseif (($state['checked_at'] ?? 0) < time() - self::CHECK_EVERY) {
            $state = self::refresh($state);
            if (is_dir(dirname($file)) || @mkdir(dirname($file), 0700, true)) {
                @file_put_contents($file, json_encode($state), LOCK_EX);
            }
        }

        $build = (int) ($state['build'] ?? 1);
        $date  = '';
        if (!empty($state['deployed_at'])) {
            $date = (new \DateTimeImmutable('@' . (int) $state['deployed_at']))
                ->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
        }
        $ver = self::VERSION . '.' . $build;
        return [
            'name' => self::NAME, 'version' => $ver, 'build' => $build, 'date' => $date,
            'label' => self::NAME . ' v' . $ver . ($date !== '' ? '  |  ' . $date : ''),
            'commit' => (string) ($state['commit'] ?? ''),
        ];
    }

    /** @return array{id:string,deployed_at:int,commit:string}|null */
    private static function deployStamp(): ?array
    {
        $path = PASE_ROOT . '/config/build.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !is_string($data['id'] ?? null) || $data['id'] === '' || !is_int($data['deployed_at'] ?? null)) {
            return null;
        }
        return ['id' => $data['id'], 'deployed_at' => $data['deployed_at'], 'commit' => (string) ($data['commit'] ?? '')];
    }

    /** @param array<string,mixed> $state */
    private static function refresh(array $state): array
    {
        [$hash, $newest] = self::fingerprint();
        $state['checked_at'] = time();
        if (!isset($state['hash'])) {
            // Pierwsze uruchomienie - zaczynamy od buildu 1 z datą najnowszego pliku.
            return $state + ['hash' => $hash, 'build' => 1, 'deployed_at' => $newest];
        }
        if ($hash !== $state['hash'] && $newest < time() - self::SETTLE) {
            $state['hash'] = $hash;
            $state['build'] = (int) ($state['build'] ?? 1) + 1;
            $state['deployed_at'] = $newest;
        } elseif ($hash !== $state['hash']) {
            // Wgrywanie wciąż trwa - sprawdzimy ponownie przy następnym wejściu.
            $state['checked_at'] = 0;
        }
        return $state;
    }

    /** @return array{0:string,1:int} skrót stanu plików i czas najnowszej zmiany */
    private static function fingerprint(): array
    {
        $parts = [];
        $newest = 0;
        foreach (self::DIRS as $dir) {
            $path = PASE_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    /** @var \SplFileInfo $f */
                    if (!$f->isFile() || !in_array(strtolower($f->getExtension()), ['php', 'js', 'css', 'json'], true)) {
                        continue;
                    }
                    $m = $f->getMTime();
                    $newest = max($newest, $m);
                    $parts[] = substr($f->getPathname(), strlen(PASE_ROOT)) . ':' . $f->getSize() . ':' . $m;
                }
            } catch (\Throwable) {
                // katalog bez uprawnień - pomijamy
            }
        }
        sort($parts);
        return [hash('sha256', implode("\n", $parts)), $newest ?: time()];
    }
}
