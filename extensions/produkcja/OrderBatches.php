<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use Pase\Services\Packing;
use PaseExt\Produkcja\Ui as U;

/**
 * Numer partii przy pozycji zamówienia: wpisuje go osoba przy pakowaniu (albo na karcie zamówienia).
 * Pozycje i ich numery (idx) bierzemy z asystenta pakowania, żeby obie strony mówiły o tych samych pozycjach.
 * Podpowiadamy ostatnie partie wyrobu o SKU pozycji; można wpisać dowolny numer (np. partia sprzed wtyczki).
 */
final class OrderBatches
{
    /** @return list<array{idx:int,name:string,sku:string,qty:int}> */
    private static function items(Store $s, int $orderId): array
    {
        try {
            $o = (new Packing($s->pdo))->order($orderId);
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn(array $i): array => ['idx' => (int) $i['idx'], 'name' => (string) $i['name'], 'sku' => (string) $i['sku'], 'qty' => (int) $i['qty']],
            $o['items'] ?? []);
    }

    /** Dane dla ekranu pakowania: przypisane numery i podpowiedzi po SKU. */
    public static function forPacking(Store $s, array $order): array
    {
        $assigned = [];
        foreach ($s->orderBatches((int) ($order['id'] ?? 0)) as $idx => $r) {
            $assigned[(string) $idx] = (string) $r['batch_code'];
        }
        $skus = array_column($order['items'] ?? [], 'sku');
        $made = [];
        foreach ($skus !== [] ? $s->all('SELECT LOWER(sku) AS sku FROM ext_prod_products WHERE sku <> \'\' AND archived = 0') : [] as $r) {
            $made[$r['sku']] = true;
        }
        $hints = $s->recentBatchesBySku($skus);
        $ours = [];
        foreach ($order['items'] ?? [] as $it) {
            $k = mb_strtolower((string) $it['sku']);
            if (isset($made[$k]) || isset($assigned[(string) $it['idx']])) {
                $ours[(string) $it['idx']] = array_map(static fn(array $b): string => $b['code'], $hints[$k] ?? []);
            }
        }
        return ['assigned' => (object) $assigned, 'items' => (object) $ours];
    }

    /** Zapis z pakowania: jedna pozycja. */
    public static function saveFromPacking(Store $s, int $orderId, int $idx, string $code, string $user): array
    {
        foreach (self::items($s, $orderId) as $it) {
            if ($it['idx'] === $idx) {
                $b = $s->setOrderBatch($orderId, $idx, $it['sku'], $it['name'], $it['qty'], $code, $user);
                return ['code' => strtoupper(trim($code)), 'known' => $b !== null || trim($code) === ''];
            }
        }
        throw new \RuntimeException('Nie ma takiej pozycji w zamówieniu.');
    }

    /** Zapis z karty zamówienia: wszystkie pozycje naraz (idx => numer). */
    public static function saveFromCard(Store $s, int $orderId, array $codes, string $user): int
    {
        $n = 0;
        $now = $s->orderBatches($orderId);
        foreach (self::items($s, $orderId) as $it) {
            if (!array_key_exists((string) $it['idx'], $codes)) {
                continue;
            }
            $code = strtoupper(trim((string) $codes[(string) $it['idx']]));
            if ($code !== strtoupper((string) ($now[$it['idx']]['batch_code'] ?? ''))) {
                $s->setOrderBatch($orderId, $it['idx'], $it['sku'], $it['name'], $it['qty'], $code, $user);
                $n++;
            }
        }
        return $n;
    }

    /** Sekcja karty zamówienia. */
    public static function section(Store $s, array $row, array $order): string
    {
        $id = (int) ($row['woo_order_id'] ?? 0);
        $items = self::items($s, $id);
        if ($items === []) {
            return '<p style="margin:0;color:var(--ink-2)">Brak pozycji do przypisania.</p>';
        }
        $assigned = $s->orderBatches($id);
        $hints = $s->recentBatchesBySku(array_column($items, 'sku'));
        $canEdit = function_exists('roleCanEdit') && roleCanEdit();
        $html = '';
        $any = false;
        foreach ($items as $it) {
            $code = (string) ($assigned[$it['idx']]['batch_code'] ?? '');
            $sug = $hints[mb_strtolower($it['sku'])] ?? [];
            if ($code === '' && $sug === []) {
                continue;
            }
            $any = true;
            $known = $code !== '' && ($b = $s->batchByCode($code)) !== null;
            $list = 'pr-ob-' . $it['idx'];
            $html .= '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;padding:6px 0;border-top:1px solid var(--line)">'
                . '<span style="min-width:0">' . (int) $it['qty'] . ' × ' . U::h($it['name']) . '</span>'
                . ($canEdit ? '<span><input name="batch[' . $it['idx'] . ']" value="' . U::h($code) . '" list="' . $list . '" placeholder="numer partii" style="width:150px;text-transform:uppercase;font-family:var(--font-num)">'
                    . '<datalist id="' . $list . '">' . implode('', array_map(static fn(array $h): string => '<option value="' . U::h($h['code']) . '">' . U::date($h['date']) . '</option>', $sug)) . '</datalist></span>'
                    : ($code !== '' ? '<b style="font-family:var(--font-num)">' . U::h($code) . '</b>' : '<span style="color:var(--ink-3)">-</span>'))
                . '</div>' . ($known ? '<div style="font-size:12px;margin:-2px 0 4px"><a href="' . U::h(U::url('partia', ['id' => $b['id']])) . '">partia z ' . U::date($b['produced_at']) . '</a></div>' : '');
        }
        if (!$any) {
            return '<p style="margin:0;color:var(--ink-2)">Pozycje nie są wyrobami z produkcji (brak SKU wyrobu z partiami).</p>';
        }
        return $canEdit ? '<form method="post" action="' . U::h(U::url('zamowienie_partie')) . '"><input type="hidden" name="csrf" value="' . U::h(csrfToken()) . '">'
            . '<input type="hidden" name="order_id" value="' . $id . '">' . $html . '<button class="btn secondary" type="submit" style="margin-top:8px">Zapisz numery partii</button></form>' : $html;
    }
}
