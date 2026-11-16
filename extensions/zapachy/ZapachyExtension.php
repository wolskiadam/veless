<?php
declare(strict_types=1);

namespace PaseExt\Zapachy;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use PaseExt\Zapachy\Ui as U;
use PDO;

/**
 * Zapachy: słownik olejków z numerem miejsca w magazynie (np. Sweet Caramel = 18) i nutami głowy, serca i bazy,
 * przeniesiony z wtyczki WordPress „Aroma Helper” (aroma-category). Zapach przypisany do produktu w Magazynie,
 * szukanie produktów po zapachu, numerze i nucie, numer zapachu przy pakowaniu, na karcie zamówienia i na liście
 * produkcji, stan olejku z wtyczki Produkcja. Opis: README.md w tym katalogu.
 */
final class ZapachyExtension extends AbstractExtension
{
    /** @var array<int,list<array<string,mixed>>> zapachy produktów z bieżącej strony Magazynu */
    private static array $pageProducts = [];

    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'zapachy',
            name: 'Zapachy',
            version: '1.0.0',
            author: 'Veless',
            description: 'Olejki zapachowe z numerem miejsca w magazynie i nutami (głowa, serce, baza). Zapach przypisany do produktu, szukanie produktów po zapachu i nucie, numer zapachu przy pakowaniu i na liście produkcji. Import z wtyczki Aroma Helper.',
            icon: '🌸',
            fields: [
                ['key' => 'import', 'label' => 'Import i eksport danych', 'type' => 'link', 'url' => Hooks::pageUrl('zapachy.import'),
                 'help' => 'Import z Aroma Helper (kopia bazy z phpMyAdmin, strona katalogu), CSV z Excela, eksport zapachów i nut do CSV.'],
                ['key' => 'guess', 'label' => 'Zapach bez przypisania', 'type' => 'select', 'default' => 'yes',
                 'options' => ['yes' => 'rozpoznawaj z wariantu i nazwy produktu', 'no' => 'pokazuj tylko przypisane do produktu'],
                 'help' => 'Przy pakowaniu, na karcie zamówienia i liście produkcji. Wariant „Zapach: Sweet Caramel” albo nazwa „Świeca Sweet Caramel”.'],
            ],
        );
    }

    public function boot(): void
    {
        $store = fn(): Store => new Store($this->pdo());
        $guess = fn(): bool => $this->setting('guess', 'yes') !== 'no';
        $go = static function (string $page, array $q = []): void {
            header('Location: ' . U::url($page, $q));
            exit;
        };
        $user = static fn(array $ctx): string => (string) $ctx['user'];

        // ---- Strony
        Hooks::addPage('lista', ['title' => 'Zapachy', 'desc' => 'Olejki, nuty i miejsca na półce', 'section' => 'Produkty', 'group' => 'catalog', 'role' => 'viewer',
            'render' => static fn(PDO $pdo, array $ctx): string => Pages::list($store(), $ctx)]);
        Hooks::addPage('nuty', ['title' => 'Nuty zapachowe', 'menu' => false, 'role' => 'viewer',
            'render' => static fn(PDO $pdo, array $ctx): string => Pages::notes($store(), $ctx)]);
        Hooks::addPage('przypisz', ['title' => 'Zapachy produktów', 'menu' => false, 'role' => 'viewer',
            'render' => static fn(PDO $pdo, array $ctx): string => Pages::assign($store(), $ctx)]);
        Hooks::addPage('import', ['title' => 'Import zapachów', 'menu' => false, 'role' => 'editor',
            'render' => static fn(PDO $pdo, array $ctx): string => Pages::import($store(), $ctx)]);
        Hooks::addPage('zapach', ['title' => 'Zapach', 'menu' => false, 'role' => 'viewer',
            'render' => static function (PDO $pdo, array $ctx) use ($store): string {
                $s = $store();
                $f = $s->fragrance((string) ($_GET['id'] ?? ''));
                return $f === null ? '<div class="flash err">Nie ma takiego zapachu.</div>' : Pages::card($s, $ctx, $f);
            }]);
        Hooks::addPage('edytuj', ['title' => 'Zapach', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store): string {
                $s = $store();
                if (isset($_GET['nowy'])) {
                    return Pages::form($s, $ctx, []);
                }
                $f = $s->fragrance((string) ($_GET['id'] ?? ''));
                return $f === null ? '<div class="flash err">Nie ma takiego zapachu.</div>' : Pages::form($s, $ctx, $f);
            }]);

        // ---- Zapis
        Hooks::addPage('zapisz', ['title' => 'Zapach', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): string {
                $s = $store();
                $id = (string) ($_POST['id'] ?? '');
                $old = $id !== '' ? $s->fragrance($id) : null;
                if (!$ctx['post'] || ($id !== '' && $old === null)) {
                    $go('lista');
                }
                try {
                    $f = $s->saveFragrance($old['id'] ?? null, $_POST);
                    // Nowe nuty do słownika (bez kategorii).
                    $known = $s->noteCategories();
                    foreach (Store::fragranceNotes($f) as $n) {
                        if (!isset($known[$n]) && mb_strlen($n) <= 100) {
                            $s->saveNote($n, '');
                        }
                    }
                    flash(($old === null ? 'Dodano zapach: ' : 'Zapisano: ') . Store::label($f) . '.');
                    $go('zapach', ['id' => $f['id']]);
                } catch (\RuntimeException $e) {
                    flash($e->getMessage(), 'err');
                    return Pages::form($s, $ctx, $old ?? [], $_POST);
                }
                return '';
            }]);
        Hooks::addPage('archiwizuj', ['title' => 'Zapach', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                $s = $store();
                $f = $s->fragrance((string) ($_POST['id'] ?? ''));
                if ($ctx['post'] && $f !== null) {
                    $on = (int) ($_POST['archive'] ?? 0) === 1;
                    if (!$on && trim((string) $f['storage_no']) !== ''
                        && $s->one('SELECT id FROM ext_zap_fragrances WHERE LOWER(storage_no) = LOWER(?) AND archived = 0 AND id <> ?', [$f['storage_no'], $f['id']]) !== null) {
                        flash('Numer ' . $f['storage_no'] . ' ma już inny zapach. Zmień numer, zanim przywrócisz ten zapach.', 'err');
                    } else {
                        $s->archive((string) $f['id'], $on);
                        flash($on ? 'Zapach przeniesiony do archiwum.' : 'Zapach przywrócony.');
                    }
                }
                $go('zapach', ['id' => $f['id'] ?? '']);
            }]);
        Hooks::addPage('usun', ['title' => 'Zapach', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                $s = $store();
                $f = $s->fragrance((string) ($_POST['id'] ?? ''));
                if ($ctx['post'] && $f !== null) {
                    $s->deleteFragrance((string) $f['id']);
                    flash('Usunięto zapach ' . Store::label($f) . '.');
                }
                $go('lista');
            }]);
        Hooks::addPage('odlacz', ['title' => 'Zapach', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                $id = (string) ($_POST['id'] ?? '');
                if ($ctx['post']) {
                    $store()->unlink($id, (int) ($_POST['product_id'] ?? 0));
                    flash('Odłączono produkt.');
                }
                $go('zapach', ['id' => $id]);
            }]);
        Hooks::addPage('przypisz_zapisz', ['title' => 'Zapachy produktów', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go, $user): void {
                $s = $store();
                $fid = (string) ($_POST['fragrance_id'] ?? '');
                $n = 0;
                if ($ctx['post']) {
                    if ($fid !== '') {
                        $n = $s->link($fid, (array) ($_POST['products'] ?? []), $user($ctx));
                    }
                    foreach ((array) ($_POST['pairs'] ?? []) as $pair) {
                        [$pid, $f] = array_pad(explode(':', (string) $pair, 2), 2, '');
                        $n += $s->link($f, [(int) $pid], $user($ctx));
                    }
                    flash($n > 0 ? 'Przypisano zapach do ' . $n . ' ' . Pages::plural($n, 'produktu', 'produktów', 'produktów') . '.' : 'Nic nie zmieniono.');
                }
                ($_POST['back'] ?? '') === 'zapach' && $fid !== '' ? $go('zapach', ['id' => $fid]) : $go('przypisz');
            }]);
        Hooks::addPage('produkt_zapisz', ['title' => 'Zapach produktu', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $user): void {
                $pid = (int) ($_POST['product_id'] ?? 0);
                if ($ctx['post'] && $pid > 0) {
                    $s = $store();
                    $s->setProductFragrances($pid, (array) ($_POST['fragrances'] ?? []), $user($ctx));
                    $now = $s->forProducts([$pid])[$pid] ?? [];
                    flash($now === [] ? 'Produkt nie ma już zapachu.' : 'Zapach produktu: ' . implode(', ', array_map([Store::class, 'label'], $now)) . '.');
                }
                header('Location: product_view.php?id=' . $pid);
                exit;
            }]);
        Hooks::addPage('nuta_zapisz', ['title' => 'Nuty', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                if ($ctx['post']) {
                    try {
                        $store()->saveNote((string) ($_POST['name'] ?? ''), (string) ($_POST['category'] ?? ''));
                        flash('Zapisano nutę.');
                    } catch (\RuntimeException $e) {
                        flash($e->getMessage(), 'err');
                    }
                }
                $go('nuty');
            }]);
        Hooks::addPage('nuta_usun', ['title' => 'Nuty', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                if ($ctx['post']) {
                    $store()->deleteNote((string) ($_POST['id'] ?? ''));
                    flash('Usunięto nutę ze słownika (zapachy jej nie tracą).');
                }
                $go('nuty');
            }]);
        Hooks::addPage('nuty_dodaj', ['title' => 'Nuty', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                if ($ctx['post']) {
                    $r = $store()->bulkNotes((string) ($_POST['bulk'] ?? ''));
                    flash('Dodano ' . $r['added'] . ' nut, bez zmian ' . $r['skipped'] . '.' . ($r['errors'] !== [] ? ' ' . implode(' ', array_slice($r['errors'], 0, 5)) : ''),
                        $r['errors'] !== [] && $r['added'] === 0 ? 'err' : 'ok');
                }
                $go('nuty');
            }]);
        Hooks::addPage('import_zapisz', ['title' => 'Import zapachów', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                if (!$ctx['post']) {
                    $go('import');
                }
                $s = $store();
                $imp = new Importer($s);
                try {
                    if (($_POST['mode'] ?? '') === 'url') {
                        $r = $imp->url((string) ($_POST['url'] ?? ''));
                    } else {
                        $file = $_FILES['file'] ?? null;
                        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                            throw new \RuntimeException('Wybierz plik do importu.');
                        }
                        if ((int) $file['size'] > 30 * 1024 * 1024) {
                            throw new \RuntimeException('Plik jest za duży (najwyżej 30 MB). Wyeksportuj tylko tabele aroma_fragrances i aroma_notes.');
                        }
                        $r = $imp->file((string) file_get_contents((string) $file['tmp_name']), (string) $file['name']);
                    }
                    $linked = $s->linkProductionByName();
                    $msg = 'Import: nowe zapachy ' . $r['added'] . ', zaktualizowane ' . $r['updated'] . ', bez zmian ' . $r['skipped'] . ', nuty dodane do słownika ' . $r['notes'] . '.'
                        . (($r['links'] ?? 0) > 0 ? ' Przypisano do produktów: ' . $r['links'] . '.' : '')
                        . ($linked > 0 ? ' Połączono z olejkami z Produkcji: ' . $linked . '.' : '');
                    if ($r['warnings'] !== []) {
                        $msg .= ' Uwagi: ' . implode(' ', array_slice($r['warnings'], 0, 8)) . (count($r['warnings']) > 8 ? ' (i ' . (count($r['warnings']) - 8) . ' więcej)' : '');
                    }
                    flash($msg);
                    $go('lista');
                } catch (\RuntimeException $e) {
                    flash($e->getMessage(), 'err');
                    $go('import');
                }
            }]);
        foreach (['eksport' => 'zapachy', 'eksport_nuty' => 'nuty'] as $page => $what) {
            Hooks::addPage($page, ['title' => 'Eksport CSV', 'menu' => false, 'role' => 'viewer', 'raw' => true,
                'render' => static function (PDO $pdo) use ($store, $what): void {
                    $e = new Exporter($store());
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $what . '-' . date('Y-m-d') . '.csv"');
                    header('Cache-Control: no-store');
                    echo $what === 'zapachy' ? $e->fragrances() : $e->notes();
                }]);
        }
        Hooks::addPage('polacz_produkcja', ['title' => 'Import zapachów', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($store, $go): void {
                if ($ctx['post']) {
                    $n = $store()->linkProductionByName();
                    flash($n > 0 ? 'Połączono ' . $n . ' zapachów z olejkami z Produkcji.' : 'Nie znalazłem nowych par (nazwa olejku w Produkcji musi zawierać nazwę zapachu). Połącz ręcznie w edycji zapachu.');
                }
                $go('import');
            }]);

        // ---- Magazyn: szukanie po zapachu, plakietka przy nazwie, sekcja na karcie produktu.
        Hooks::addFilter('products.search', static function (array $ids, string $q) use ($store): array {
            return array_values(array_unique(array_merge($ids, $store()->productIdsMatching($q))));
        });
        Hooks::addAction('products.rows', static function (array $rows) use ($store): void {
            self::$pageProducts = $store()->forProducts(array_column($rows, 'id'));
        });
        Hooks::addAction('products.name', static function (array $row): string {
            $list = self::$pageProducts[(int) ($row['id'] ?? 0)] ?? [];
            static $styled = false;
            if ($list === []) {
                return '';
            }
            $out = $styled ? '' : U::chipStyle();
            $styled = true;
            return $out . '<div>' . implode('', array_map(static fn(array $f) => U::chip($f), $list)) . '</div>';
        });
        Hooks::addFilter('product_view.sections', static function (array $sections, array $product) use ($store): array {
            $sections[] = ['id' => 'zapachy', 'title' => '🌸 Zapach',
                'render' => static fn(array $p, array $ctx): string => Pages::productSection($store(), $ctx, $p)];
            return $sections;
        });

        // ---- Zamówienia: karta zamówienia, pakowanie, lista produkcji.
        Hooks::addFilter('order_view.sections', static function (array $sections) use ($store, $guess): array {
            $sections[] = ['id' => 'zapachy', 'title' => '🌸 Zapachy do zrobienia', 'col' => 'right',
                'render' => static function (array $row) use ($store, $guess): string {
                    $s = $store();
                    $items = \Pase\Services\OrderMerge::items($row);
                    if (!$guess()) {
                        $items = self::onlyLinked($s, $items);
                    }
                    return Pages::orderSection($s, $items);
                }];
            return $sections;
        });
        Hooks::addFilter('packing.order', static function (array $order) use ($store, $guess): array {
            $s = $store();
            $out = [];
            foreach ($order['items'] ?? [] as $it) {
                $frags = $s->resolve((string) ($it['sku'] ?? ''), Store::parseAttrs((string) ($it['attrs'] ?? '')), (string) ($it['name'] ?? ''));
                if (!$guess()) {
                    $frags = array_values(array_filter($frags, static fn(array $f) => $f['how'] === 'product'));
                }
                if ($frags !== []) {
                    $out[(string) $it['idx']] = array_map(static fn(array $f) => ['no' => (string) $f['storage_no'], 'name' => (string) $f['name']], $frags);
                }
            }
            $order['ext']['zapachy'] = (object) $out;
            return $order;
        });
        Hooks::addAsset('packing', $this->path('assets/packing.js'));
        Hooks::addFilter('production_list.details', static function (array $details, string $sku, int|float $qty = 0, array $row = []) use ($store, $guess): array {
            $s = $store();
            $frags = $s->resolve($sku, (array) ($row['attrs'] ?? []), (string) ($row['name'] ?? ''));
            if (!$guess()) {
                $frags = array_values(array_filter($frags, static fn(array $f) => $f['how'] === 'product'));
            }
            foreach ($frags as $f) {
                $details[] = ['label' => 'Nr zapachu', 'value' => (trim((string) $f['storage_no']) !== '' ? $f['storage_no'] . ' · ' : '') . $f['name']];
            }
            return $details;
        }, 20);
    }

    /** Pozycje, których produkt (po SKU) ma przypisany zapach. */
    private static function onlyLinked(Store $s, array $items): array
    {
        $ids = $s->productIdsBySku(array_column($items, 'sku'));
        $linked = $s->forProducts(array_values($ids));
        return array_values(array_filter($items, static fn(array $it) => isset($linked[$ids[mb_strtolower(trim((string) ($it['sku'] ?? '')))] ?? 0])));
    }
}
