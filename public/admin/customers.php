<?php
declare(strict_types=1);

/**
 * Klienci — lista klientów i karta klienta, zbudowane z zamówień (sklepy WooCommerce i Allegro).
 *
 * Widoki:
 *   customers.php            lista (szukaj, sortuj, tylko powracający)
 *   customers.php?id=<id>    karta klienta: ile razy kupował, za ile, na jakich kanałach, zamówienia,
 *                            zwroty, dyskusje i oceny Allegro; ręczne połączenie z innym klientem
 *   customers.php?order=<woo_order_id>   karta klienta danego zamówienia (link ze strony zamówienia)
 *
 * Jak zamówienia łączą się w klienta — patrz Services\Customers. Brak eksportu: dane zostają w panelu.
 */

use Pase\Repository\OrderStatusRepository;
use Pase\Services\Customers;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor', 'viewer']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$customers = new Customers($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
        redirectAfterPost();
    }
    $action = (string) ($_POST['action'] ?? '');
    $cid = (int) ($_POST['customer_id'] ?? 0);
    $back = $cid > 0 ? 'customers.php?id=' . $cid : 'customers.php';
    try {
        switch ($action) {
            case 'merge':
                $other = $customers->findByIdentifier((string) ($_POST['other'] ?? ''));
                if ($other === null) {
                    throw new RuntimeException('Nie znaleziono klienta o takim e-mailu, telefonie, loginie Allegro ani numerze zamówienia.');
                }
                if ($other === $cid) {
                    throw new RuntimeException('To zamówienie / dane należą już do tego klienta.');
                }
                $id = $customers->mergeManually($cid, $other, currentUserName());
                $back = 'customers.php?id=' . $id;
                flash('Połączono klientów. Połączenie zostaje także po przeliczeniu; możesz je cofnąć niżej.');
                break;
            case 'unmerge':
                $ids = $customers->unmerge((int) ($_POST['merge_id'] ?? 0));
                $back = $ids !== [] ? 'customers.php?id=' . $ids[0] : 'customers.php';
                flash('Cofnięto połączenie.' . (count($ids) > 1 ? ' Zamówienia rozdzieliły się na ' . count($ids) . ' klientów.' : ''));
                break;
            case 'anonymize':
                if (!isAdmin()) {
                    throw new RuntimeException('Dane klienta może usunąć administrator.');
                }
                if (($_POST['confirm'] ?? '') !== '1') {
                    throw new RuntimeException('Zaznacz potwierdzenie przed usunięciem danych.');
                }
                $customers->anonymize($cid);
                flash('Usunięto dane osobowe klienta z kart klientów.');
                break;
            case 'rebuild':
                if (!isAdmin()) {
                    throw new RuntimeException('Przeliczenie klientów może uruchomić administrator.');
                }
                $customers->resetAll();
                $r = $customers->syncPending(20.0);
                flash($r['pending'] > 0
                    ? 'Przeliczam klientów: zostało ' . $r['pending'] . ' zamówień, dokończy się przy kolejnych wejściach na tę stronę.'
                    : 'Przeliczono klientów od nowa (historia, ręczne połączenia i usunięte dane zostały).');
                $back = 'customers.php';
                break;
            default:
                throw new RuntimeException('Nieznana akcja.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirectAfterPost($back);
}

// Link ze strony zamówienia: przypisuje tylko to jedno zamówienie, bez układania całej historii.
if (isset($_GET['order'])) {
    $cid = $customers->customerIdForOrder((int) $_GET['order']);
    header('Location: ' . ($cid !== null ? 'customers.php?id=' . $cid : 'order_view.php?id=' . (int) $_GET['order']));
    exit;
}

// Nowe zamówienia dopisujemy przy każdym wejściu (pierwsze wejście układa całą historię, w limicie czasu).
$sync = ['assigned' => 0, 'pending' => 0, 'busy' => false];
try {
    $sync = $customers->syncPending(8.0);
} catch (Throwable $e) {
    \Pase\Support\Logger::warn('Klienci: przypisanie zamówień nie powiodło się - ' . $e->getMessage());
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($v, string $cur = 'PLN'): string => number_format((float) $v, 2, ',', ' ') . ' ' . ($cur === 'PLN' ? 'zł' : $cur);
$totals = static function (array $t) use ($money): string {
    if ($t === []) {
        return $money(0);
    }
    $parts = [];
    foreach ($t as $cur => $v) {
        $parts[] = $money($v, (string) $cur);
    }
    return implode(' + ', $parts);
};
$day = static fn(?string $d): string => $d ? substr($d, 0, 10) : '—';
$phoneFmt = static fn(string $p): string => strlen($p) === 9 ? substr($p, 0, 3) . ' ' . substr($p, 3, 3) . ' ' . substr($p, 6) : $p;

$id = (int) ($_GET['id'] ?? 0);
$customer = $id > 0 ? $customers->find($id) : null;

$PAGE_TITLE = $customer ? ($customer['name'] ?: ((int) $customer['anonymized'] === 1 ? 'Klient (dane usunięte)' : 'Klient bez nazwiska')) : 'Klienci';
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>
<style>
.cu-muted{color:var(--ink-2);font-size:13px}
.cu-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.cu-tiles{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin:14px 0 4px}
.cu-tile{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px 14px}
.cu-tile b{display:block;font-size:22px;font-family:var(--font-num);font-weight:600;margin-top:2px}
.cu-tile span{color:var(--ink-2);font-size:12px;text-transform:uppercase;letter-spacing:.04em;font-weight:700}
.cu-chip{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;background:var(--muted-bg);color:var(--muted-ink);margin:2px 4px 2px 0;white-space:nowrap}
.cu-chip.allegro{background:#fff0e6;color:#b24d00}
.cu-scroll{overflow-x:auto}
.cu-ids{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:6px}
.cu-num{font-family:var(--font-num);text-align:right;white-space:nowrap}
.cu-note{background:var(--warn-bg);color:var(--warn-ink);border-radius:var(--radius-sm);padding:10px 12px;margin-bottom:14px;font-size:14px}
.cu-bar{height:6px;border-radius:3px;background:var(--accent);min-width:2px}
/* Linki jak na liście zamówień: kolor tekstu, delikatne podkreślenie, akcent po najechaniu. */
.cu a{color:inherit;text-decoration:underline;text-decoration-color:#cbd3e1;text-underline-offset:3px}
.cu a:hover{color:var(--accent);text-decoration-color:currentColor}
.cu a:focus-visible{outline:2px solid var(--accent);outline-offset:3px;border-radius:2px}
.cu a.cu-name{text-decoration:none;font-weight:700}
.cu a.cu-name:hover{text-decoration:underline}
.cu a.cu-back{text-decoration:none;color:var(--ink-2);font-size:14px;font-weight:600}
.cu a.cu-back:hover{color:var(--accent)}
.cu-gone{color:var(--ink-2)}
.cu-danger{border:1px solid var(--danger-bg)}
</style>
<div class="cu">

<?php if ($sync['pending'] > 0): ?>
    <div class="cu-note">Układam klientów z historii zamówień: zostało <?= (int) $sync['pending'] ?> zamówień. Odśwież stronę, żeby dokończyć<?= $sync['busy'] ? ' (trwa w innej karcie)' : '' ?>.</div>
<?php endif; ?>

<?php if ($id > 0 && $customer === null): ?>
    <div class="card"><p>Nie ma takiego klienta (mógł zostać połączony z innym albo przeliczony). <a href="customers.php">Wróć do listy</a>.</p></div>

<?php elseif ($customer !== null):
    // ============================================================
    //  Karta klienta
    // ============================================================
    $orders = $customers->ordersOf($id);
    $statusMap = (new OrderStatusRepository($pdo))->map();
    $orderIds = array_map(static fn($o) => (int) $o['woo_order_id'], $orders);
    $logins = array_values(array_map(static fn($k) => $k['label'], array_filter($customer['keys'], static fn($k) => $k['kind'] === 'allegro')));
    $byOrder = [];
    foreach ($orders as $o) {
        $byOrder[(int) $o['woo_order_id']] = $o;
    }

    $returns = $issues = $ratings = [];
    if ($orderIds !== []) {
        $in = implode(',', $orderIds);
        try {
            $returns = $pdo->query("SELECT id, woo_order_id, reference_number, status, items, refund_state, created_at, remote_created_at FROM order_returns WHERE woo_order_id IN ($in) ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $ex) {
            $returns = [];
        }
    }
    $fbWhere = [];
    $fbParams = [];
    if ($orderIds !== []) {
        $fbWhere[] = 'woo_order_id IN (' . implode(',', $orderIds) . ')';
    }
    if ($logins !== []) {
        $fbWhere[] = 'buyer_login IN (' . implode(',', array_fill(0, count($logins), '?')) . ')';
        $fbParams = $logins;
    }
    if ($fbWhere !== []) {
        try {
            $s = $pdo->prepare('SELECT * FROM allegro_issues WHERE ' . implode(' OR ', $fbWhere) . ' ORDER BY id DESC');
            $s->execute($fbParams);
            $issues = $s->fetchAll(PDO::FETCH_ASSOC);
            $s = $pdo->prepare('SELECT * FROM allegro_ratings WHERE ' . implode(' OR ', $fbWhere) . ' ORDER BY id DESC');
            $s->execute($fbParams);
            $ratings = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $ex) {
            $issues = $ratings = [];
        }
    }
    $merges = $customers->mergesOf($id);
    $orderLink = static function (?array $o) use ($e): string {
        if ($o === null) {
            return '—';
        }
        $label = '#' . $e($o['pase_number'] ?? $o['woo_order_id']);
        if (isset($o['in_crm']) && (int) $o['in_crm'] === 0) {
            // Zamówienie skasowane z CRM (archiwum po roku) — zostaje w historii klienta bez linku.
            return '<span class="cu-gone" title="Zamówienie usunięte z CRM, zostało w historii klienta">' . $label . '</span>';
        }
        return '<a href="order_view.php?id=' . (int) $o['woo_order_id'] . '">' . $label . '</a>';
    };
    $spentCount = $customer['orders_count'] > 0 && count($customer['totals']) === 1 && isset($customer['totals']['PLN']);
    $maxCh = max(1, ...array_values(array_map(static fn($c) => (int) $c["count"], $customer["channels"] ?: [["count" => 1]])));
    ?>
    <p><a class="cu-back" href="customers.php">← Klienci</a></p>
    <?php if ((int) $customer['anonymized'] === 1): ?>
        <div class="cu-note">Dane osobowe tego klienta zostały usunięte na jego prośbę. Zostały tylko liczby: ile zamówień, kiedy, gdzie i za ile.</div>
    <?php endif; ?>
    <div class="card">
        <div class="cu-head">
            <div>
                <div class="cu-ids" style="margin-top:0">
                    <?php foreach ($customer['keys'] as $k): ?>
                        <?php if ($k['kind'] === 'email'): ?><span>✉ <?= $e($k['label']) ?></span>
                        <?php elseif ($k['kind'] === 'phone'): ?><span>☎ <?= $e($phoneFmt($k['label'])) ?></span>
                        <?php else: ?><span><span class="cu-chip allegro">Allegro</span><?= $e($k['label']) ?></span><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($customer['keys'] === [] && (int) $customer['anonymized'] === 0): ?><span class="cu-muted">Zamówienie bez e-maila, telefonu i konta Allegro — nie da się go dopasować do innych.</span><?php endif; ?>
                </div>
            </div>
            <?php if ((int) $customer['orders_count'] >= 2): ?><span class="pill ok">Powracający klient</span><?php endif; ?>
        </div>
        <div class="cu-tiles">
            <div class="cu-tile"><span>Zamówienia</span><b><?= (int) $customer['orders_count'] ?></b><?php if ((int) $customer['cancelled_count'] > 0): ?><div class="cu-muted">+ <?= (int) $customer['cancelled_count'] ?> anulowane</div><?php endif; ?></div>
            <div class="cu-tile"><span>Wydał łącznie</span><b><?= $e($totals($customer['totals'])) ?></b></div>
            <?php if ($spentCount): ?><div class="cu-tile"><span>Średnie zamówienie</span><b><?= $e($money($customer['totals']['PLN'] / (int) $customer['orders_count'])) ?></b></div><?php endif; ?>
            <div class="cu-tile"><span>Pierwsze zamówienie</span><b style="font-size:17px"><?= $e($day($customer['first_order_at'])) ?></b></div>
            <div class="cu-tile"><span>Ostatnie zamówienie</span><b style="font-size:17px"><?= $e($day($customer['last_order_at'])) ?></b></div>
        </div>
        <p class="cu-muted" style="margin:6px 0 0">Kwota to suma zamówień bez anulowanych i bez zamówień w statusie „Zwrot”; częściowe zwroty są niżej.</p>
    </div>

    <div class="card">
        <strong>Kanały sprzedaży</strong>
        <?php if ($customer['channels'] === []): ?>
            <p class="cu-muted">Brak zamówień.</p>
        <?php else: ?>
            <div class="cu-scroll"><table>
                <tr><th>Kanał</th><th style="text-align:right">Zamówienia</th><th></th><th style="text-align:right">Wydał</th><th>Ostatnio</th></tr>
                <?php foreach ($customer['channels'] as $ch): ?>
                    <tr>
                        <td><?= $e($ch['label']) ?></td>
                        <td class="cu-num"><?= (int) $ch['count'] ?></td>
                        <td style="width:30%"><div class="cu-bar" style="width:<?= round(100 * (int) $ch['count'] / $maxCh) ?>%"></div></td>
                        <td class="cu-num"><?= $e($totals($ch['spent'])) ?></td>
                        <td><?= $e($day($ch['last'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Zamówienia (<?= count($orders) ?>)</strong>
        <div class="cu-scroll"><table>
            <tr><th>Zamówienie</th><th>Data</th><th>Kanał</th><th>Nr w kanale</th><th>Status</th><th style="text-align:right">Wartość</th></tr>
            <?php foreach ($orders as $o):
                $st = $statusMap[$o['pase_status'] ?? ''] ?? null; ?>
                <tr>
                    <td><?= $orderLink($o) ?></td>
                    <td><?= $e(substr((string) $o['date_created'], 0, 16)) ?></td>
                    <td><?= $e($o['channel_label']) ?></td>
                    <td class="cu-muted"><?= $e($o['order_number'] ?? '') ?></td>
                    <td><?php if ($st): ?><span class="pill" style="background:<?= $e($st['color'] ?? '#eee') ?>22;color:<?= $e($st['color'] ?? '#444') ?>"><?= $e($st['label'] ?? $o['pase_status']) ?></span><?php else: ?><?= $e($o['pase_status'] ?? '—') ?><?php endif; ?></td>
                    <td class="cu-num"><?= $e($money($o['total'] ?? 0, (string) ($o['currency'] ?: 'PLN'))) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>

    <?php if ($returns !== [] || $issues !== [] || $ratings !== []): ?>
    <div class="card">
        <strong>Zwroty, dyskusje i oceny</strong>
        <div class="cu-scroll"><table>
            <tr><th>Co</th><th>Zamówienie</th><th>Szczegóły</th><th>Data</th></tr>
            <?php foreach ($returns as $r):
                $items = json_decode((string) $r['items'], true) ?: []; ?>
                <tr>
                    <td><a href="returns.php?id=<?= (int) $r['id'] ?>">↩ Zwrot <?= $e($r['reference_number'] ?? '') ?></a></td>
                    <td><?= $orderLink($byOrder[(int) $r['woo_order_id']] ?? null) ?></td>
                    <td><?= $e(\Pase\Services\OrderReturns::statusLabel((string) $r['status'])) ?> · <?= $e(implode(', ', array_map(static fn($it) => ($it['quantity'] ?? 1) . '× ' . ($it['name'] ?? ''), $items))) ?><?= $r['refund_state'] === 'done' ? ' · pieniądze zwrócone' : '' ?></td>
                    <td><?= $e($day($r['remote_created_at'] ?? $r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($issues as $is): ?>
                <tr>
                    <td><a href="allegro_messages.php?tab=issues&amp;issue=<?= urlencode((string) $is['remote_id']) ?>">💬 <?= $is['type'] === 'CLAIM' ? 'Reklamacja' : 'Dyskusja' ?></a></td>
                    <td><?= $orderLink($byOrder[(int) ($is['woo_order_id'] ?? 0)] ?? null) ?></td>
                    <td><?= $e(\Pase\Services\AllegroFeedback::statusLabel((string) $is['status'])) ?><?= $is['subject'] ? ' · ' . $e($is['subject']) : '' ?></td>
                    <td><?= $e($day($is['opened_at'] ?? $is['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($ratings as $ra): $neg = (int) $ra['recommended'] === 0; ?>
                <tr>
                    <td><a href="allegro_messages.php?tab=ratings#r-<?= $e($ra['remote_id']) ?>"><?= $neg ? '👎 Negatywna ocena' : '👍 Pozytywna ocena' ?></a></td>
                    <td><?= $orderLink($byOrder[(int) ($ra['woo_order_id'] ?? 0)] ?? null) ?></td>
                    <td><?= $ra['comment'] ? '„' . $e($ra['comment']) . '”' : '<span class="cu-muted">bez komentarza</span>' ?></td>
                    <td><?= $e($day($ra['rated_at'] ?? $ra['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ((int) $customer['anonymized'] === 0): ?>
    <div class="card">
        <strong>Ta sama osoba jest zapisana dwa razy?</strong>
        <p class="cu-muted">Zamówienia łączą się same po e-mailu, koncie Allegro i telefonie. Nie połączą się, gdy ktoś kupował z różnych e-maili albo na Allegro podał inny telefon niż w sklepie. Wtedy połącz ręcznie.</p>
        <?php if (canEdit()): ?>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap" onsubmit="return confirm('Połączyć tych klientów w jedną kartę?')">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="merge"><input type="hidden" name="customer_id" value="<?= $id ?>">
                <input name="other" required placeholder="E-mail, telefon, login Allegro albo nr zamówienia drugiego klienta" style="flex:1;min-width:260px">
                <button class="btn secondary" type="submit">Połącz z tym klientem</button>
            </form>
        <?php endif; ?>
        <?php if ($merges !== []): ?>
            <p style="margin:14px 0 6px"><strong>Połączone ręcznie</strong></p>
            <?php foreach ($merges as $m): ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:4px 0">
                    <span class="cu-muted"><?= $e(preg_replace('/^(email|phone|allegro):/', '', (string) $m['key_a'])) ?> + <?= $e(preg_replace('/^(email|phone|allegro):/', '', (string) $m['key_b'])) ?> · <?= $e($m['created_by'] ?? '') ?>, <?= $e($day($m['created_at'])) ?></span>
                    <?php if (canEdit()): ?>
                        <form method="post" onsubmit="return confirm('Cofnąć to połączenie?')">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="unmerge"><input type="hidden" name="customer_id" value="<?= $id ?>"><input type="hidden" name="merge_id" value="<?= (int) $m['id'] ?>">
                            <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:13px">Cofnij</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (isAdmin()): ?>
    <div class="card cu-danger">
        <strong>Usuń dane osobowe klienta</strong>
        <p class="cu-muted">Na prośbę klienta (RODO). Z kart klientów znikną imię i nazwisko, e-maile, telefony, loginy Allegro i ręczne połączenia. Zostaną same liczby: ile zamówień, kiedy, gdzie i za ile. Nie da się tego cofnąć.
            Zamówienia w CRM, w sklepie i na Allegro zachowują swoje dane, bo są potrzebne do rozliczeń. Zamówienia z CRM znikną przy czyszczeniu archiwum po roku.</p>
        <form method="post" onsubmit="return confirm('Usunąć dane osobowe tego klienta? Nie da się tego cofnąć.')">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="anonymize"><input type="hidden" name="customer_id" value="<?= $id ?>">
            <p><label><input type="checkbox" name="confirm" value="1" required> Klient poprosił o usunięcie danych</label></p>
            <button class="btn danger" type="submit">Usuń dane klienta</button>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

<?php else:
    // ============================================================
    //  Lista klientów
    // ============================================================
    $q = trim((string) ($_GET['q'] ?? ''));
    $sort = in_array($_GET['sort'] ?? '', ['last', 'orders', 'spent'], true) ? (string) $_GET['sort'] : 'last';
    $returning = ($_GET['returning'] ?? '') === '1';
    $perPage = 50;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $res = $customers->list($q, $sort, $returning ? 2 : 0, $perPage, ($page - 1) * $perPage);
    $stats = $pdo->query('SELECT COUNT(*) AS n, SUM(CASE WHEN orders_count >= 2 THEN 1 ELSE 0 END) AS ret FROM customers WHERE orders_count > 0 AND anonymized = 0')->fetch(PDO::FETCH_ASSOC);
    ?>
    <div class="card">
        <div class="cu-head">
            <div>
                <p class="cu-muted" style="margin:0"><?= (int) ($stats['n'] ?? 0) ?> klientów, w tym <?= (int) ($stats['ret'] ?? 0) ?> kupowało więcej niż raz. Zbudowane z zamówień ze sklepów i Allegro.</p>
            </div>
            <?php if (isAdmin()): ?>
                <form method="post" onsubmit="return confirm('Przeliczyć wszystkich klientów od nowa? Historia zamówień usuniętych z CRM, ręczne połączenia i usunięte dane zostaną.')">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="rebuild">
                    <button class="btn secondary" type="submit" title="Po poprawkach e-maili i telefonów w zamówieniach">Przelicz od nowa</button>
                </form>
            <?php endif; ?>
        </div>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0">
            <input name="q" value="<?= $e($q) ?>" placeholder="Imię, e-mail, telefon, login Allegro, nr zamówienia" style="flex:1;min-width:240px">
            <select name="sort">
                <option value="last"<?= $sort === 'last' ? ' selected' : '' ?>>Ostatnio kupujący</option>
                <option value="orders"<?= $sort === 'orders' ? ' selected' : '' ?>>Najwięcej zamówień</option>
                <option value="spent"<?= $sort === 'spent' ? ' selected' : '' ?>>Najwięcej wydali</option>
            </select>
            <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="returning" value="1"<?= $returning ? ' checked' : '' ?>> tylko powracający</label>
            <button class="btn secondary" type="submit">Szukaj</button>
        </form>
        <?php if ($res['rows'] === []): ?>
            <p class="cu-muted"><?= $q !== '' || $returning ? 'Nikogo nie znaleziono.' : 'Brak klientów. Pojawią się, gdy w CRM będą zamówienia.' ?></p>
        <?php else: ?>
            <div class="cu-scroll"><table>
                <tr><th>Klient</th><th>Kontakt</th><th style="text-align:right">Zamówienia</th><th style="text-align:right">Wydał</th><th>Kanały</th><th>Ostatnie</th></tr>
                <?php foreach ($res['rows'] as $c): ?>
                    <tr>
                        <td><a class="cu-name" href="customers.php?id=<?= (int) $c['id'] ?>"><?= $e($c['name'] ?: 'Bez nazwiska') ?></a></td>
                        <td class="cu-muted">
                            <?= $e(implode(', ', $c['keys']['email'] ?? [])) ?>
                            <?php if (!empty($c['keys']['phone'])): ?><br>☎ <?= $e(implode(', ', array_map($phoneFmt, $c['keys']['phone']))) ?><?php endif; ?>
                            <?php if (!empty($c['keys']['allegro'])): ?><br>Allegro: <?= $e(implode(', ', $c['keys']['allegro'])) ?><?php endif; ?>
                        </td>
                        <td class="cu-num"><?= (int) $c['orders_count'] ?></td>
                        <td class="cu-num"><?= $e($totals($c['totals'])) ?></td>
                        <td><?php foreach ($c['channels'] as $ch): ?><span class="cu-chip<?= stripos((string) $ch['label'], 'allegro') !== false ? ' allegro' : '' ?>"><?= $e($ch['label']) ?> × <?= (int) $ch['count'] ?></span><?php endforeach; ?></td>
                        <td><?= $e($day($c['last_order_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
            <?php
            $pagerPage = $page;
            $pagerPages = (int) ceil($res['total'] / $perPage);
            $pagerLink = static fn(int $p): string => 'customers.php?' . http_build_query(array_filter(['q' => $q, 'sort' => $sort !== 'last' ? $sort : null, 'returning' => $returning ? '1' : null, 'page' => $p > 1 ? $p : null]));
            require __DIR__ . '/_pager.php';
            ?>
        <?php endif; ?>
    </div>
<?php endif;

?></div><?php
require __DIR__ . '/footer.php';
