<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use PaseExt\Produkcja\Ui as U;
use PDO;

/**
 * Produkcja świec: materiały i magazyn, wyroby z recepturą i kosztem, partie z numerem (wzór z Rejestru Partii),
 * testy spalania, numer partii przy pakowaniu i na karcie zamówienia.
 *
 * Zakładka „Produkcja” w menu. Partia zdejmuje materiały z dostaw i dokłada sztuki do produktu CRM o tym samym SKU;
 * sprzedaż zdejmuje sztuki jak dotąd (rdzeń). Opis: README.md w tym katalogu.
 */
final class ProdukcjaExtension extends AbstractExtension
{
    private const MENU = ['pulpit' => 'Pulpit produkcji', 'materialy' => 'Materiały i magazyn', 'wyroby' => 'Wyroby', 'partie' => 'Partie', 'testy' => 'Testy spalania'];

    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'produkcja',
            name: 'Produkcja',
            version: '1.0.0',
            author: 'Veless',
            description: 'Produkcja świec: materiały z magazynem i datami ważności, wyroby z recepturą i kosztem sztuki, partie z numerem do śledzenia (zdejmują materiały i dokładają sztuki do produktu), testy spalania i numer partii przypisywany przy pakowaniu. Import danych z Rejestru Partii.',
            icon: '🕯',
            fields: [
                ['key' => 'pattern', 'label' => 'Wzór numeru partii', 'type' => 'text', 'default' => BatchCode::DEFAULT_PATTERN,
                 'help' => '{DC}{MC}{YC} dzień/miesiąc/rok literami, {DD}{MM}{YY}{YYYY} cyframi, {QTY} ilość, {WC} kod wosku, {SC} kod zapachu, {WK} knot (B/K/X), {PC} zmiana albo kolejna litera, {SEQ} numer partii tego dnia. Ten sam wzór co w Rejestrze Partii.'],
                ['key' => 'stock_target', 'label' => 'Partia dokłada sztuki do', 'type' => 'select', 'default' => 'actual',
                 'options' => ['actual' => 'stanu faktycznego produktu (bez wysyłki do sklepu)', 'crm' => 'stanu CRM (idzie do sklepu)', 'both' => 'obu stanów', 'none' => 'niczego'],
                 'help' => 'Produkt CRM szukany po SKU wyrobu. Sprzedaż zdejmuje sztuki jak dotąd.'],
                ['key' => 'hourly_rate', 'label' => 'Stawka za godzinę pracy (zł)', 'type' => 'text', 'default' => '',
                 'help' => 'Do kosztu robocizny, gdy osoba nie ma własnej stawki.'],
                ['key' => 'fixed_monthly', 'label' => 'Koszty stałe miesięcznie (zł)', 'type' => 'text', 'default' => '',
                 'help' => 'Czynsz, prąd, abonamenty. Służy do progu opłacalności na karcie wyrobu.'],
                ['key' => 'label_w', 'label' => 'Etykieta partii: szerokość (mm)', 'type' => 'text', 'default' => '50'],
                ['key' => 'label_h', 'label' => 'Etykieta partii: wysokość (mm)', 'type' => 'text', 'default' => '30'],
                ['key' => 'maker', 'label' => 'Producent na etykiecie partii', 'type' => 'textarea', 'default' => '',
                 'help' => 'Nazwa, adres i kontakt (GPSR). Puste = bez tej linii.'],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function cfg(): array
    {
        $out = [];
        foreach (['pattern', 'stock_target', 'hourly_rate', 'fixed_monthly', 'label_w', 'label_h', 'maker'] as $k) {
            $out[$k] = $this->setting($k, '');
        }
        $out['hourly_rate'] = Store::num($out['hourly_rate']) ?? 0.0;
        $out['fixed_monthly'] = Store::num($out['fixed_monthly']) ?? 0.0;
        $out['stock_target'] = in_array($out['stock_target'], ['actual', 'crm', 'both', 'none'], true) ? $out['stock_target'] : 'actual';
        return $out;
    }

    public function boot(): void
    {
        if (!License::active()) {
            return;
        }
        $store = fn(): Store => new Store($this->pdo());
        $prod = fn(Store $s): Production => new Production($s, $this->cfg());
        $user = static fn(array $ctx): string => (string) $ctx['user'];
        $go = static function (string $page, array $q = []): void {
            header('Location: ' . U::url($page, $q));
            exit;
        };

        // ---- Zakładka Produkcja w menu (strony rejestrujemy w grupie „catalog” i przenosimy).
        foreach (self::MENU as $id => $title) {
            Hooks::addPage($id, ['title' => $title, 'group' => 'catalog', 'role' => 'viewer', 'render' => fn(PDO $pdo, array $ctx): string => match ($id) {
                'pulpit' => $this->dashboard($store(), $ctx),
                'materialy' => MaterialPages::list($store(), $ctx),
                'wyroby' => ProductPages::list($s = $store(), $prod($s), $ctx, $this->cfg()),
                'partie' => BatchPages::list($s = $store(), $prod($s), $ctx),
                'testy' => TestPages::list($store(), $ctx),
            }]);
        }
        Hooks::addFilter('admin.menu', static function (array $groups): array {
            $mine = [];
            foreach ($groups as $gk => $g) {
                foreach ($g['items'] as $i => $item) {
                    if (str_contains((string) ($item['href'] ?? ''), 'page=produkcja.')) {
                        $mine[] = $item;
                        unset($groups[$gk]['items'][$i]);
                    }
                }
                $groups[$gk]['items'] = array_values($groups[$gk]['items']);
                if ($groups[$gk]['items'] === []) {
                    unset($groups[$gk]);
                }
            }
            if ($mine === []) {
                return $groups;
            }
            $out = [];
            foreach ($groups as $gk => $g) {
                $out[$gk] = $g;
                if ($gk === 'catalog') {
                    $out['produkcja'] = ['label' => 'Produkcja', 'items' => $mine];
                }
            }
            return $out + ['produkcja' => ['label' => 'Produkcja', 'items' => $mine]];
        });

        // ---- Materiały
        Hooks::addPage('material', ['title' => 'Materiał', 'menu' => false, 'role' => 'viewer',
            'render' => static function (PDO $pdo, array $ctx) use ($store): string {
                $s = $store();
                if (isset($_GET['nowy']) && $ctx['canEdit']) {
                    return MaterialPages::card($s, $ctx, null);
                }
                $m = $s->material((string) ($_GET['id'] ?? ''));
                if ($m === null) {
                    return '<div class="flash err">Nie ma takiego materiału.</div>';
                }
                if (isset($_GET['edytuj']) && $ctx['canEdit']) {
                    return U::style() . U::tabs('materialy') . U::back(U::url('material', ['id' => $m['id']]), (string) $m['name']) . MaterialPages::form($ctx, $m);
                }
                return MaterialPages::card($s, $ctx, $m);
            }]);
        Hooks::addPage('material_zapisz', ['title' => 'Materiał', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $user, $go): string {
                $s = $store();
                $id = (string) ($_POST['id'] ?? '');
                $old = $id !== '' ? $s->material($id) : null;
                if (!$ctx['post'] || ($id !== '' && $old === null)) {
                    $go('materialy');
                }
                if ($old !== null && isset($_POST['archive'])) {
                    $s->update('ext_prod_materials', $id, ['archived' => (int) $_POST['archive'] === 1 ? 1 : 0, 'updated_at' => Store::now()]);
                    flash((int) $_POST['archive'] === 1 ? 'Materiał przeniesiony do archiwum.' : 'Materiał przywrócony z archiwum.');
                    $go('material', ['id' => $id]);
                }
                try {
                    $m = $s->tx(function () use ($s, $old, $user, $ctx): array {
                        $m = $s->saveMaterial($old['id'] ?? null, $_POST);
                        if ($old === null && ($q = Store::num($_POST['stock'] ?? '')) !== null && $q > 0) {
                            $s->addDelivery((string) $m['id'], ['qty' => $q, 'price_total' => $_POST['price_total'] ?? '', 'expires_at' => $_POST['expires_at'] ?? '',
                                'notes' => 'Stan początkowy'], $user($ctx));
                        }
                        return $m;
                    });
                    flash(($old === null ? 'Dodano materiał: ' : 'Zapisano: ') . $m['name'] . '.');
                    $go('material', ['id' => $m['id']]);
                } catch (\RuntimeException $e) {
                    flash($e->getMessage(), 'err');
                    return U::style() . U::tabs('materialy') . MaterialPages::form($ctx, $old ?? [], $_POST);
                }
                return '';
            }]);
        Hooks::addPage('dostawa', ['title' => 'Dostawa', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $user, $go): void {
                $mid = (string) ($_POST['material_id'] ?? '');
                if ($ctx['post']) {
                    try {
                        $d = $store()->addDelivery($mid, $_POST, $user($ctx));
                        flash('Przyjęto dostawę: ' . U::n($d['qty']) . '.');
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                $go('material', ['id' => $mid]);
            }]);
        foreach (['dostawa_usun' => 'trashDelivery', 'dostawa_przywroc' => 'restoreDelivery'] as $page => $method) {
            Hooks::addPage($page, ['title' => 'Dostawa', 'menu' => false, 'role' => 'editor',
                'render' => static function (PDO $pdo, array $ctx) use ($store, $user, $go, $method, $page): void {
                    $s = $store();
                    $d = $s->delivery((string) ($_POST['id'] ?? ''));
                    if ($ctx['post'] && $d !== null) {
                        $s->{$method}((string) $d['id'], $user($ctx));
                        flash($page === 'dostawa_usun' ? 'Dostawa w koszu (30 dni).' : 'Przywrócono dostawę.');
                    }
                    $go('material', ['id' => $d['material_id'] ?? '']);
                }]);
        }
        Hooks::addPage('inwentaryzacja', ['title' => 'Inwentaryzacja', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $user, $go): string {
                $s = $store();
                if ($ctx['post']) {
                    $n = $s->inventory((array) ($_POST['counted'] ?? []), $user($ctx));
                    flash($n > 0 ? 'Zapisano inwentaryzację: zmieniono stan ' . $n . ' materiałów.' : 'Stan bez zmian.');
                    $go('materialy');
                }
                return MaterialPages::inventory($s, $ctx);
            }]);
        Hooks::addPage('import', ['title' => 'Import do produkcji', 'menu' => false, 'role' => 'editor',
            'render' => fn(PDO $pdo, array $ctx): string => $this->importPage($store(), $ctx)]);
        Hooks::addPage('ludzie', ['title' => 'Osoby i zmiany', 'menu' => false, 'role' => 'editor',
            'render' => fn(PDO $pdo, array $ctx): string => $this->peoplePage($store(), $ctx)]);

        // ---- Wyroby
        Hooks::addPage('wyrob', ['title' => 'Wyrób', 'menu' => false, 'role' => 'viewer',
            'render' => function (PDO $pdo, array $ctx) use ($store, $prod): string {
                $s = $store();
                if (isset($_GET['nowy']) && $ctx['canEdit']) {
                    return ProductPages::form($s, $ctx, []);
                }
                $w = $s->product((string) ($_GET['id'] ?? ''));
                if ($w === null) {
                    return '<div class="flash err">Nie ma takiego wyrobu.</div>';
                }
                return isset($_GET['edytuj']) && $ctx['canEdit'] ? ProductPages::form($s, $ctx, $w) : ProductPages::card($s, $prod($s), $ctx, $this->cfg(), $w);
            }]);
        Hooks::addPage('wyrob_zapisz', ['title' => 'Wyrób', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): string {
                $s = $store();
                $id = (string) ($_POST['id'] ?? '');
                $old = $id !== '' ? $s->product($id) : null;
                if (!$ctx['post'] || ($id !== '' && $old === null)) {
                    $go('wyroby');
                }
                try {
                    $w = $s->saveProduct($old['id'] ?? null, $_POST);
                    if ($old !== null) {
                        $s->update('ext_prod_products', (string) $w['id'], ['archived' => !empty($_POST['archived']) ? 1 : 0]);
                    }
                    flash(($old === null ? 'Dodano wyrób: ' : 'Zapisano: ') . $w['name'] . '.');
                    $go('wyrob', ['id' => $w['id']]);
                } catch (\RuntimeException $e) {
                    flash($e->getMessage(), 'err');
                    return ProductPages::form($s, $ctx, $old ?? [], $_POST);
                }
                return '';
            }]);

        // ---- Partie
        Hooks::addPage('partia', ['title' => 'Partia', 'menu' => false, 'role' => 'viewer',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $prod): string {
                $s = $store();
                $b = $s->batch((string) ($_GET['id'] ?? ''));
                return $b === null ? '<div class="flash err">Nie ma takiej partii.</div>' : BatchPages::card($s, $prod($s), $ctx, $b);
            }]);
        Hooks::addPage('partia_nowa', ['title' => 'Partia', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $prod, $user, $go): string {
                $s = $store();
                $p = $prod($s);
                $id = (string) ($_POST['id'] ?? $_GET['id'] ?? '');
                $b = $id !== '' ? $s->batch($id) : null;
                if ($id !== '' && ($b === null || (int) $b['imported'] === 1 || $b['deleted_at'] !== null)) {
                    return '<div class="flash err">Tej partii nie da się edytować.</div>';
                }
                if ($ctx['post'] && empty($_POST['reload'])) {
                    try {
                        $saved = $p->save($b['id'] ?? null, $_POST, $user($ctx));
                        $st = json_decode((string) $saved['stock_json'], true) ?: [];
                        flash(((int) $saved['is_draft'] === 1 ? 'Zapisano wycenę roboczą.' : 'Zapisano partię ' . $saved['code'] . '.')
                            . (!empty($st['product_id']) && ((int) ($st['actual'] ?? 0) + (int) ($st['crm'] ?? 0)) > 0 ? ' Dołożono ' . (int) $saved['qty'] . ' szt. do produktu ' . $st['sku'] . '.' : '')
                            . (($st['note'] ?? '') !== '' ? ' Uwaga: ' . $st['note'] . '.' : ''));
                        $go('partia', ['id' => $saved['id']]);
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                return BatchPages::form($s, $p, $ctx, $b, $ctx['post'] ? $_POST : null);
            }]);
        // Podgląd numeru partii w formularzu (JSON).
        Hooks::addPage('partia_kod', ['title' => 'Numer partii', 'menu' => false, 'role' => 'editor', 'raw' => true,
            'render' => static function (PDO $pdo) use ($store, $prod): void {
                $s = $store();
                $w = $s->product((string) ($_GET['product_id'] ?? ''));
                $shift = (string) ($_GET['shift'] ?? '');
                $sh = $shift !== '' ? $s->one('SELECT code FROM ext_prod_dict WHERE kind = ? AND id = ?', ['shift', $shift]) : null;
                $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['produced_at'] ?? '')) ? (string) $_GET['produced_at'] : date('Y-m-d');
                header('Content-Type: application/json; charset=utf-8');
                $id = (string) ($_GET['id'] ?? '');
                echo json_encode(['code' => $w !== null ? $prod($s)->code($w, $date, max(1, (int) ($_GET['qty'] ?? 1)), (string) ($sh['code'] ?? ''), $id !== '' ? $id : null) : ''], JSON_UNESCAPED_UNICODE);
            }]);
        Hooks::addPage('partia_usun', ['title' => 'Partia', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $prod, $user, $go): void {
                $s = $store();
                if ($ctx['post'] && ($b = $s->batch((string) ($_POST['id'] ?? ''))) !== null && $b['deleted_at'] === null) {
                    $prod($s)->trash((string) $b['id'], $user($ctx));
                    flash('Partia ' . $b['code'] . ' w koszu (30 dni). Materiały wróciły na stan.');
                }
                $go('partie');
            }]);
        Hooks::addPage('partia_przywroc', ['title' => 'Partia', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $prod, $user, $go): void {
                $s = $store();
                if ($ctx['post'] && ($b = $s->batch((string) ($_POST['id'] ?? ''))) !== null && $b['deleted_at'] !== null) {
                    $prod($s)->restore((string) $b['id'], $user($ctx));
                    flash('Przywrócono partię ' . $b['code'] . '.');
                    $go('partia', ['id' => $b['id']]);
                }
                $go('partie', ['widok' => 'kosz']);
            }]);
        Hooks::addPage('partia_zatwierdz', ['title' => 'Partia', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $prod, $user, $go): void {
                $s = $store();
                $b = $s->batch((string) ($_POST['id'] ?? ''));
                if ($ctx['post'] && $b !== null && (int) $b['is_draft'] === 1) {
                    try {
                        $saved = $prod($s)->approve((string) $b['id'], $user($ctx));
                        flash('Wycena zatwierdzona jako partia ' . $saved['code'] . '.');
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage() . ' Otwórz „Edytuj”, żeby zapisać mimo braków.', 'err');
                    }
                }
                $go('partia', ['id' => $b['id'] ?? '']);
            }]);
        Hooks::addPage('etykieta', ['title' => 'Etykiety partii', 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => function (PDO $pdo) use ($store): void {
                $s = $store();
                $b = $s->batch((string) ($_GET['id'] ?? ''));
                header('Content-Type: text/html; charset=utf-8');
                echo $b === null ? 'Nie ma takiej partii.' : BatchPages::labels($b, ($b['product_id'] !== null ? $s->product((string) $b['product_id']) : null) ?? [], $this->cfg());
            }]);

        // ---- Testy spalania
        Hooks::addPage('test', ['title' => 'Test spalania', 'menu' => false, 'role' => 'viewer',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $user, $go): string {
                $s = $store();
                $id = (string) ($_POST['id'] ?? $_GET['id'] ?? '');
                $t = $id !== '' ? $s->test($id) : null;
                if ($id !== '' && $t === null) {
                    return '<div class="flash err">Nie ma takiego testu.</div>';
                }
                if ($ctx['post']) {
                    if ($t !== null && !empty($_POST['delete'])) {
                        TestPages::delete($s, $t);
                        flash('Usunięto test.');
                        $go('testy');
                    }
                    try {
                        $f = $_FILES['photo'] ?? null;
                        $saved = TestPages::save($s, $t, $_POST, is_array($f) ? $f : null, $user($ctx));
                        flash('Zapisano test spalania.');
                        $go('test', ['id' => $saved['id']]);
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'err');
                        return TestPages::form($s, $ctx, $t, $_POST);
                    }
                }
                return TestPages::form($s, $ctx, $t);
            }]);
        Hooks::addPage('test_zdjecie', ['title' => 'Zdjęcie z testu', 'menu' => false, 'role' => 'viewer', 'raw' => true,
            'render' => static function (PDO $pdo) use ($store): void {
                $t = $store()->test((string) ($_GET['id'] ?? ''));
                $path = $t !== null && $t['photo'] !== '' ? TestPages::dir() . '/' . basename((string) $t['photo']) : '';
                if ($path === '' || !is_file($path)) {
                    http_response_code(404);
                    echo 'Nie ma zdjęcia.';
                    return;
                }
                header('Content-Type: ' . $t['photo_mime']);
                header('Content-Length: ' . filesize($path));
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, max-age=86400');
                readfile($path);
            }]);

        // ---- Zamówienia: numer partii na karcie zamówienia i przy pakowaniu.
        Hooks::addFilter('order_view.sections', static function (array $sections, array $row) use ($store): array {
            $sections[] = ['id' => 'produkcja_partie', 'title' => '🕯 Partie produkcji', 'col' => 'right',
                'render' => static fn(array $row, array $order): string => OrderBatches::section($store(), $row, $order)];
            return $sections;
        });
        Hooks::addPage('zamowienie_partie', ['title' => 'Partie w zamówieniu', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $user): void {
                $id = (int) ($_POST['order_id'] ?? 0);
                if ($ctx['post'] && $id > 0) {
                    $n = OrderBatches::saveFromCard($store(), $id, (array) ($_POST['batch'] ?? []), $user($ctx));
                    flash($n > 0 ? 'Zapisano numery partii.' : 'Numery partii bez zmian.');
                }
                header('Location: order_view.php?id=' . $id);
                exit;
            }]);
        Hooks::addFilter('packing.order', static function (array $order) use ($store): array {
            $order['ext']['produkcja'] = OrderBatches::forPacking($store(), $order);
            return $order;
        });
        Hooks::addPackingAction('produkcja_partia', ['write' => true, 'run' => static function (int $id, array $in, array $ctx) use ($store): array {
            if ($id <= 0) {
                throw new \RuntimeException('Brak zamówienia.');
            }
            return OrderBatches::saveFromPacking($store(), $id, (int) ($in['idx'] ?? -1), (string) ($in['code'] ?? ''), (string) $ctx['user_name']);
        }]);
        Hooks::addAsset('packing', $this->path('assets/packing.js'));
    }

    // ---------------------------------------------------------------- pulpit

    private function dashboard(Store $s, array $ctx): string
    {
        $mats = $s->materials();
        $prods = $s->products();
        $html = U::style() . U::tabs('pulpit');
        if ($mats === [] && $prods === []) {
            return $html . '<div class="card" style="background:var(--ink);color:#fff;border:0"><h2 style="margin:0 0 6px;color:#fff">Zacznij od tego, co masz na półce</h2>'
                . '<p style="margin:0;opacity:.8">Dodaj materiały, a policzymy koszt każdego wyrobu i powiemy, kiedy coś się kończy.</p></div>'
                . '<div class="pr-cards">'
                . self::step(1, 'Materiały', 'Wosk, zapachy, knoty, słoiki. Wystarczy nazwa, albo wczytaj listę z pliku.', $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('material', ['nowy' => 1])) . '">+ Dodaj materiał</a> <a href="' . U::h(U::url('import')) . '">Import z pliku</a>' : '')
                . self::step(2, 'Wyroby z kosztem i ceną', 'Receptura świecy i SKU produktu w sklepie. Koszt sztuki policzy się sam.', $ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('wyrob', ['nowy' => 1])) . '">Nowy wyrób</a>' : '')
                . self::step(3, 'Partie', 'Lanie świec z numerem partii. Zdejmie materiały i doda sztuki do produktu.', $ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('import')) . '">Wczytaj Rejestr Partii</a>' : '')
                . '</div>';
        }
        $low = array_values(array_filter($mats, static fn(array $m): bool => $m['low']));
        $expiring = $s->expiring(30);
        $recent = $s->batches(['draft' => false], 8);
        $month = $s->one('SELECT COUNT(*) AS n, COALESCE(SUM(qty), 0) AS q FROM ext_prod_batches WHERE deleted_at IS NULL AND is_draft = 0 AND produced_at >= ?', [date('Y-m-d', strtotime('-30 days'))]);
        $stocks = array_column($mats, 'stock', 'id');
        $p = new Production($s, $this->cfg());

        // Do zrobienia: wyroby z SKU, których w CRM jest mniej niż „trzymaj co najmniej” (albo 0).
        $todo = [];
        foreach ($prods as $w) {
            if ($w['sku'] === '' || ($crm = $p->crmProduct((string) $w['sku'])) === null) {
                continue;
            }
            $have = array_key_exists('actual_stock', $crm) && $crm['actual_stock'] !== null ? (int) $crm['actual_stock'] : (int) $crm['pase_stock'];
            $min = $w['min_stock'] !== null ? (int) $w['min_stock'] : 0;
            if ($have <= $min) {
                $todo[] = ['w' => $w, 'have' => $have, 'need' => max(1, $min - $have + ($min === 0 ? 1 : 0)), 'make' => $s->canMake((string) $w['id'], $stocks)];
            }
        }

        $html .= '<div class="pr-cards">'
            . '<a class="stat" href="' . U::h(U::url('materialy', ['low' => 1])) . '"><div class="num">' . count($low) . '</div><div class="lbl">materiałów z niskim stanem</div></a>'
            . '<a class="stat" href="#pr-exp"><div class="num">' . count($expiring) . '</div><div class="lbl">dostaw przeterminowanych lub do 30 dni</div></a>'
            . '<a class="stat" href="#pr-todo"><div class="num">' . count($todo) . '</div><div class="lbl">wyrobów do zrobienia</div></a>'
            . '<a class="stat" href="' . U::h(U::url('partie')) . '"><div class="num">' . (int) ($month['q'] ?? 0) . '</div><div class="lbl">świec w ' . (int) ($month['n'] ?? 0) . ' partiach przez 30 dni</div></a>'
            . '</div><div class="pr-two">';

        $html .= '<div class="card" id="pr-todo"><h3 style="margin:0 0 8px">Do zrobienia</h3>';
        if ($todo === []) {
            $html .= '<p class="pr-muted" style="margin:0">Stany wyrobów są powyżej minimum. Lista bierze wyroby z SKU produktu w CRM.</p>';
        } else {
            $html .= '<ul class="pr-list">';
            foreach ($todo as $t) {
                $html .= '<li><span><a href="' . U::h(U::url('wyrob', ['id' => $t['w']['id']])) . '"><b>' . U::h($t['w']['name']) . '</b></a><br><span class="pr-small">jest ' . $t['have'] . ' szt.'
                    . ($t['make'] !== null ? ', z magazynu zrobisz ' . $t['make'] : '') . '</span></span>'
                    . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('partia_nowa', ['wyrob' => $t['w']['id']])) . '">Zrób ' . $t['need'] . '</a>' : '<b>' . $t['need'] . ' szt.</b>') . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div><div class="card"><h3 style="margin:0 0 8px">Kończy się</h3>';
        if ($low === []) {
            $html .= '<p class="pr-muted" style="margin:0">Nic poniżej minimum. Minimum ustawisz na karcie materiału.</p>';
        } else {
            $html .= '<ul class="pr-list">';
            foreach ($low as $m) {
                $html .= '<li><a href="' . U::h(U::url('material', ['id' => $m['id']])) . '">' . U::h($m['name']) . '</a><span class="pr-num"><span class="pill warn">' . U::qty($m['stock'], $m['unit']) . '</span>'
                    . ' <span class="pr-small">min. ' . U::qty($m['min_stock'], $m['unit']) . '</span></span></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div><div class="card" id="pr-exp"><h3 style="margin:0 0 8px">Daty ważności</h3>';
        if ($expiring === []) {
            $html .= '<p class="pr-muted" style="margin:0">Żadna dostawa na stanie nie traci ważności w ciągu 30 dni.</p>';
        } else {
            $html .= '<ul class="pr-list">';
            foreach ($expiring as $d) {
                $past = (string) $d['expires_at'] < date('Y-m-d');
                $html .= '<li><span><a href="' . U::h(U::url('material', ['id' => $d['material_id']])) . '">' . U::h($d['name']) . '</a>' . ($d['lot_no'] !== '' ? ' <span class="pr-small">' . U::h($d['lot_no']) . '</span>' : '')
                    . '<br><span class="pr-small">zostało ' . U::qty($d['remaining'], $d['unit']) . '</span></span><span class="pill ' . ($past ? 'bad' : 'warn') . '">' . ($past ? 'po terminie ' : 'do ') . U::date($d['expires_at']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div><div class="card"><div class="pr-top" style="margin-bottom:8px"><h3 style="margin:0">Ostatnie partie</h3>'
            . ($ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('partia_nowa')) . '">+ Nowa partia</a>' : '') . '</div>';
        if ($recent === []) {
            $html .= '<p class="pr-muted" style="margin:0">Jeszcze nie ma partii.</p>';
        } else {
            $html .= '<ul class="pr-list">';
            foreach ($recent as $b) {
                $html .= '<li><span><a class="pr-code" href="' . U::h(U::url('partia', ['id' => $b['id']])) . '">' . U::h($b['code']) . '</a><br><span class="pr-small">' . U::h($b['product_name']) . '</span></span>'
                    . '<span class="pr-num">' . (int) $b['qty'] . ' szt. · ' . U::date($b['produced_at']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div></div>';
        if ($ctx['canEdit']) {
            $html .= '<p class="pr-small" style="margin-top:16px"><a href="' . U::h(U::url('ludzie')) . '">Osoby i zmiany</a> · <a href="' . U::h(U::url('import')) . '">Import danych</a>'
                . ($ctx['isAdmin'] ? ' · <a href="plugins.php">Ustawienia wtyczki</a>' : '') . '</p>';
        }
        return $html;
    }

    private static function step(int $n, string $title, string $text, string $actions): string
    {
        return '<div class="card" style="margin:0"><div style="width:30px;height:30px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);display:flex;align-items:center;justify-content:center;font-weight:700;margin-bottom:10px">'
            . $n . '</div><h3 style="margin:0 0 6px">' . $title . '</h3><p class="pr-muted" style="margin:0 0 14px">' . $text . '</p>' . $actions . '</div>';
    }

    // ---------------------------------------------------------------- import, osoby

    private function importPage(Store $s, array $ctx): string
    {
        if ($ctx['post']) {
            try {
                $f = $_FILES['file'] ?? null;
                $text = is_array($f) && ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name'])
                    ? (string) file_get_contents((string) $f['tmp_name']) : trim((string) ($_POST['text'] ?? ''));
                if ($text === '') {
                    throw new \RuntimeException('Wybierz plik albo wklej dane.');
                }
                $imp = new Importer($s);
                if (($_POST['kind'] ?? '') === 'bne') {
                    $r = $imp->bne($text, (string) $ctx['user']);
                    flash('Zaimportowano z Rejestru Partii: ' . $r['materials'] . ' materiałów, ' . $r['deliveries'] . ' dostaw, ' . $r['products'] . ' wyrobów, '
                        . $r['batches'] . ' partii, ' . $r['people'] . ' osób, ' . $r['shifts'] . ' zmian.' . ($r['skipped'] > 0 ? ' Pominięto ' . $r['skipped'] . ' wpisów bez daty lub materiału.' : '')
                        . ' Stan materiałów wpisz teraz Inwentaryzacją.');
                    header('Location: ' . U::url('pulpit'));
                } else {
                    $r = $imp->csv($text, (string) $ctx['user']);
                    flash('Materiały z pliku: dodano ' . $r['added'] . ', zaktualizowano ' . $r['updated'] . ($r['skipped'] > 0 ? ', pominięto ' . $r['skipped'] . ' wierszy bez nazwy' : '') . '.');
                    header('Location: ' . U::url('materialy'));
                }
                exit;
            } catch (\RuntimeException $e) {
                flash($e->getMessage(), 'err');
            }
        }
        $form = static fn(string $kind, string $title, string $text, string $accept, string $ph): string => '<form class="card pr-form" method="post" enctype="multipart/form-data" action="' . U::h(U::url('import')) . '">'
            . '<h3 style="margin:0">' . $title . '</h3><p class="pr-muted" style="margin:0">' . $text . '</p>' . U::csrf($ctx) . '<input type="hidden" name="kind" value="' . $kind . '">'
            . '<label>Plik<input type="file" name="file" accept="' . $accept . '"></label><label>albo wklej treść<textarea name="text" rows="5" placeholder="' . U::h($ph) . '"></textarea></label>'
            . '<div class="pr-actions"><button class="btn" type="submit">Importuj</button></div></form>';
        return U::style() . U::tabs('materialy') . U::back(U::url('materialy'), 'Materiały i magazyn') . '<div class="pr-two">'
            . $form('csv', 'Materiały z pliku CSV (Excel)', 'Kolumny: <b>nazwa</b>; kategoria (wosk, zapach, knot, pojemnik...); jednostka (g, kg, ml, l, szt); kod; stan; cena (za cały stan); minimum; dostawca; ważne do. Wystarczy sama nazwa. W Excelu: Zapisz jako → CSV (rozdzielany średnikami).',
                '.csv,text/csv,text/plain', "nazwa;kategoria;jednostka;kod;stan;cena;minimum\nGoldenwax 464;wosk;kg;01;20;380;5")
            . $form('bne', 'Kopia z Rejestru Partii (WordPress)', 'Plik JSON z Rejestru Partii → Narzędzia → Kopia zapasowa. Przeniesie woski, zapachy, knoty, pojemniki, pracowników, zmiany, dostawy i historię partii. Ponowny import pomija to, co już jest.',
                '.json,application/json', '{"batch_records": [...], "batch_waxes": [...]}')
            . '</div>';
    }

    private function peoplePage(Store $s, array $ctx): string
    {
        if ($ctx['post']) {
            $s->saveDict('person', (array) ($_POST['person_name'] ?? []), [], (array) ($_POST['person_rate'] ?? []));
            $s->saveDict('shift', (array) ($_POST['shift_name'] ?? []), (array) ($_POST['shift_code'] ?? []), []);
            flash('Zapisano osoby i zmiany.');
            header('Location: ' . U::url('ludzie'));
            exit;
        }
        $rows = static function (string $kind, array $list, bool $code) {
            $list[] = ['name' => '', 'code' => '', 'rate' => null];
            $list[] = ['name' => '', 'code' => '', 'rate' => null];
            $h = '';
            foreach ($list as $r) {
                $h .= '<tr><td><input name="' . $kind . '_name[]" value="' . U::h($r['name']) . '" maxlength="100" style="width:100%"></td>'
                    . ($code ? '<td style="width:110px"><input name="' . $kind . '_code[]" value="' . U::h($r['code']) . '" maxlength="1" style="width:100%;text-transform:uppercase"></td>'
                        : '<td style="width:150px"><input name="' . $kind . '_rate[]" value="' . U::h($r['rate'] !== null ? U::n($r['rate']) : '') . '" inputmode="decimal" style="width:100%"></td>') . '</tr>';
            }
            return $h;
        };
        return U::style() . U::tabs('pulpit') . U::back(U::url('pulpit'), 'Pulpit produkcji')
            . '<form class="pr-form" method="post" action="' . U::h(U::url('ludzie')) . '" style="max-width:none">' . U::csrf($ctx) . '<div class="pr-two">'
            . '<div class="card"><h3 style="margin:0 0 4px">Osoby</h3><p class="pr-small" style="margin:0 0 8px">Kto robi partie. Stawka liczy koszt robocizny partii.</p>'
            . '<table><thead><tr><th>Imię i nazwisko</th><th>Stawka zł/h</th></tr></thead><tbody>' . $rows('person', $s->dict('person'), false) . '</tbody></table></div>'
            . '<div class="card"><h3 style="margin:0 0 4px">Zmiany / serie</h3><p class="pr-small" style="margin:0 0 8px">Litera trafia do numeru partii w miejsce {PC}.</p>'
            . '<table><thead><tr><th>Nazwa</th><th>Litera</th></tr></thead><tbody>' . $rows('shift', $s->dict('shift'), true) . '</tbody></table></div></div>'
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button></div></form>';
    }
}
