<?php
declare(strict_types=1);

namespace PaseExt\Przyklad;

use Pase\Plugin\AbstractExtension;
use Pase\Plugin\ExtensionManifest;
use Pase\Plugin\Hooks;
use PDO;

/**
 * Przykładowe rozszerzenie: notatki wewnętrzne do zamówień. Pokazuje każdy rodzaj haka:
 *  - lista zamówień: kolumna „Sztuk”, ikona notatki, przycisk na pasku akcji,
 *  - karta zamówienia: sekcja z notatką i formularzem (zapis przez własną stronę),
 *  - własna strona w menu (Zamówienia → Notatki wewnętrzne),
 *  - automatyzacje: akcja „Dopisz notatkę wewnętrzną”,
 *  - pakowanie: notatka nad przyciskami i przycisk „📝 Notatka” (akcja serwera),
 *  - statystyki: kafelek z liczbą notatek w zakresie dat,
 *  - wygląd: plik CSS na wszystkich stronach panelu.
 *
 * Instalacja: skopiuj katalog do extensions/przyklad/ (albo spakuj do ZIP i wgraj w Konfiguracja → Wtyczki).
 */
final class PrzykladExtension extends AbstractExtension
{
    public function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            type: 'przyklad',
            name: 'Notatki wewnętrzne (przykład)',
            version: '1.0.0',
            author: 'Veless',
            description: 'Przykład wtyczki rozszerzającej: notatki zespołu przy zamówieniach - na liście, w karcie, w pakowaniu i w automatyzacjach.',
            icon: '📝',
            fields: [
                ['key' => 'items_column', 'label' => 'Pokazuj kolumnę „Sztuk” na liście zamówień', 'type' => 'checkbox', 'default' => '1'],
                ['key' => 'column_after', 'label' => 'Kolumna „Sztuk” za kolumną', 'type' => 'select', 'default' => 'status',
                 'options' => ['number' => 'Numer', 'status' => 'Status', 'client' => 'Klient', 'amount' => 'Kwota']],
            ]
        );
    }

    public function boot(): void
    {
        $notes = fn(): Notes => new Notes($this->pdo());

        // ---- Lista zamówień: nowa kolumna wstawiona za wybraną kolumną rdzenia.
        if ($this->setting('items_column', '1') === '1') {
            $after = (string) $this->setting('column_after', 'status');
            Hooks::addFilter('orders.columns', static function (array $cols) use ($after): array {
                $new = ['x_items' => ['label' => 'Sztuk', 'class' => 'col-amt', 'cell' => static function (array $row): string {
                    $p = json_decode((string) ($row['payload'] ?? ''), true);
                    $qty = 0;
                    foreach ((array) ($p['line_items'] ?? $p['lineItems'] ?? []) as $it) {
                        $qty += (int) ($it['quantity'] ?? 0);
                    }
                    return (string) $qty;
                }]];
                $pos = array_search($after, array_keys($cols), true);
                return $pos === false ? $cols + $new
                    : array_slice($cols, 0, $pos + 1, true) + $new + array_slice($cols, $pos + 1, null, true);
            });
        }

        // ---- Lista zamówień: ikona przy statusie, gdy zamówienie ma notatkę (kolor w assets/admin.css).
        // orders.rows daje całą stronę listy - jedna kwerenda zamiast jednej na wiersz.
        $listNotes = [];
        Hooks::addAction('orders.rows', static function (array $rows) use ($notes, &$listNotes): void {
            $listNotes = $notes()->forOrders(array_column($rows, 'woo_order_id'));
        });
        Hooks::addFilter('orders.indicators', static function (array $ind, array $row) use (&$listNotes): array {
            $note = $listNotes[(int) $row['woo_order_id']] ?? null;
            if ($note !== null) {
                $ind['x_note'] = ['state' => 'info', 'literal' => true, 'label' => 'Notatka: ' . mb_strimwidth($note, 0, 80, '…'),
                    'icon' => '<path d="M5 4h10l4 4v12H5z"/><path d="M9 12h6M9 16h4"/>'];
            }
            return $ind;
        });

        // ---- Lista zamówień: przycisk na pasku akcji (działa na zaznaczonych wierszach).
        Hooks::addAction('orders.actionbar', static function (string $view): void {
            if ($view !== 'active') {
                return;
            }
            echo '<button type="button" class="btn secondary" title="Notatki zaznaczonych zamówień"'
                . ' onclick="var ids=[].map.call(document.querySelectorAll(\'.rowcheck:checked\'),function(c){return c.value});'
                . 'location.href=\'' . Hooks::pageUrl('przyklad.notatki') . '&ids=\'+ids.join(\',\');">📝 Notatki</button>';
        });

        // ---- Karta zamówienia: sekcja z notatką i formularzem.
        Hooks::addFilter('order_view.sections', static function (array $sections) use ($notes): array {
            $sections[] = ['id' => 'przyklad_note', 'title' => '📝 Notatka wewnętrzna', 'col' => 'right',
                'render' => static function (array $row) use ($notes): string {
                    $id = (int) $row['woo_order_id'];
                    $n = $notes()->get($id);
                    $html = $n ? '<p style="white-space:pre-wrap;margin:0 0 6px">' . htmlspecialchars($n['note']) . '</p>'
                        . '<p style="color:var(--ink-3);font-size:12px;margin:0 0 10px">' . htmlspecialchars((string) $n['updated_by']) . ', ' . htmlspecialchars((string) $n['updated_at']) . ' UTC</p>'
                        : '<p style="color:var(--ink-2);margin:0 0 10px">Brak notatki. Widzi ją tylko zespół, nie klient.</p>';
                    if (function_exists('roleCanEdit') && roleCanEdit()) {
                        $html .= '<form method="post" action="' . htmlspecialchars(Hooks::pageUrl('przyklad.zapisz')) . '">'
                            . '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfToken()) . '">'
                            . '<input type="hidden" name="id" value="' . $id . '">'
                            . '<textarea name="note" rows="3" style="width:100%">' . htmlspecialchars((string) ($n['note'] ?? '')) . '</textarea>'
                            . '<button class="btn secondary" type="submit" style="margin-top:8px">Zapisz notatkę</button></form>';
                    }
                    return $html;
                }];
            return $sections;
        });

        // ---- Zapis z karty zamówienia: strona bez pozycji w menu, wraca do karty (PRG).
        Hooks::addPage('zapisz', ['title' => 'Zapis notatki', 'menu' => false, 'role' => 'editor',
            'render' => static function (PDO $pdo, array $ctx) use ($notes): void {
                $id = (int) ($_POST['id'] ?? 0);
                if ($ctx['post'] && $id > 0) {
                    $notes()->save($id, (string) ($_POST['note'] ?? ''), (string) $ctx['user']);
                    flash('Zapisano notatkę wewnętrzną.');
                }
                header('Location: order_view.php?id=' . $id);
                exit;
            }]);

        // ---- Własna strona w menu Zamówienia.
        Hooks::addPage('notatki', ['title' => '📝 Notatki wewnętrzne', 'group' => 'orders', 'role' => 'viewer',
            'render' => static function (PDO $pdo) use ($notes): string {
                $only = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
                $list = $notes()->latest(200);
                if ($only) {
                    $list = array_values(array_filter($list, static fn($n) => in_array((int) $n['woo_order_id'], $only, true)));
                }
                $out = '<div class="card"><table><tr><th>Zamówienie</th><th>Notatka</th><th>Kto</th><th>Kiedy (UTC)</th></tr>';
                foreach ($list as $n) {
                    $out .= '<tr><td><a href="order_view.php?id=' . (int) $n['woo_order_id'] . '">#' . (int) $n['woo_order_id'] . '</a></td>'
                        . '<td style="white-space:pre-wrap">' . htmlspecialchars($n['note']) . '</td>'
                        . '<td>' . htmlspecialchars((string) $n['updated_by']) . '</td><td>' . htmlspecialchars((string) $n['updated_at']) . '</td></tr>';
                }
                if ($list === []) {
                    $out .= '<tr><td colspan="4" style="color:var(--ink-2)">Brak notatek' . ($only ? ' w zaznaczonych zamówieniach' : '') . '.</td></tr>';
                }
                return $out . '</table></div>';
            }]);

        // ---- Automatyzacje: nowa akcja w regułach.
        Hooks::addFilter('automation.actions', static function (array $actions) use ($notes): array {
            $actions['x_przyklad_note'] = ['label' => 'Dopisz notatkę wewnętrzną', 'params' => ['text' => 'Treść notatki'],
                'hint' => 'Zastępuje notatkę zamówienia (rozszerzenie „Notatki wewnętrzne”).',
                'run' => static function (int $orderId, array $params) use ($notes): void {
                    $notes()->save($orderId, (string) ($params['text'] ?? ''), 'Automatyzacja');
                }];
            return $actions;
        });

        // ---- Pakowanie: notatka w danych zamówienia, skrypt ją pokazuje i pozwala dopisać.
        Hooks::addFilter('packing.order', static function (array $order) use ($notes): array {
            $order['ext']['przyklad'] = ['note' => $notes()->get((int) $order['id'])['note'] ?? ''];
            return $order;
        });
        Hooks::addPackingAction('przyklad_note', ['run' => static function (int $id, array $in, array $ctx) use ($notes): array {
            if ($id <= 0) {
                throw new \RuntimeException('Brak zamówienia.');
            }
            $notes()->save($id, (string) ($in['note'] ?? ''), (string) $ctx['user_name']);
            return ['note' => $notes()->get($id)['note'] ?? ''];
        }]);
        Hooks::addAsset('packing', $this->path('assets/packing.js'));

        // ---- Statystyki: kafelek z liczbą notatek w wybranym zakresie dat.
        Hooks::addFilter('statistics.cards', static function (array $cards, array $ctx) use ($notes): array {
            $cards[] = ['title' => 'Notatki wewnętrzne', 'render' => static fn(): string =>
                '<div class="mg-num">' . $notes()->countBetween($ctx['from'], $ctx['to']) . '</div>'
                . '<div class="mg-sub">zapisanych lub zmienionych w zakresie dat</div>'];
            return $cards;
        });

        // ---- Wygląd: własny CSS na wszystkich stronach panelu.
        Hooks::addAsset('admin', $this->path('assets/admin.css'));
    }
}
