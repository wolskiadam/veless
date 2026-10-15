<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use Pase\Repository\SettingsRepository;
use PDO;

/**
 * KSeF: połączenie z Krajowym Systemem e-Faktur (API 2.0), bez programu księgowego. W ustawieniach wybiera się, co wtyczka robi:
 *
 * Wystawianie e-faktur (domyślnie włączone):
 *  - karta zamówienia: sekcja „e-Faktura KSeF” (podgląd, wysyłka, status, numer KSeF, XML, UPO, link weryfikacyjny),
 *  - lista zamówień: ikona stanu e-faktury przy statusie,
 *  - automatyzacje: akcja „Wyślij e-fakturę do KSeF”.
 * Faktury sprzedażowe (domyślnie włączone) i zakupowe (domyślnie wyłączone):
 *  - Zamówienia → Faktury sprzedażowe KSeF / Faktury zakupowe KSeF: faktury wystawione / otrzymane przez firmę,
 *    pobierane z KSeF (także wystawione poza tym systemem), miesiącami, z podglądem, PDF i XML.
 * Zamówienia → Faktury KSeF: stan połączenia, test połączenia i lista wystawionych e-faktur.
 *
 * Domyślnie działa na środowisku testowym KSeF. Token KSeF (osobny dla każdego środowiska) wpisuje się w ustawieniach
 * wtyczki; to pole 'secret', więc rdzeń trzyma je zaszyfrowane. Nigdy w kodzie. Opis: README.md w tym katalogu.
 */
final class KsefExtension extends AbstractExtension
{
    private const PER_PAGE = 50;

    /** Strony i teksty list faktur pobieranych z KSeF: zakupowe (firma jest nabywcą) i sprzedażowe (firma jest sprzedawcą). */
    private const KINDS = [
        Purchases::PURCHASE => ['list' => 'zakupy', 'fetch' => 'pobierz', 'one' => 'zakup', 'xml' => 'zakupxml', 'pdf' => 'zakuppdf',
            'title' => '📥 Faktury zakupowe KSeF', 'one_title' => 'Faktura zakupowa', 'plural' => 'Faktury zakupowe', 'adj' => 'zakupowe', 'adj_gen' => 'zakupowej',
            'role' => 'nabywcą', 'party' => 'Sprzedawca', 'party_q' => 'sprzedawca'],
        Purchases::SALE => ['list' => 'sprzedaz', 'fetch' => 'pobierzsp', 'one' => 'sprzedazfv', 'xml' => 'sprzedazxml', 'pdf' => 'sprzedazpdf',
            'title' => '📤 Faktury sprzedażowe KSeF', 'one_title' => 'Faktura sprzedażowa', 'plural' => 'Faktury sprzedażowe', 'adj' => 'sprzedażowe', 'adj_gen' => 'sprzedażowej',
            'role' => 'sprzedawcą', 'party' => 'Nabywca', 'party_q' => 'nabywca'],
    ];

    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'ksef',
            name: 'KSeF - e-faktury',
            version: '1.4.0',
            author: 'Veless',
            description: 'Połączenie z Krajowym Systemem e-Faktur (API 2.0): wystawianie faktur FA(3) z zamówień (numer KSeF, UPO i link weryfikacyjny w karcie zamówienia) oraz podgląd faktur sprzedażowych i zakupowych firmy pobranych z KSeF (z PDF). W ustawieniach wybierz, co ma robić, i wpisz token KSeF.',
            icon: '🏛️',
            fields: [
                ['key' => 'environment', 'label' => 'Środowisko KSeF', 'type' => 'select', 'default' => 'test',
                 'options' => array_map(static fn(array $e): string => $e['label'], KsefApi::ENVIRONMENTS),
                 'help' => 'Zacznij od testowego. Faktury z produkcyjnego mają moc prawną i nie da się ich usunąć, tylko skorygować.'],
                ['key' => 'do_sales', 'label' => 'Wystawianie e-faktur z zamówień (karta zamówienia, lista zamówień, automatyzacje)', 'type' => 'checkbox', 'default' => '1'],
                ['key' => 'do_sales_list', 'label' => 'Faktury sprzedażowe: pobieranie i podgląd faktur wystawionych przez firmę w KSeF (także poza tym systemem)', 'type' => 'checkbox', 'default' => '1',
                 'help' => 'Token KSeF musi mieć uprawnienie do przeglądania faktur. Faktury znajdziesz w Zamówienia → Faktury sprzedażowe KSeF.'],
                ['key' => 'do_purchases', 'label' => 'Faktury zakupowe: pobieranie i podgląd faktur otrzymanych przez firmę', 'type' => 'checkbox', 'default' => '0',
                 'help' => 'Token KSeF musi mieć uprawnienie do przeglądania faktur. Faktury znajdziesz w Zamówienia → Faktury zakupowe KSeF.'],
                ['key' => 'purchases_days', 'label' => 'Faktury sprzedażowe i zakupowe: przy pierwszym pobraniu sięgnij wstecz', 'type' => 'select', 'default' => '30',
                 'options' => ['30' => '30 dni', '90' => '90 dni', '180' => 'pół roku', '365' => 'rok']],
                ['key' => 'nip', 'label' => 'NIP firmy', 'type' => 'text', 'required' => true],
                ['key' => 'token_test', 'label' => 'Token KSeF: środowisko testowe', 'type' => 'password', 'secret' => true,
                 'help' => 'Wygeneruj go w Aplikacji Podatnika KSeF danego środowiska, z uprawnieniem do wystawiania faktur (i do przeglądania - dla faktur zakupowych). Zapisywany zaszyfrowany.'],
                ['key' => 'token_demo', 'label' => 'Token KSeF: środowisko przedprodukcyjne (DEMO)', 'type' => 'password', 'secret' => true],
                ['key' => 'token_prod', 'label' => 'Token KSeF: środowisko produkcyjne', 'type' => 'password', 'secret' => true],
                ['key' => 'seller_name', 'label' => 'Nazwa firmy (na wystawianych fakturach)', 'type' => 'text'],
                ['key' => 'seller_address1', 'label' => 'Adres: ulica i numer', 'type' => 'text'],
                ['key' => 'seller_address2', 'label' => 'Adres: kod pocztowy i miejscowość', 'type' => 'text'],
                ['key' => 'seller_email', 'label' => 'E-mail na fakturze', 'type' => 'text'],
                ['key' => 'seller_phone', 'label' => 'Telefon na fakturze', 'type' => 'text'],
                ['key' => 'place', 'label' => 'Miejsce wystawienia', 'type' => 'text'],
                ['key' => 'number_format', 'label' => 'Wzór numeru faktury', 'type' => 'text', 'default' => 'KSEF/{nr}/{mm}/{rrrr}',
                 'help' => '{nr} kolejny numer, {mm} miesiąc, {rrrr} rok. Z {mm} numeracja zaczyna się od 1 co miesiąc. Numer nie może powtórzyć numeru faktury wystawionej gdzie indziej (np. w wFirma) - KSeF odrzuca duplikaty.'],
                ['key' => 'default_vat', 'label' => 'Stawka VAT, gdy sklep jej nie podał', 'type' => 'select', 'default' => '23',
                 'options' => ['23' => '23%', '8' => '8%', '5' => '5%', '0' => '0%']],
                ['key' => 'vat_mode', 'label' => 'Rozliczenie VAT', 'type' => 'select', 'default' => 'vat',
                 'options' => ['vat' => 'Czynny podatnik VAT', 'zw' => 'Zwolniony z VAT (wszystkie pozycje „zw”)']],
                ['key' => 'exempt_basis', 'label' => 'Podstawa zwolnienia z VAT', 'type' => 'text',
                 'help' => 'Tylko przy zwolnieniu, np. „art. 113 ust. 1 ustawy o VAT”.'],
                ['key' => 'bank_account', 'label' => 'Numer rachunku (na nieopłaconych fakturach)', 'type' => 'text'],
                ['key' => 'footer', 'label' => 'Stopka faktury', 'type' => 'textarea'],
            ]
        );
    }

    public function boot(): void
    {
        $service = function (): KsefService {
            $settings = $this->settings();
            return new KsefService($this->pdo(), $settings, fn(): string => $this->token($settings['environment'] ?? 'test'));
        };
        $cfg = $this->settings();

        // ---- Zawsze: połączenie z KSeF i lista wystawionych e-faktur
        Hooks::addPage('faktury', ['title' => '🏛️ Faktury KSeF', 'group' => 'orders', 'role' => 'viewer', 'menu' => ($cfg['do_sales'] ?? '1') !== '0',
            'render' => fn(PDO $pdo, array $ctx): string => $this->listPage($service(), $ctx)]);

        Hooks::addPage('polaczenie', ['title' => 'Połączenie z KSeF', 'menu' => false, 'role' => 'admin',
            'render' => static function (PDO $pdo, array $ctx) use ($service): void {
                if ($ctx['post']) {
                    try {
                        flash($service()->testConnection());
                    } catch (\Throwable $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                header('Location: ' . Hooks::pageUrl('ksef.faktury'));
                exit;
            }]);

        if (($cfg['do_purchases'] ?? '0') === '1') {
            $this->bootKsefInvoices($service, Purchases::PURCHASE);
        }
        if (($cfg['do_sales_list'] ?? '1') !== '0') {
            $this->bootKsefInvoices($service, Purchases::SALE);
        }
        if (($cfg['do_sales'] ?? '1') !== '0') {
            $this->bootSales($service);
        }
    }

    /** Wystawianie e-faktur: karta zamówienia, ikona na liście, wysyłka, status, pliki, automatyzacja. */
    private function bootSales(\Closure $service): void
    {
        // ---- Karta zamówienia
        Hooks::addFilter('order_view.sections', static function (array $sections) use ($service): array {
            $sections[] = ['id' => 'ksef', 'title' => '🏛️ e-Faktura KSeF', 'col' => 'right',
                'render' => static fn(array $row): string => self::section($service(), (int) $row['woo_order_id'])];
            return $sections;
        });

        // ---- Lista zamówień: ikona stanu e-faktury (jedna kwerenda na stronę listy)
        $listed = [];
        Hooks::addAction('orders.rows', static function (array $rows) use ($service, &$listed): void {
            $s = $service();
            $listed = $s->invoices()->forOrders(array_column($rows, 'woo_order_id'), $s->env());
        });
        Hooks::addFilter('orders.indicators', static function (array $ind, array $row) use (&$listed): array {
            $inv = $listed[(int) $row['woo_order_id']] ?? null;
            if ($inv !== null) {
                [$state, $label] = self::state((string) $inv['status']);
                $ind['x_ksef'] = ['state' => $state, 'literal' => true,
                    'label' => 'e-Faktura ' . $inv['number'] . ': ' . $label . ($inv['ksef_number'] ? ' (' . $inv['ksef_number'] . ')' : ''),
                    'icon' => '<path d="M6 3h9l4 4v14H6z"/><path d="M9 13l2 2 4-4"/>'];
            }
            return $ind;
        });

        // ---- Strony
        Hooks::addPage('wystaw', ['title' => 'e-Faktura KSeF', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($service): string {
                $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
                $s = $service();
                if ($ctx['post']) {
                    try {
                        $row = $s->issue($id, (string) $ctx['user']);
                        [, $label] = self::state((string) $row['status']);
                        flash('e-Faktura ' . $row['number'] . ': ' . $label . '. ' . (string) $row['message'], $row['status'] === 'rejected' ? 'err' : 'ok');
                    } catch (\Throwable $e) {
                        flash($e->getMessage(), 'err');
                    }
                    header('Location: order_view.php?id=' . $id);
                    exit;
                }
                return self::previewPage($s, $id, $ctx);
            }]);

        Hooks::addPage('status', ['title' => 'Status e-faktury', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($service): void {
                $id = (int) ($_POST['id'] ?? 0);
                if ($ctx['post']) {
                    try {
                        $row = $service()->refresh($id);
                        flash('e-Faktura ' . $row['number'] . ': ' . self::state((string) $row['status'])[1] . '. ' . (string) $row['message'],
                            $row['status'] === 'rejected' ? 'err' : 'ok');
                    } catch (\Throwable $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                header('Location: order_view.php?id=' . $id);
                exit;
            }]);

        Hooks::addPage('odswiez', ['title' => 'Odśwież e-faktury', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($service): void {
                if ($ctx['post']) {
                    try {
                        flash('Sprawdzono w KSeF: ' . $service()->refreshOpen() . ' e-faktur.');
                    } catch (\Throwable $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                header('Location: ' . Hooks::pageUrl('ksef.faktury'));
                exit;
            }]);

        Hooks::addPage('plik', ['title' => 'Plik e-faktury', 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function (PDO $pdo) use ($service): string {
                $s = $service();
                $row = $s->invoices()->get((int) ($_GET['id'] ?? 0), $s->env());
                $what = ($_GET['co'] ?? '') === 'upo' ? 'upo' : 'xml';
                if ($row === null || ($row[$what] ?? null) === null) {
                    http_response_code(404);
                    return 'Brak pliku.';
                }
                $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($what === 'upo' ? 'UPO_' . ($row['ksef_number'] ?: $row['number']) : $row['number']));
                header('Content-Type: application/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $name . '.xml"');
                return (string) $row[$what];
            }]);

        // ---- Automatyzacje
        Hooks::addFilter('automation.actions', static function (array $actions) use ($service): array {
            $actions['x_ksef_invoice'] = ['label' => 'Wyślij e-fakturę do KSeF', 'params' => [],
                'hint' => 'Wystawia fakturę FA(3) z zamówienia i wysyła ją do KSeF (rozszerzenie KSeF). Zamówienie z e-fakturą jest pomijane.',
                'run' => static function (int $orderId) use ($service): void {
                    $s = $service();
                    $existing = $s->invoices()->get($orderId, $s->env());
                    if ($existing !== null && in_array($existing['status'], ['accepted', ...Invoices::OPEN], true)) {
                        return;
                    }
                    $row = $s->issue($orderId, 'Automatyzacja');
                    if ($row['status'] === 'rejected') {
                        throw new \RuntimeException((string) $row['message']);
                    }
                }];
            return $actions;
        });
    }

    /** Faktury zakupowe albo sprzedażowe: lista pobrana z KSeF, pobieranie nowych, podgląd, PDF i XML. */
    private function bootKsefInvoices(\Closure $service, string $kind): void
    {
        $k = self::KINDS[$kind];
        Hooks::addPage($k['list'], ['title' => $k['title'], 'group' => 'orders', 'role' => 'viewer',
            'render' => static fn(PDO $pdo, array $ctx): string => self::invoicesPage($service(), $ctx, $kind)]);

        Hooks::addPage($k['fetch'], ['title' => 'Pobierz faktury ' . $k['adj'], 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($service, $kind, $k): void {
                if ($ctx['post']) {
                    $od = trim((string) ($_POST['od'] ?? ''));
                    $do = trim((string) ($_POST['do'] ?? ''));
                    try {
                        $r = $od !== '' || $do !== '' ? $service()->fetchInvoices($kind, $od, $do) : $service()->syncInvoices($kind);
                        $what = $od !== '' || $do !== '' ? ' z okresu ' . $od . ' – ' . $do : '';
                        flash('Pobrano z KSeF' . $what . ': ' . $r['new'] . ' nowych faktur ' . ($kind === Purchases::SALE ? 'sprzedażowych' : 'zakupowych') . ($r['seen'] > $r['new'] ? ' (sprawdzono ' . $r['seen'] . ')' : '') . '.'
                            . (!$r['more'] ? '' : (isset($r['until']) && $r['until'] !== null
                                ? ' To nie wszystko (limit zapytań KSeF) - pobrano do ' . $r['until'] . '; za minutę pobierz resztę okresu.'
                                : ' To nie wszystko - kliknij „Pobierz nowe z KSeF” jeszcze raz za minutę.')));
                    } catch (\Throwable $e) {
                        flash('Nie pobrano faktur ' . ($kind === Purchases::SALE ? 'sprzedażowych' : 'zakupowych') . ': ' . $e->getMessage(), 'err');
                    }
                }
                header('Location: ' . Hooks::pageUrl('ksef.' . $k['list']));
                exit;
            }]);

        Hooks::addPage($k['one'], ['title' => $k['one_title'], 'menu' => false, 'role' => 'viewer',
            'render' => static fn(PDO $pdo, array $ctx): string => self::invoicePage($service(), $kind, (string) ($_GET['k'] ?? ''))]);

        Hooks::addPage($k['xml'], ['title' => 'XML faktury ' . $k['adj_gen'], 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function (PDO $pdo) use ($service, $kind): string {
                $k = (string) ($_GET['k'] ?? '');
                try {
                    $xml = $service()->invoiceXml($kind, $k);
                } catch (\Throwable $e) {
                    http_response_code(404);
                    header('Content-Type: text/plain; charset=utf-8');
                    return $e->getMessage();
                }
                header('Content-Type: application/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9_-]+/', '_', $k) ?: 'faktura') . '.xml"');
                return $xml;
            }]);

        Hooks::addPage($k['pdf'], ['title' => 'PDF faktury ' . $k['adj_gen'], 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function (PDO $pdo) use ($service, $kind): string {
                $k = (string) ($_GET['k'] ?? '');
                $s = $service();
                try {
                    $pdf = InvoicePdf::render($s->invoiceXml($kind, $k), self::invoiceMeta($s, $kind, $k));
                } catch (\Throwable $e) {
                    http_response_code(404);
                    header('Content-Type: text/plain; charset=utf-8');
                    return $e->getMessage();
                }
                $row = $s->ksefInvoices($kind)->get($s->env(), $k) ?? [];
                $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Faktura ' . ($row['invoice_number'] ?? $k)) ?: 'faktura';
                header('Content-Type: application/pdf');
                header('Content-Disposition: ' . (isset($_GET['pobierz']) ? 'attachment' : 'inline') . '; filename="' . $name . '.pdf"');
                return $pdf;
            }]);
    }

    // ------------------------------------------------------------------ ustawienia i token

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $out = [];
        foreach ($this->manifest()->fields as $f) {
            $out[$f['key']] = (string) $this->setting($f['key'], $f['default'] ?? '');
        }
        return $out;
    }

    /** Dawny klucz tokena w tabeli settings (wersja 1.0 wpisywała token na stronie Faktury KSeF); czytany, gdy pole w ustawieniach jest puste. */
    public static function tokenKey(string $env): string
    {
        return 'EXT_KSEF_' . strtoupper(preg_replace('/[^a-z]/', '', $env) ?: 'test') . '_TOKEN';
    }

    /** Token KSeF środowiska: z ustawień wtyczki (pole token_<środowisko>), a gdy puste - z dawnego klucza. */
    private function token(string $env): string
    {
        $token = trim((string) $this->setting('token_' . $env, ''));
        if ($token !== '') {
            return $token;
        }
        try {
            return (string) (new SettingsRepository($this->pdo()))->get(self::tokenKey($env), '');
        } catch (\Throwable) {
            return '';
        }
    }

    // ------------------------------------------------------------------ HTML

    /** @return array{0:string,1:string} [stan ikony, opis] */
    private static function state(string $status): array
    {
        return match ($status) {
            'accepted' => ['ok', 'przyjęta w KSeF'],
            'processing', 'sending' => ['transit', 'KSeF sprawdza fakturę'],
            'rejected' => ['bad', 'odrzucona przez KSeF'],
            default => ['bad', 'wysyłka nieudana'],
        };
    }

    private static function pill(string $status): string
    {
        [$state, $label] = self::state($status);
        $cls = ['ok' => 'ok', 'transit' => 'warn', 'bad' => 'bad'][$state] ?? 'muted';
        return '<span class="pill ' . $cls . '">' . htmlspecialchars($label) . '</span>';
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    private static function postButton(string $page, array $fields, string $label, string $class = 'btn secondary'): string
    {
        $html = '<form method="post" action="' . self::h(Hooks::pageUrl($page)) . '" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . self::h(csrfToken()) . '">';
        foreach ($fields as $k => $v) {
            $html .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        return $html . '<button class="' . self::h($class) . '" type="submit">' . self::h($label) . '</button></form> ';
    }

    private static function envNote(string $env): string
    {
        return $env === 'prod' ? '' : '<p style="color:var(--ink-3);font-size:12px;margin:0 0 8px">Środowisko: '
            . self::h(KsefApi::ENVIRONMENTS[$env]['label']) . ' - faktury nie mają mocy prawnej.</p>';
    }

    private static function section(KsefService $s, int $orderId): string
    {
        $env = $s->env();
        $canEdit = function_exists('roleCanEdit') && roleCanEdit();
        $row = $s->invoices()->get($orderId, $env);
        $html = self::envNote($env);
        if ($row === null) {
            $missing = $s->missing();
            if ($missing !== []) {
                return $html . '<p style="color:var(--ink-2);margin:0">Wtyczka KSeF czeka na ustawienia: ' . self::h(implode(', ', $missing))
                    . '. <a href="' . self::h(Hooks::pageUrl('ksef.faktury')) . '">Faktury KSeF</a></p>';
            }
            return $html . '<p style="color:var(--ink-2);margin:0 0 10px">Zamówienie nie ma e-faktury w KSeF.</p>'
                . ($canEdit ? '<a class="btn secondary" href="' . self::h(Hooks::pageUrl('ksef.wystaw', ['id' => $orderId])) . '">Podgląd i wysyłka do KSeF</a>' : '');
        }
        $html .= '<p style="margin:0 0 6px"><b>' . self::h($row['number']) . '</b> ' . self::pill((string) $row['status']) . '</p>';
        if ($row['ksef_number']) {
            $html .= '<p style="margin:0 0 6px">Numer KSeF: <code>' . self::h($row['ksef_number']) . '</code></p>';
        }
        if ($row['message']) {
            $html .= '<p style="color:var(--ink-2);margin:0 0 8px;white-space:pre-wrap">' . self::h($row['message']) . '</p>';
        }
        $links = [];
        if ($row['status'] === 'accepted' && $row['xml']) {
            $links[] = '<a href="' . self::h(KsefService::verificationUrl($env, $s->nip(), (string) $row['issue_date'], (string) $row['xml']))
                . '" target="_blank" rel="noopener noreferrer">Faktura w KSeF (link dla klienta)</a>';
        }
        if ($row['xml']) {
            $links[] = '<a href="' . self::h(Hooks::pageUrl('ksef.plik', ['id' => $orderId, 'co' => 'xml'])) . '">XML</a>';
        }
        if ($row['upo']) {
            $links[] = '<a href="' . self::h(Hooks::pageUrl('ksef.plik', ['id' => $orderId, 'co' => 'upo'])) . '">UPO</a>';
        }
        if ($links !== []) {
            $html .= '<p style="margin:0 0 10px">' . implode(' · ', $links) . '</p>';
        }
        if ($canEdit) {
            if (in_array($row['status'], Invoices::OPEN, true) || ($row['status'] === 'accepted' && !$row['upo'])) {
                $html .= self::postButton('ksef.status', ['id' => $orderId], 'Sprawdź status w KSeF');
            }
            if (in_array($row['status'], ['rejected', 'error'], true)) {
                $html .= '<a class="btn secondary" href="' . self::h(Hooks::pageUrl('ksef.wystaw', ['id' => $orderId])) . '">Popraw i wyślij ponownie</a>';
            }
        }
        return $html;
    }

    private static function previewPage(KsefService $s, int $id, array $ctx): string
    {
        $env = $s->env();
        $existing = $s->invoices()->get($id, $env);
        try {
            $p = $s->preview($id);
        } catch (\Throwable $e) {
            return '<div class="flash err">' . self::h($e->getMessage()) . '</div><p><a href="order_view.php?id=' . $id . '">← Zamówienie</a></p>';
        }
        $inv = $p['invoice'];
        $b = $inv['buyer'];
        $buyerId = $b['nip'] !== '' ? 'NIP ' . $b['nip'] : ($b['eu_vat'] !== '' ? 'VAT UE ' . $b['eu_code'] . $b['eu_vat'] : 'osoba prywatna, bez NIP');
        $html = '<p><a href="order_view.php?id=' . $id . '">← Zamówienie</a></p><div class="card">' . self::envNote($env)
            . '<p><b>Numer:</b> ' . self::h($existing['number'] ?? $inv['number']) . ' &nbsp; <b>Data wystawienia:</b> ' . self::h($inv['issue_date']) . '</p>'
            . '<p><b>Sprzedawca:</b> ' . self::h($inv['seller']['name']) . ', NIP ' . self::h($inv['seller']['nip']) . ', ' . self::h(trim($inv['seller']['address1'] . ', ' . $inv['seller']['address2'], ', ')) . '</p>'
            . '<p><b>Nabywca:</b> ' . self::h($b['name']) . ' (' . self::h($buyerId) . ')' . ($b['address1'] !== '' ? ', ' . self::h(trim($b['address1'] . ', ' . $b['address2'], ', ')) : '') . '</p>'
            . '<table><tr><th>Pozycja</th><th>Ilość</th><th>Cena brutto</th><th>Wartość brutto</th><th>VAT</th></tr>';
        foreach ($inv['lines'] as $l) {
            $html .= '<tr><td>' . self::h($l['name']) . '</td><td>' . self::h($l['qty']) . '</td><td>' . number_format((float) $l['unit_gross'], 2, ',', ' ')
                . '</td><td>' . number_format((float) $l['gross'], 2, ',', ' ') . '</td><td>' . self::h($l['rate'] === 'zw' ? 'zw' : $l['rate'] . '%') . '</td></tr>';
        }
        $html .= '</table><table style="margin-top:10px"><tr><th>Stawka</th><th>Netto</th><th>VAT</th><th>Brutto</th></tr>';
        foreach ($p['totals']['rates'] as $rate => $t) {
            $html .= '<tr><td>' . self::h($rate === 'zw' ? 'zw' : $rate . '%') . '</td><td>' . number_format($t['net'], 2, ',', ' ') . '</td><td>'
                . number_format($t['vat'], 2, ',', ' ') . '</td><td>' . number_format($t['gross'], 2, ',', ' ') . '</td></tr>';
        }
        $html .= '<tr><td><b>Razem</b></td><td></td><td></td><td><b>' . number_format($p['totals']['gross'], 2, ',', ' ') . ' ' . self::h($inv['currency']) . '</b></td></tr></table>'
            . '<p><b>Płatność:</b> ' . ($inv['paid'] ? 'zapłacono ' . self::h($inv['paid_date']) : 'do zapłaty') . ($inv['payment_desc'] !== '' ? ', ' . self::h($inv['payment_desc']) : '') . '</p>';
        if ($p['error'] !== null) {
            return $html . '<div class="flash err">' . self::h($p['error']) . '</div><p>Popraw dane do faktury w zamówieniu albo ustawienia wtyczki (Konfiguracja → Wtyczki → KSeF).</p></div>';
        }
        $missing = $s->missing();
        if ($missing !== []) {
            return $html . '<div class="flash err">Uzupełnij ustawienia KSeF: ' . self::h(implode(', ', $missing)) . '.</div></div>';
        }
        return $html . '<p style="color:var(--ink-2)">Po wysłaniu faktury nie da się jej zmienić ani usunąć - błąd poprawia się fakturą korygującą.</p>'
            . '<form method="post" action="' . self::h($ctx['url']) . '"><input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">'
            . '<input type="hidden" name="id" value="' . $id . '"><button class="btn" type="submit">Wyślij do KSeF</button></form></div>';
    }

    private function listPage(KsefService $s, array $ctx): string
    {
        $env = $s->env();
        $sales = $s->salesEnabled();
        $missing = $sales ? $s->missing() : $s->missingConnection();
        $html = '<div class="card"><h3 style="margin-top:0">Połączenie</h3>' . self::envNote($env)
            . '<p>Środowisko: <b>' . self::h(KsefApi::ENVIRONMENTS[$env]['label']) . '</b>, NIP firmy: <b>' . self::h($s->nip() ?: '-') . '</b>, token: <b>'
            . (in_array('token KSeF', $missing, true) ? 'brak' : 'zapisany') . '</b></p>';
        if ($missing !== []) {
            $html .= '<p class="flash err">' . ($sales ? 'Do wysyłki' : 'Do połączenia') . ' brakuje: ' . self::h(implode(', ', $missing)) . '. Dane firmy ustawisz w Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia.</p>';
        }
        $html .= '<p style="margin:0 0 8px">Włączone: ' . self::h(implode(', ', array_filter([$sales ? 'wystawianie e-faktur z zamówień' : '',
            $s->salesListEnabled() ? 'faktury sprzedażowe z KSeF' : '', $s->purchasesEnabled() ? 'faktury zakupowe' : ''])) ?: 'nic') . ' <small style="color:var(--ink-3)">(zmienisz w Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia)</small>'
            . ($s->salesListEnabled() ? ' · <a href="' . self::h(Hooks::pageUrl('ksef.sprzedaz')) . '">Faktury sprzedażowe</a>' : '')
            . ($s->purchasesEnabled() ? ' · <a href="' . self::h(Hooks::pageUrl('ksef.zakupy')) . '">Faktury zakupowe</a>' : '') . '</p>';
        if ($ctx['isAdmin']) {
            $html .= '<p style="margin:8px 0">' . self::postButton('ksef.polaczenie', [], 'Sprawdź połączenie')
                . ' <a href="plugins.php">Token i dane firmy: Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia</a></p>';
        }
        $html .= '</div>';
        if (!$sales) {
            return $html;
        }

        $rows = $s->invoices()->latest($env);
        $html .= '<div class="card"><h3 style="margin-top:0">e-Faktury</h3>';
        if ($ctx['canEdit'] && $rows !== []) {
            $html .= '<p>' . self::postButton('ksef.odswiez', [], 'Sprawdź oczekujące w KSeF') . '</p>';
        }
        $html .= '<table><tr><th>Numer</th><th>Zamówienie</th><th>Nabywca</th><th>Brutto</th><th>Status</th><th>Numer KSeF</th><th>Wystawił</th><th>Data</th></tr>';
        foreach ($rows as $r) {
            $html .= '<tr><td>' . self::h($r['number']) . '</td><td><a href="order_view.php?id=' . (int) $r['woo_order_id'] . '">#' . (int) $r['woo_order_id'] . '</a></td>'
                . '<td>' . self::h($r['buyer']) . '</td><td>' . number_format((float) $r['gross'], 2, ',', ' ') . '</td><td>' . self::pill((string) $r['status'])
                . ($r['status'] !== 'accepted' && $r['message'] ? '<br><small>' . self::h(mb_strimwidth((string) $r['message'], 0, 160, '…')) . '</small>' : '')
                . '</td><td><code>' . self::h($r['ksef_number']) . '</code></td><td>' . self::h($r['created_by']) . '</td><td>' . self::h($r['issue_date']) . '</td></tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="8" style="color:var(--ink-2)">Brak e-faktur w tym środowisku. Wystawisz je z karty zamówienia (sekcja „e-Faktura KSeF”) albo automatyzacją.</td></tr>';
        }
        return $html . '</table></div>';
    }

    private static function money(mixed $v): string
    {
        return $v === null || $v === '' ? '' : number_format((float) $v, 2, ',', ' ');
    }

    private static function invoicesPage(KsefService $s, array $ctx, string $kind): string
    {
        $k = self::KINDS[$kind];
        $env = $s->env();
        $repo = $s->ksefInvoices($kind);
        $q = trim((string) ($_GET['q'] ?? ''));
        $months = $repo->months($env);
        $month = (string) ($_GET['m'] ?? '');
        if ($month !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            // Bez wybranego miesiąca: przy wyszukiwaniu wszystkie, inaczej najnowszy miesiąc z fakturami.
            $month = $q !== '' ? 'all' : (array_key_first($months) ?? date('Y-m'));
        }
        $missing = $s->missingConnection();
        $point = $repo->syncPoint($env);
        $html = '<div class="card">' . self::envNote($env)
            . '<p style="margin:0 0 8px">Faktury, w których Twoja firma (NIP ' . self::h($s->nip() ?: '-') . ') jest ' . $k['role'] . '. '
            . ($point !== null ? 'Pobrane z KSeF do: <b>' . self::h(self::when($point)) . '</b>.' : 'Jeszcze nic nie pobrano.') . '</p>';
        if ($missing !== []) {
            $html .= '<p class="flash err">Do połączenia z KSeF brakuje: ' . self::h(implode(', ', $missing)) . '. <a href="' . self::h(Hooks::pageUrl('ksef.faktury')) . '">Faktury KSeF</a></p>';
        } elseif ($ctx['canEdit']) {
            $today = date('Y-m-d');
            $html .= '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;margin:0 0 12px">'
                . '<div>' . self::postButton('ksef.' . $k['fetch'], [], 'Pobierz nowe z KSeF', 'btn') . '</div>'
                . '<form method="post" action="' . self::h(Hooks::pageUrl('ksef.' . $k['fetch'])) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0">'
                . '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">'
                . '<label>Wystawione od<br><input type="date" name="od" required value="' . self::h(date('Y-m-01')) . '" max="' . $today . '"></label>'
                . '<label>do<br><input type="date" name="do" required value="' . $today . '" max="' . $today . '"></label>'
                . '<button class="btn secondary" type="submit">Pobierz z okresu</button></form></div>'
                . '<p style="color:var(--ink-3);font-size:12px;margin:-4px 0 10px">„Pobierz nowe” bierze faktury, które trafiły do KSeF od ostatniego pobrania. '
                . '„Pobierz z okresu” - faktury z datą wystawienia w wybranym okresie (np. starsze miesiące).</p>';
        }
        $filter = $month === 'all' ? '' : $month;
        $link = static fn(array $over): string => Hooks::pageUrl('ksef.' . $k['list'], array_filter(array_merge(['m' => $month, 'q' => $q], $over), static fn($v): bool => $v !== '' && $v !== 1));
        $opts = '<option value="all"' . ($month === 'all' ? ' selected' : '') . '>Wszystkie miesiące</option>';
        $list = $months;
        if ($month !== 'all' && !isset($list[$month])) {
            $list[$month] = 0;
            krsort($list);
        }
        foreach ($list as $m => $n) {
            $opts .= '<option value="' . self::h($m) . '"' . ($m === $month ? ' selected' : '') . '>' . self::h(self::monthName($m)) . ' (' . $n . ')</option>';
        }
        $nav = '';
        if ($month !== 'all') {
            $prev = (new \DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
            $next = (new \DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');
            $oldest = array_key_last($months);
            $nav = ($oldest !== null && $prev >= $oldest ? '<a class="btn secondary" href="' . self::h($link(['m' => $prev, 'p' => 1])) . '">← ' . self::h(self::monthName($prev)) . '</a>' : '')
                . ($next <= date('Y-m') ? ' <a class="btn secondary" href="' . self::h($link(['m' => $next, 'p' => 1])) . '">' . self::h(self::monthName($next)) . ' →</a>' : '');
        }
        $html .= '<form method="get" action="ext.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0">'
            . '<input type="hidden" name="page" value="ksef.' . $k['list'] . '">'
            . '<label>Miesiąc wystawienia<br><select name="m">' . $opts . '</select></label>'
            . '<label>Szukaj<br><input type="search" name="q" value="' . self::h($q) . '" placeholder="numer, ' . $k['party_q'] . ', NIP"></label>'
            . '<button class="btn secondary" type="submit">Pokaż</button>'
            . ($q !== '' ? ' <a href="' . self::h($link(['q' => '', 'p' => 1])) . '">wyczyść szukanie</a>' : '') . '</form>'
            . ($nav !== '' ? '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' . $nav . '</div>' : '') . '</div>';

        $totals = $repo->totals($env, $q, $filter);
        $pages = max(1, (int) ceil($totals['count'] / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($_GET['p'] ?? 1)));
        $rows = $repo->latest($env, $q, $filter, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $title = $month === 'all' ? 'Wszystkie miesiące' : ucfirst(self::monthName($month));
        $html .= '<div class="card"><h3 style="margin:0 0 8px">' . self::h($title) . ($q !== '' ? ' · „' . self::h($q) . '”' : '') . '</h3>'
            . '<div style="overflow-x:auto"><table><tr><th>Data</th><th>Numer faktury</th><th>' . $k['party'] . '</th><th>Netto</th><th>VAT</th><th>Brutto</th><th>Rodzaj</th><th>Numer KSeF</th></tr>';
        foreach ($rows as $r) {
            $cur = (string) ($r['currency'] ?: 'PLN');
            $url = Hooks::pageUrl('ksef.' . $k['one'], ['k' => $r['ksef_number']]);
            $html .= '<tr><td>' . self::h($r['issue_date']) . '</td><td><a href="' . self::h($url) . '">' . self::h($r['invoice_number']) . '</a></td>'
                . '<td>' . self::h($r['party_name']) . '<br><small>NIP ' . self::h($r['party_nip']) . '</small></td>'
                . '<td>' . self::money($r['net']) . '</td><td>' . self::money($r['vat']) . '</td><td><b>' . self::money($r['gross']) . '</b> ' . self::h($cur) . '</td>'
                . '<td>' . self::h(Purchases::TYPES[$r['invoice_type']] ?? $r['invoice_type']) . '</td><td><small><code>' . self::h($r['ksef_number']) . '</code></small></td></tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="8" style="color:var(--ink-2)">' . ($q !== '' ? 'Brak faktur dla tego wyszukiwania.'
                : ($months === [] ? 'Brak faktur ' . ($kind === Purchases::SALE ? 'sprzedażowych' : 'zakupowych') . ' w tym środowisku. Kliknij „Pobierz nowe z KSeF”.' : 'Brak faktur wystawionych w tym miesiącu.')) . '</td></tr>';
        } else {
            foreach ($totals['sums'] as $cur => $t) {
                $html .= '<tr><td colspan="3"><b>Razem' . (count($totals['sums']) > 1 ? ' ' . self::h($cur) : '') . ' (' . $totals['count'] . ($pages > 1 ? ', wszystkie strony' : '') . ')</b></td>'
                    . '<td><b>' . self::money($t['net']) . '</b></td><td><b>' . self::money($t['vat']) . '</b></td><td colspan="3"><b>' . self::money($t['gross']) . '</b> ' . self::h($cur) . '</td></tr>';
            }
        }
        return $html . '</table></div>' . self::pager($page, $pages, static fn(int $p): string => $link(['p' => $p])) . '</div>';
    }

    /** Paginacja jak w liście zamówień: ← Poprzednia · 1 … 4 [5] 6 … 20 · Następna →. */
    private static function pager(int $page, int $pages, callable $link): string
    {
        if ($pages <= 1) {
            return '';
        }
        $nums = array_unique(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2))));
        sort($nums);
        $a = 'min-width:34px;height:34px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;text-decoration:none;border:1px solid var(--line,#e5e2da);box-sizing:border-box';
        $html = '<nav aria-label="Paginacja" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;justify-content:center;margin-top:16px">';
        if ($page > 1) {
            $html .= '<a class="btn secondary" href="' . self::h($link($page - 1)) . '">← Poprzednia</a>';
        }
        $prev = 0;
        foreach ($nums as $n) {
            if ($n - $prev > 1) {
                $html .= '<span style="color:var(--ink-3)">…</span>';
            }
            $html .= $n === $page
                ? '<span aria-current="page" style="' . $a . ';background:#8a6a3b;border-color:#8a6a3b;color:#fff;font-weight:700">' . $n . '</span>'
                : '<a href="' . self::h($link($n)) . '" style="' . $a . '">' . $n . '</a>';
            $prev = $n;
        }
        if ($page < $pages) {
            $html .= '<a class="btn secondary" href="' . self::h($link($page + 1)) . '">Następna →</a>';
        }
        return $html . '<span style="color:var(--ink-3);font-size:13px">strona ' . $page . ' z ' . $pages . '</span></nav>';
    }

    private static function monthName(string $ym): string
    {
        static $names = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        [$y, $m] = array_map('intval', explode('-', $ym));
        return ($names[$m - 1] ?? $ym) . ' ' . $y;
    }

    private static function invoicePage(KsefService $s, string $kind, string $ksefNumber): string
    {
        $k = self::KINDS[$kind];
        $env = $s->env();
        $back = '<p><a href="' . self::h(Hooks::pageUrl('ksef.' . $k['list'])) . '">← ' . $k['plural'] . '</a></p>';
        $row = $s->ksefInvoices($kind)->get($env, $ksefNumber);
        if ($row === null) {
            return $back . '<div class="flash err">Nie ma takiej faktury ' . $k['adj_gen'] . '.</div>';
        }
        $meta = self::invoiceMeta($s, $kind, $ksefNumber);
        $html = $back . '<div class="card">' . self::envNote($env)
            . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px">'
            . '<a class="btn" href="' . self::h(Hooks::pageUrl('ksef.' . $k['pdf'], ['k' => $ksefNumber, 'pobierz' => 1])) . '">Pobierz PDF</a>'
            . '<a class="btn secondary" href="' . self::h(Hooks::pageUrl('ksef.' . $k['pdf'], ['k' => $ksefNumber])) . '" target="_blank" rel="noopener">Otwórz PDF</a>'
            . '<a class="btn secondary" href="' . self::h(Hooks::pageUrl('ksef.' . $k['xml'], ['k' => $ksefNumber])) . '">Pobierz XML</a>'
            . (isset($meta['qr']) ? '<a class="btn secondary" href="' . self::h($meta['qr']) . '" target="_blank" rel="noopener noreferrer">Faktura w KSeF</a>' : '') . '</div>';
        try {
            $html .= InvoiceView::html($s->invoiceXml($kind, $ksefNumber), $meta);
        } catch (\Throwable $e) {
            $html .= '<p><b>' . self::h($row[$kind === Purchases::SALE ? 'buyer_name' : 'seller_name']) . '</b> (NIP ' . self::h($row[$kind === Purchases::SALE ? 'buyer_nip' : 'seller_nip']) . '), faktura ' . self::h($row['invoice_number'])
                . ' z ' . self::h($row['issue_date']) . ': ' . self::money($row['gross']) . ' ' . self::h($row['currency']) . ' brutto.</p>'
                . '<div class="flash err">Nie udało się pokazać treści faktury: ' . self::h($e->getMessage()) . '</div>';
        }
        return $html . '</div>';
    }

    /**
     * Numer KSeF i link weryfikacyjny (kod QR) faktury pobranej z KSeF. Link zawiera NIP sprzedawcy:
     * w zakupowych to kontrahent, w sprzedażowych - nasza firma.
     * @return array{ksef:string,qr?:string}
     */
    private static function invoiceMeta(KsefService $s, string $kind, string $ksefNumber): array
    {
        $row = $s->ksefInvoices($kind)->get($s->env(), $ksefNumber);
        $meta = ['ksef' => $ksefNumber];
        $sellerNip = $kind === Purchases::SALE ? $s->nip() : (string) ($row['seller_nip'] ?? '');
        if ($row !== null && !empty($row['invoice_hash']) && $sellerNip !== '') {
            $meta['qr'] = KsefService::verificationUrlForHash($s->env(), $sellerNip, (string) $row['issue_date'], (string) $row['invoice_hash']);
        }
        return $meta;
    }

    /** Data z KSeF (ISO, UTC) w czasie polskim. */
    private static function when(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
