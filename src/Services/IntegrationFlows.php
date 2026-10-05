<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Co dana integracja pobiera i wysyła (lista Integracji, jak w Base): „etykiety" przepływów
 * z informacją, czy są włączone i jak często działają.
 *
 * Włączenie wynika z trzech źródeł:
 *  - interwałów harmonogramu (Konfiguracja → Synchronizacja, SYNC_*_EVERY),
 *  - ustawień samej integracji (np. sync_status, stock_master w WooCommerce),
 *  - aktywnych automatyzacji (np. „Wystaw fakturę", „Wyślij SMS", „Przekaż zamówienie").
 * Wyłączony przepływ jest pokazany na szaro z podpowiedzią, gdzie go włączyć.
 */
final class IntegrationFlows
{
    /** @var array<string,string> */
    private array $s;
    /** @var array<string,array<int,array<string,mixed>>> typ akcji => parametry aktywnych reguł */
    private array $actions = [];

    public function __construct(private readonly PDO $pdo)
    {
        $this->s = (new SettingsRepository($pdo))->all();
        try {
            $rows = $pdo->query('SELECT actions, action_type, action_params FROM automation_rules WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $list = json_decode((string) ($r['actions'] ?? ''), true);
            if (!is_array($list) || $list === []) {
                $list = $r['action_type'] ? [['type' => $r['action_type'], 'params' => json_decode((string) ($r['action_params'] ?? ''), true) ?: []]] : [];
            }
            foreach ($list as $a) {
                if (is_array($a) && !empty($a['type'])) {
                    $this->actions[(string) $a['type']][] = is_array($a['params'] ?? null) ? $a['params'] : [];
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $acc wiersz integration_accounts (config zdekodowany)
     * @return array{in:array<int,array{label:string,on:bool,hint:string,link:string}>,out:array<int,array{label:string,on:bool,hint:string,link:string}>}
     */
    public function forAccount(array $acc): array
    {
        $cfg = is_array($acc['config'] ?? null) ? $acc['config'] : [];
        $id  = (int) $acc['id'];
        $edit = 'integration_edit.php?id=' . $id;
        $in = $out = [];

        switch ($acc['type']) {
            case 'woocommerce':
                $in[]  = $this->every('Zamówienia', 'SYNC_ORDERS_EVERY', \Pase\Support\AppMode::isLocal()
                    ? 'Tryb lokalny: CRM sam sprawdza w sklepie nowe i zmienione zamówienia (bez webhooka).'
                    : 'Nowe zamówienia przychodzą od razu (webhook), a harmonogram dociąga pominięte.', true);
                $in[]  = $this->every('Produkty', 'SYNC_PRODUCTS_EVERY', 'Import katalogu produktów ze sklepu.');
                $out[] = $this->flag('Statusy', !empty($cfg['sync_status']), 'Zmiana statusu zamówienia w CRM trafia do sklepu.', $edit);
                $stockOn = ($cfg['stock_master'] ?? 'pase') === 'pase';
                $out[] = $stockOn ? $this->every('Stany', 'SYNC_STOCK_EVERY', 'CRM wysyła stany do sklepu.')
                                  : $this->flag('Stany', false, 'Źródłem stanów jest sklep — CRM ich nie wysyła.', $edit);
                $out[] = $this->every('Ceny', 'SYNC_PRICE_EVERY', 'CRM wysyła ceny do sklepu.');
                $fwd = count(array_filter($this->actions['forward_order'] ?? [], static fn($p) => (int) ($p['integration'] ?? 0) === $id));
                $out[] = $this->flag('Zamówienia', $fwd > 0, $fwd > 0 ? 'Automatyzacje przekazują tu zamówienia z innych kanałów.' : 'Włącz akcją „Przekaż zamówienie” w Automatyzacjach.', 'automations.php', $fwd > 0 ? 'automat.' : '');
                break;

            case 'allegro':
                $in[]  = $this->every('Zamówienia', 'SYNC_ORDERS_EVERY', 'Pobieranie nowych zamówień z Allegro.');
                $in[]  = $this->flag('Wiadomości', true, 'Wiadomości od kupujących (Allegro → Wiadomości, dzwoneczek).', 'allegro_messages.php');
                $in[]  = $this->flag('Oferty', true, 'Lista ofert w Zarządzaniu ofertami.', 'allegro_offers.php');
                $in[]  = $this->every('Statusy paczek', 'SYNC_TRACKING_EVERY', 'Etap przesyłek u przewoźników przez API Allegro.');
                $out[] = $this->flag('Oferty', true, 'Wystawianie i operacje na ofertach.', 'offer_allegro.php');
                $out[] = $this->flag('Stany', true, 'Zmiana stanu produktu aktualizuje powiązane oferty na bieżąco.', 'allegro_links.php', 'na bieżąco');
                $out[] = $this->flag('Statusy', !empty($cfg['sync_status']) || ($this->s['WOO_SYNC_STATUS'] ?? '0') === '1', 'Zmiana statusu zamówienia w CRM ustawia status realizacji na Allegro.', $edit);
                $sched = json_decode((string) ($this->s[AllegroHandlingSchedule::CONFIG_KEY] ?? ''), true);
                $out[] = $this->flag('Czas wysyłki', !empty($sched['enabled']), 'Automatyczna zmiana czasu wysyłki wg harmonogramu.', 'allegro_handling_schedule.php', !empty($sched['enabled']) ? 'harmonogram' : '');
                break;

            case 'allegro_wysylka':
                $in[]  = $this->flag('Etykiety', true, 'Pobieranie etykiet po nadaniu.', $edit);
                $in[]  = $this->flag('Protokoły', true, 'Protokół przekazania paczek.', $edit);
                $in[]  = $this->every('Statusy paczek', 'SYNC_TRACKING_EVERY', 'Etap przesyłek u przewoźnika.');
                $out[] = $this->flag('Nadawanie', true, 'Nadawanie paczek z zamówienia.', $edit);
                $out[] = $this->flag('Anulowanie', true, 'Anulowanie nadanej paczki.', $edit);
                $out[] = $this->flag('Zam. kuriera', ($cfg['handover'] ?? 'point') === 'courier' || str_contains((string) ($cfg['handover'] ?? ''), 'COURIER'), 'Zamawianie odbioru przez kuriera (domyślny sposób nadania).', $edit);
                break;

            case 'blpaczka':
                $in[]  = $this->flag('Etykiety', true, 'Pobieranie etykiet po nadaniu.', $edit);
                $in[]  = $this->every('Statusy paczek', 'SYNC_TRACKING_EVERY', 'Etap przesyłek u przewoźnika.');
                $out[] = $this->flag('Nadawanie', true, 'Nadawanie paczek z zamówienia.', $edit);
                $out[] = $this->flag('Zam. kuriera', ($cfg['pickup_mode'] ?? 'courier') === 'courier', 'Domyślnie z odbiorem przez kuriera.', $edit);
                break;

            case 'wfirma':
                $in[]  = $this->flag('Wydruk faktur', true, 'Pobieranie PDF dokumentów do druku i wysyłki.', $edit);
                $inv = count($this->actions['issue_invoice'] ?? []);
                $rec = count($this->actions['create_receipt'] ?? []);
                $out[] = $this->flag('Faktury', true, $inv ? 'Wystawiane ręcznie i przez automatyzacje.' : 'Wystawiane ręcznie z zamówienia.', 'automations.php', $inv ? 'automat.' : '');
                $out[] = $this->flag('Paragony', true, $rec ? 'Wystawiane ręcznie i przez automatyzacje.' : 'Wystawiane ręcznie z zamówienia.', 'automations.php', $rec ? 'automat.' : '');
                break;

            case 'smsapi':
                $n = count($this->actions['send_sms'] ?? []);
                $out[] = $this->flag('SMS', !empty($cfg['token']), empty($cfg['token']) ? 'Brak tokenu — uzupełnij w ustawieniach.' : ($n ? 'Wysyłane przez automatyzacje.' : 'Dodaj akcję „Wyślij SMS” w Automatyzacjach.'), $n ? 'automations.php' : $edit, $n ? 'automat.' : '');
                break;

            case 'gs1':
                $ok = !empty($cfg['login']) && !empty($cfg['password']);
                $in[]  = $this->flag('Karty GS1', $ok, $ok ? 'Lista kart z MojeGS1 odświeża się co 12 h przy wejściu na stronę GS1.' : 'Brak loginu lub hasła API — uzupełnij w ustawieniach.', $ok ? 'gs1.php' : $edit);
                $out[] = $this->flag('Nowe GTIN', $ok, 'Tylko po kliknięciu „Nadaj GTIN z GS1” na karcie produktu.', 'gs1.php');
                break;

            case 'payu':
                $ok = !empty($cfg['client_id']) && !empty($cfg['client_secret']) && !empty($cfg['shop_id']);
                $in[]  = $this->flag('Saldo', $ok, $ok ? 'Odczyt przy wejściu na stronę PayU.' : 'Brak kluczy OAuth lub Id sklepu — uzupełnij w ustawieniach.', $ok ? 'payu.php' : $edit);
                $out[] = $this->flag('Wypłaty', $ok, 'Tylko po potwierdzeniu na stronie PayU, na konto zapisane w PayU.', 'payu.php');
                break;

            case 'tiktokshop':
                $ok = !empty($acc['access_token']);
                $in[]  = $this->flag('Zamówienia', $ok, $ok ? 'Pobierane co ' . \Pase\Services\TiktokShop::RUN_EVERY_MIN . ' min, opłacone zdejmują stan w CRM.' : 'Konto niepołączone — kliknij „Połącz z TikTok Shop” w ustawieniach.', $edit);
                $out[] = $this->flag('Stany', $ok && ($cfg['push_stock'] ?? 'on') !== 'off', 'Stan CRM do produktów TikTok o tym samym SKU, gdy się zmieni.', $edit);
                $out[] = $this->flag('Numery przesyłek', $ok && ($cfg['push_tracking'] ?? 'on') !== 'off', 'Numer przesyłki z CRM oznacza zamówienie w TikTok jako wysłane.', $edit);
                break;

            default:
                // Wtyczki bez własnego opisu: przepływy z deklarowanych zdolności.
                $caps = [];
                foreach (\Pase\Plugin\PluginRegistry::manifests() as $m) {
                    if ($m->type === $acc['type']) { $caps = $m->capabilities; }
                }
                if (in_array(\Pase\Plugin\Capability::ORDER_SOURCE, $caps, true)) { $in[] = $this->every('Zamówienia', 'SYNC_ORDERS_EVERY', 'Pobieranie zamówień.'); }
                if (in_array(\Pase\Plugin\Capability::WAREHOUSE, $caps, true)) {
                    $out[] = $this->every('Stany', 'SYNC_STOCK_EVERY', 'Wysyłka stanów.');
                    $out[] = $this->every('Ceny', 'SYNC_PRICE_EVERY', 'Wysyłka cen.');
                }
                if (in_array(\Pase\Plugin\Capability::COURIER, $caps, true)) { $out[] = $this->flag('Nadawanie', true, 'Nadawanie paczek.', $edit); }
                if (in_array(\Pase\Plugin\Capability::INVOICING, $caps, true)) { $out[] = $this->flag('Faktury', true, 'Dokumenty sprzedaży.', $edit); }
                if (in_array(\Pase\Plugin\Capability::SMS, $caps, true)) { $out[] = $this->flag('SMS', true, 'Wysyłka SMS.', $edit); }
        }

        if (empty($acc['is_active'])) {
            // Wyłączona integracja nic nie robi - wszystko na szaro.
            foreach ([&$in, &$out] as &$list) {
                foreach ($list as &$f) { $f['on'] = false; $f['hint'] = 'Integracja wyłączona. ' . $f['hint']; }
            }
            unset($list, $f);
        }
        return ['in' => $in, 'out' => $out];
    }

    /** Przepływ z harmonogramu: włączony, gdy interwał > 0; w etykiecie interwał. */
    private function every(string $label, string $key, string $hint, bool $webhook = false): array
    {
        $min = (int) ($this->s[$key] ?? (Scheduler::DEFAULT_EVERY[$key] ?? '0'));
        return [
            'label' => $label . ($min > 0 ? ' (' . self::interval($min) . ')' : ''),
            'on'    => $min > 0 || $webhook,
            'hint'  => $hint . ($min > 0 ? ' Co ' . self::interval($min) . '.' : ' Harmonogram wyłączony — ustaw interwał w Synchronizacji.'),
            'link'  => 'sync_settings.php',
            'push'  => $webhook,
        ];
    }

    private function flag(string $label, bool $on, string $hint, string $link, string $note = ''): array
    {
        return ['label' => $label . ($note !== '' ? " ({$note})" : ''), 'on' => $on, 'hint' => $hint, 'link' => $link, 'push' => false];
    }

    public static function interval(int $min): string
    {
        if ($min % 1440 === 0) { return ($min / 1440) . 'd'; }
        if ($min % 60 === 0) { return ($min / 60) . 'h'; }
        return $min . 'm';
    }
}
