<?php
declare(strict_types=1);

namespace PaseExt\Kontrola;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use Pase\Repository\SettingsRepository;
use PDO;

/**
 * Kontrola stanu: wewnętrzny audyt, który sam sprawdza, czy CRM działa poprawnie.
 *
 *  - System → Kontrola stanu: zielone / żółte / czerwone światło przy każdym sprawdzeniu, z listą zamówień
 *    albo produktów, których dotyczy, i przyciskiem „Napraw” tam, gdzie się da,
 *  - w tle co godzinę (ustawienie „every”), porcjami po kilka sprawdzeń na minutę,
 *  - mail, gdy coś zaświeci na czerwono (i gdy problem ustąpi).
 *
 * Sprawdzenia: Checks. Naprawy: Fixes. Inne rozszerzenia dokładają swoje sprawdzenia filtrem 'health.checks'.
 * Opis: README.md w tym katalogu.
 */
final class KontrolaExtension extends AbstractExtension
{
    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'kontrola',
            name: 'Kontrola stanu',
            version: '1.0.0',
            author: 'Veless',
            description: 'Sama sprawdza, czy wszystko działa: połączenia z Allegro, sklepami, kurierami i księgowością, cron i kolejkę zadań, zgodność stanów z Allegro i sklepem, zamówienia bez produktu, wysłane bez numeru przesyłki, opłacone bez paragonu i faktury oraz paczki bez statusu. Zielone, żółte i czerwone światło w System → Kontrola stanu i mail przy problemie.',
            icon: '🩺',
            fields: [
                ['key' => 'every', 'label' => 'Sprawdzaj w tle', 'type' => 'select', 'default' => '60',
                 'options' => ['60' => 'Co godzinę', '30' => 'Co 30 minut', '180' => 'Co 3 godziny', '360' => 'Co 6 godzin', '0' => 'Tylko ręcznie (przycisk „Sprawdź teraz”)']],
                ['key' => 'alerts', 'label' => 'Mail przy czerwonym świetle', 'type' => 'select', 'default' => '1',
                 'options' => ['1' => 'Wysyłaj (i drugi, gdy problem ustąpi)', '0' => 'Nie wysyłaj']],
                ['key' => 'email', 'label' => 'Adres do alarmów', 'type' => 'text',
                 'help' => 'Puste = adres alarmów z Konfiguracja → E-mail. Wysyła przez skrzynkę ustawioną w CRM.'],
                ['key' => 'stale_days', 'label' => 'Paczka bez statusu od przewoźnika - alarm po', 'type' => 'select', 'default' => '2',
                 'options' => ['1' => '1 dniu', '2' => '2 dniach', '3' => '3 dniach', '5' => '5 dniach']],
                ['key' => 'doc_days', 'label' => 'Opłacone bez paragonu i faktury - alarm po', 'type' => 'select', 'default' => '2',
                 'options' => ['1' => '1 dniu', '2' => '2 dniach', '3' => '3 dniach', '7' => '7 dniach']],
                ['key' => 'days', 'label' => 'Sprawdzaj zamówienia z ostatnich', 'type' => 'select', 'default' => '30',
                 'options' => ['14' => '14 dni', '30' => '30 dni', '60' => '60 dni']],
                ['key' => 'open', 'label' => 'Kontrola stanu', 'type' => 'link', 'url' => Hooks::pageUrl('kontrola.stan')],
            ],
            connects: 'Allegro, sklepy i kurierzy - tylko odczyt (test połączenia, stany ofert i produktów)',
        );
    }

    /** @return array<string,mixed> ustawienia z wartościami domyślnymi */
    private function cfg(): array
    {
        $cfg = [];
        foreach ($this->manifest()->fields as $f) {
            if (isset($f['default'])) {
                $cfg[$f['key']] = (string) $this->setting($f['key'], $f['default']);
            }
        }
        $cfg['email'] = (string) $this->setting('email', '');
        return $cfg;
    }

    private function runner(PDO $pdo): Runner
    {
        $cfg = $this->cfg();
        $mail = static function (string $to, string $subject, string $html) use ($pdo): bool {
            $mailer = new \Pase\Services\Mailer((new \Pase\Services\MailAccounts($pdo))->mailerConfig(null));
            return (bool) ($mailer->send($to, $subject, $html, ['type' => 'ops_alert'])[0] ?? false);
        };
        return new Runner(new Checks($pdo, $cfg), new Store($pdo), new SettingsRepository($pdo), $cfg, $mail);
    }

    public function boot(): void
    {
        Hooks::addPage('stan', ['title' => 'Kontrola stanu', 'desc' => 'Czy wszystko w CRM działa poprawnie', 'group' => 'system', 'role' => 'viewer',
            'render' => fn(PDO $pdo, array $ctx): string => Page::render($this->runner($pdo), $ctx, $this->cfg())]);

        Hooks::addPage('sprawdz', ['title' => 'Kontrola stanu', 'menu' => false, 'role' => 'editor',
            'render' => function (PDO $pdo, array $ctx): void {
                if ($ctx['post']) {
                    @set_time_limit(300);
                    $this->runner($pdo)->runAll(time(), (string) $ctx['user']);
                    flash('Sprawdzono wszystko.');
                }
                header('Location: ' . Hooks::pageUrl('kontrola.stan'));
                exit;
            }]);

        Hooks::addPage('napraw', ['title' => 'Kontrola stanu', 'menu' => false, 'role' => 'editor',
            'render' => function (PDO $pdo, array $ctx): void {
                if ($ctx['post']) {
                    @set_time_limit(180);
                    try {
                        flash((new Fixes($pdo))->run((string) ($_POST['fix'] ?? ''), time()));
                        // Sprawdzenie, którego dotyczyła naprawa, od razu od nowa (bez tych z połączeniem z zewnątrz).
                        $checks = new Checks($pdo, $this->cfg());
                        $defs = $checks->definitions();
                        $id = (string) ($_POST['check'] ?? '');
                        if (isset($defs[$id]) && empty($defs[$id]['network'])) {
                            (new Store($pdo))->save($checks->run($id, $defs[$id]), time());
                        }
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                header('Location: ' . Hooks::pageUrl('kontrola.stan') . '#' . rawurlencode((string) ($_POST['check'] ?? '')));
                exit;
            }]);

        // Cron co minutę; Runner sam pilnuje interwału i robi porcje po ~25 s.
        Hooks::addAction('cron.tick', function (int $now): void {
            $msg = $this->runner($this->pdo())->tick($now);
            if ($msg !== null) {
                \Pase\Support\Logger::info($msg);
            }
        });
    }
}
