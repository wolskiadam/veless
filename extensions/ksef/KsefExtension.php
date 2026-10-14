<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use Pase\Repository\SettingsRepository;
use PDO;

/**
 * KSeF: e-faktury FA(3) z zamówień wysyłane wprost do Krajowego Systemu e-Faktur (API 2.0), bez programu księgowego.
 *
 *  - karta zamówienia: sekcja „e-Faktura KSeF” (podgląd, wysyłka, status, numer KSeF, XML, UPO, link weryfikacyjny),
 *  - lista zamówień: ikona stanu e-faktury przy statusie,
 *  - Zamówienia → Faktury KSeF: lista e-faktur, połączenie (token KSeF) i test połączenia,
 *  - automatyzacje: akcja „Wyślij e-fakturę do KSeF”.
 *
 * Domyślnie działa na środowisku testowym KSeF. Token KSeF trzymamy zaszyfrowany w ustawieniach
 * (osobno dla każdego środowiska), nigdy w kodzie. Opis: README.md w tym katalogu.
 */
final class KsefExtension extends AbstractExtension
{
    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'ksef',
            name: 'KSeF - e-faktury',
            version: '1.0.0',
            author: 'Veless',
            description: 'Wystawia faktury FA(3) z zamówień i wysyła je wprost do Krajowego Systemu e-Faktur (API 2.0): numer KSeF, UPO i link weryfikacyjny w karcie zamówienia. Token KSeF wpisz na stronie Zamówienia → Faktury KSeF.',
            icon: '🏛️',
            fields: [
                ['key' => 'environment', 'label' => 'Środowisko KSeF', 'type' => 'select', 'default' => 'test',
                 'options' => array_map(static fn(array $e): string => $e['label'], KsefApi::ENVIRONMENTS),
                 'help' => 'Zacznij od testowego. Faktury z produkcyjnego mają moc prawną i nie da się ich usunąć, tylko skorygować.'],
                ['key' => 'nip', 'label' => 'NIP firmy (sprzedawcy)', 'type' => 'text', 'required' => true],
                ['key' => 'seller_name', 'label' => 'Nazwa firmy', 'type' => 'text', 'required' => true],
                ['key' => 'seller_address1', 'label' => 'Adres: ulica i numer', 'type' => 'text', 'required' => true],
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
        Hooks::addPage('faktury', ['title' => '🏛️ Faktury KSeF', 'group' => 'orders', 'role' => 'viewer',
            'render' => fn(PDO $pdo, array $ctx): string => $this->listPage($service(), $ctx)]);

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

        Hooks::addPage('polaczenie', ['title' => 'Połączenie z KSeF', 'menu' => false, 'role' => 'admin',
            'render' => function (PDO $pdo, array $ctx) use ($service): void {
                if ($ctx['post']) {
                    $env = (string) ($this->settings()['environment'] ?? 'test');
                    if (($_POST['do'] ?? '') === 'save') {
                        $token = trim((string) ($_POST['token'] ?? ''));
                        if ($token !== '') {
                            (new SettingsRepository($pdo))->setMany([self::tokenKey($env) => $token]);
                            flash('Zapisano token KSeF dla środowiska: ' . (KsefApi::ENVIRONMENTS[$env]['label'] ?? $env) . '.');
                        }
                    } elseif (($_POST['do'] ?? '') === 'forget') {
                        (new SettingsRepository($pdo))->setMany([self::tokenKey($env) => '']);
                        flash('Usunięto token KSeF dla środowiska: ' . (KsefApi::ENVIRONMENTS[$env]['label'] ?? $env) . '.');
                    } else {
                        try {
                            flash($service()->testConnection());
                        } catch (\Throwable $e) {
                            flash($e->getMessage(), 'err');
                        }
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

    /** Klucz w tabeli settings; końcówka _TOKEN sprawia, że rdzeń trzyma go zaszyfrowanego (SecretStore). */
    public static function tokenKey(string $env): string
    {
        return 'EXT_KSEF_' . strtoupper(preg_replace('/[^a-z]/', '', $env) ?: 'test') . '_TOKEN';
    }

    private function token(string $env): string
    {
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
        $missing = $s->missing();
        $html = '<div class="card"><h3 style="margin-top:0">Połączenie</h3>' . self::envNote($env)
            . '<p>Środowisko: <b>' . self::h(KsefApi::ENVIRONMENTS[$env]['label']) . '</b>, NIP firmy: <b>' . self::h($s->nip() ?: '-') . '</b>, token: <b>'
            . (in_array('token KSeF', $missing, true) ? 'brak' : 'zapisany') . '</b></p>';
        if ($missing !== []) {
            $html .= '<p class="flash err">Do wysyłki brakuje: ' . self::h(implode(', ', $missing)) . '. Dane firmy ustawisz w Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia.</p>';
        }
        if ($ctx['isAdmin']) {
            $html .= '<form method="post" action="' . self::h(Hooks::pageUrl('ksef.polaczenie')) . '" style="margin:8px 0">'
                . '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '"><input type="hidden" name="do" value="save">'
                . '<label>Token KSeF dla środowiska: ' . self::h(KsefApi::ENVIRONMENTS[$env]['label']) . '<br><input type="password" name="token" autocomplete="off" style="width:min(520px,100%)" placeholder="wklej nowy token, puste = bez zmian"></label> '
                . '<button class="btn secondary" type="submit">Zapisz token</button></form>'
                . self::postButton('ksef.polaczenie', ['do' => 'test'], 'Sprawdź połączenie')
                . (!in_array('token KSeF', $missing, true) ? self::postButton('ksef.polaczenie', ['do' => 'forget'], 'Usuń token', 'btn danger') : '')
                . '<p style="color:var(--ink-3);font-size:12px">Token wygenerujesz w Aplikacji Podatnika KSeF (lub przez API) z uprawnieniem do wystawiania faktur. '
                . 'Jest zapisywany zaszyfrowany, osobno dla każdego środowiska.</p>';
        }
        $html .= '</div>';

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
}
