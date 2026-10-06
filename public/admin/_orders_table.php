<?php
declare(strict_types=1);

/**
 * Karta "Lista" (pasek akcji + tabela + paginacja) na liście zamówień.
 * WSPÓLNA dla pełnego przeładowania (index.php) i wyszukiwania na żywo
 * (orders_search.php) - ta sama karta trafia do DOM przy podmianie AJAX.
 *
 * Wymaga wcześniej: _orders_query.php (rows/pages/itd.), $statusList, $statusMap, $view.
 * Plik include-only (patrz .htaccess) - bez własnego auth/sesji.
 */

if (!function_exists('statusBadge')) {
    /** Renderuje kolorowy badge statusu. */
    function statusBadge(?string $key, array $statusMap): string
    {
        $s = $statusMap[$key] ?? null;
        $label = $s['label'] ?? ($key ?: '—');
        $color = $s['color'] ?? '#888888';
        return '<span style="display:inline-block;white-space:nowrap;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:'
            . htmlspecialchars($color) . '">' . htmlspecialchars($label) . '</span>';
    }
}

if (!function_exists('sourceBadge')) {
    /**
     * Renderuje źródło zamówienia: logo kanału (z wtyczki integracji - Services\OrderChannelIcons)
     * + WooCommerce/Allegro, nazwa integracji oraz - co najważniejsze przy wielu sklepach - DOMENA
     * sklepu (wyłuskana z base_url w configu integracji), żeby jednoznacznie wiedzieć
     * z którego sklepu trafiło zamówienie.
     */
    function sourceBadge(?string $type, ?string $name, ?string $configJson): string
    {
        $labels = ['woocommerce' => 'WooCommerce', 'allegro' => 'Allegro', 'wfirma' => 'wFirma', 'manual' => 'Ręczne', 'tiktokshop' => 'TikTok Shop'];
        if ($type === null) {
            return '<span style="color:var(--ink-3)">—</span>';
        }
        $lbl = $labels[$type] ?? (\Pase\Services\OrderChannelIcons::sourceName($type) ?? $type);

        // Domena z base_url (np. "twojsklep.pl").
        $domain = '';
        $cfg = $configJson ? (json_decode($configJson, true) ?: []) : [];
        if (!empty($cfg['base_url'])) {
            $host = parse_url($cfg['base_url'], PHP_URL_HOST);
            $domain = $host ? preg_replace('/^www\./', '', $host) : '';
        }

        $out = '<span class="source-badge">'
            . '<span class="source-logo">' . \Pase\Services\OrderChannelIcons::sourceLogo($type) . '</span>'
            . htmlspecialchars($lbl) . '</span>';
        // Druga linia: domena (priorytet) + nazwa integracji w nawiasie.
        $second = $domain !== '' ? $domain : (string) $name;
        if ($second !== '') {
            $extra = ($domain !== '' && $name) ? ' (' . $name . ')' : '';
            $out .= '<div class="source-sub">' . htmlspecialchars($second . $extra) . '</div>';
        }
        return $out;
    }
}
?>
<!-- ===== Lista ===== -->
<div class="card">
    <form method="post" id="bulkForm">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">

    <?php if (canEdit()): ?>
    <!-- Pasek akcji: zmiana statusu + akcje cyklu życia (zależne od widoku) -->
    <div class="actionbar">
        <?php if ($view === 'active'): ?>
        <div class="flag-menu">
            <button type="button" class="flag-btn" onclick="this.closest('.flag-menu').classList.toggle('open')" title="<?= htmlspecialchars(t('orders.bulk.status')) ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3v18"/><path d="M5 4h11l-2 4 2 4H5"/></svg>
                <span style="font-size:12px"><?= htmlspecialchars(t('orders.bulk.status')) ?></span>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="flag-dropdown">
                <?php foreach ($statusList as $s): ?>
                    <button type="submit" name="bulk_pase_status" value="<?= htmlspecialchars($s['status_key']) ?>"
                        formaction="index.php" onclick="this.form.elements['action'].value='bulk_status'; return document.querySelectorAll('.rowcheck:checked').length ? true : (alert('<?= htmlspecialchars(t('orders.empty')) ?>'),false)"
                        class="flag-item">
                        <span class="dot" style="background:<?= htmlspecialchars($s['color']) ?>"></span>
                        <?= htmlspecialchars($s['label']) ?>
                    </button>
                <?php endforeach; ?>
                <a class="flag-item" href="statuses.php" style="border-top:1px solid var(--line);color:var(--accent)"><?= htmlspecialchars(t('orders.side.manage')) ?></a>
            </div>
        </div>
        <?php if (canOpenPage('packing.php')): ?>
            <button type="button" class="btn" title="Asystent pakowania dla zaznaczonych zamówień"
                onclick="var ids=[].map.call(document.querySelectorAll('.rowcheck:checked'),function(c){return c.value});if(!ids.length){alert('Zaznacz zamówienia do spakowania.');return;}location.href='packing.php?ids='+ids.join(',');">📦 Pakuj</button>
        <?php endif; ?>
        <button type="button" class="btn secondary" title="Scal zaznaczone zamówienia jednego klienta w jedną paczkę"
            onclick="var ids=[].map.call(document.querySelectorAll('.rowcheck:checked'),function(c){return c.value});if(ids.length<2){alert('Zaznacz co najmniej dwa zamówienia do scalenia.');return;}location.href='order_merge.php?ids='+ids.join(',');">Scal</button>
        <?php endif; ?>

        <!-- Ukryte pole action ustawiane przez przyciski poniżej -->
        <input type="hidden" name="action" value="">

        <?php if ($view === 'active'): ?>
            <button type="submit" class="btn secondary act-sel" data-action="archive"><?= htmlspecialchars(t('orders.bulk.archive')) ?></button>
            <button type="submit" class="btn danger act-sel" data-action="trash"><?= htmlspecialchars(t('orders.bulk.trash')) ?></button>
        <?php elseif ($view === 'archived'): ?>
            <button type="submit" class="btn secondary act-sel" data-action="restore"><?= htmlspecialchars(t('orders.bulk.restore')) ?></button>
            <button type="submit" class="btn danger act-sel" data-action="trash"><?= htmlspecialchars(t('orders.bulk.trash')) ?></button>
        <?php else: /* trashed */ ?>
            <button type="submit" class="btn secondary act-sel" data-action="restore"><?= htmlspecialchars(t('orders.bulk.restore')) ?></button>
            <button type="submit" class="btn danger act-sel" data-action="purge" data-confirm="<?= htmlspecialchars(t('orders.bulk.purge')) ?>?"><?= htmlspecialchars(t('orders.bulk.purge')) ?></button>
            <button type="submit" class="btn danger" onclick="this.form.elements['action'].value='empty_trash'; return confirm('<?= htmlspecialchars(t('orders.bulk.empty')) ?>?');"><?= htmlspecialchars(t('orders.bulk.empty')) ?></button>
        <?php endif; ?>

        <?php // Przyciski rozszerzeń na pasku akcji (zaznaczone wiersze: .rowcheck:checked). ?>
        <?= \Pase\Plugin\Hooks::render('orders.actionbar', $view) ?>

        <span style="font-size:13px;color:var(--ink-2);margin-left:auto"><?= str_replace('{n}', '<span id="selCount">0</span>', htmlspecialchars(t('orders.selected'), ENT_QUOTES)) ?></span>
    </div>
    <?php endif; ?>

    <div class="orders-table-scroll" tabindex="0" role="region" aria-label="<?= htmlspecialchars(t('orders.title')) ?>">
    <table class="orders-table">
        <tr>
            <?php if (canEdit()): ?><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.rowcheck').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
            <?php
            // Klikalne nagłówki: sortowanie po stronie serwera (cała lista, nie tylko bieżąca strona).
            // Aktywna kolumna ma strzałkę ▲/▼; kolejne kliknięcie odwraca kierunek.
            $sortTh = static function (string $column, string $labelKey, string $class = '') use ($sortLink, $sortColumn, $sortDir): string {
                $active = $sortColumn === $column;
                $aria = $active ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none';
                $arrow = $active ? ($sortDir === 'asc' ? '▲' : '▼') : '↕';
                return '<th' . ($class !== '' ? ' class="' . $class . '"' : '') . ' aria-sort="' . $aria . '">'
                    . '<a class="sort-link' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($sortLink($column)) . '">'
                    . htmlspecialchars(t($labelKey)) . '<span class="sort-arrow" aria-hidden="true">' . $arrow . '</span></a></th>';
            };
            ?>
            <?php
            // Kolumny listy: rdzeń ma gotowy nagłówek ('th'), rozszerzenia (filtr orders.columns) mogą je
            // przestawić, usunąć albo dodać własne: ['label' => 'Waga', 'cell' => fn(array $row): string, 'class' => ''].
            $orderColumns = [
                'number'   => ['label' => t('orders.col.shop'),     'th' => $sortTh('shop', 'orders.col.shop')],
                'source'   => ['label' => t('orders.col.source'),   'th' => $sortTh('source', 'orders.col.source')],
                'status'   => ['label' => t('orders.col.status'),   'th' => $sortTh('status', 'orders.col.status')],
                'client'   => ['label' => t('orders.col.client'),   'th' => $sortTh('client', 'orders.col.client')],
                'email'    => ['label' => t('orders.col.email'),    'th' => $sortTh('email', 'orders.col.email')],
                'amount'   => ['label' => t('orders.col.amount'),   'th' => $sortTh('amount', 'orders.col.amount', 'col-amt')],
                'delivery' => ['label' => t('orders.col.delivery'), 'th' => $sortTh('delivery', 'orders.col.delivery', 'col-delivery')],
                'date'     => ['label' => t('orders.col.date'),     'th' => $sortTh('date', 'orders.col.date')],
                'actions'  => ['label' => '',                       'th' => '<th></th>'],
            ];
            $orderColumns = \Pase\Plugin\Hooks::applyFilters('orders.columns', $orderColumns, $view);
            foreach ($orderColumns as $colKey => $col) {
                echo isset($col['cell'])
                    ? '<th' . (!empty($col['class']) ? ' class="' . htmlspecialchars((string) $col['class']) . '"' : '') . '>' . htmlspecialchars((string) ($col['label'] ?? $colKey)) . '</th>'
                    : ($col['th'] ?? '<th>' . htmlspecialchars((string) ($col['label'] ?? '')) . '</th>');
            }
            ?>
        </tr>
        <?php if ($rows === [] && !$noTable): ?>
            <tr><td colspan="<?= count($orderColumns) + 1 ?>" style="color:var(--ink-2)"><?= htmlspecialchars(t('orders.empty')) ?></td></tr>
        <?php endif; ?>
        <?php
        // Ikony przycisków akcji wierszowych (linearne SVG zamiast emoji).
        $icoRestore = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-2"/></svg>';
        $icoPurge   = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
        $icoTrash   = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/></svg>';
        ?>
        <?php
        // Scalone zamówienia: dołączone są wyszarzone z odnośnikiem do głównego, główne pokazuje sumę paczki.
        $mergeInfo = (new \Pase\Services\OrderMerge($pdo))->listInfo(array_column($rows, 'woo_order_id'));
        // Nazwa klienta prowadzi do karty klienta, jeśli konto ma dostęp do strony Klienci.
        $canSeeCustomers = function_exists('canViewPage') && canViewPage('customers');
        // Rozszerzenia dostają całą stronę wierszy naraz (np. jedna kwerenda zamiast jednej na wiersz).
        \Pase\Plugin\Hooks::doAction('orders.rows', $rows);
        ?>
        <?php foreach ($rows as $r): $unread = $unreadByOrder[(int)$r['woo_order_id']] ?? 0; $mi = $mergeInfo[(int)$r['woo_order_id']] ?? []; ?>
            <?php $rowClass = trim(($unread > 0 ? 'row-unread ' : '') . (isset($mi['into']) ? 'row-merged' : ''));
                $rowClass = trim(\Pase\Plugin\Hooks::applyFilters('orders.row_class', $rowClass, $r)); ?>
            <tr<?= $rowClass !== '' ? ' class="' . htmlspecialchars($rowClass) . '"' : '' ?>>
                <?php if (canEdit()): ?><td><input type="checkbox" class="rowcheck" name="ids[]" value="<?= (int)$r['woo_order_id'] ?>"></td><?php endif; ?>
                <?php $cells = []; ob_start(); // komórki rdzenia zbieramy, żeby rozszerzenia mogły zmienić układ kolumn ?>
                <?php
                    $isAllegroRow = ($r['source_type'] ?? '') === 'allegro';
                    $fullNumber = (string) ($r['order_number'] ?? '');
                    // Allegro: numer to długi UUID checkout-formu - pokazujemy tylko pierwszy
                    // segment (krótki, jak w BaseLinkerze), pełny dostępny w tooltipie.
                    $displayNumber = $isAllegroRow ? explode('-', $fullNumber, 2)[0] : $fullNumber;
                    $buyerLogin = null;
                    if ($isAllegroRow) {
                        $payloadArr = json_decode((string) ($r['payload'] ?? ''), true);
                        $buyerLogin = is_array($payloadArr) ? ($payloadArr['buyer']['login'] ?? null) : null;
                    }
                ?>
                <td style="font-size:12px;color:var(--ink-2)">
                    <a class="order-number-link" href="order_view.php?id=<?= (int)$r['woo_order_id'] ?>">
                        <strong><span class="order-source-number" title="<?= htmlspecialchars($fullNumber) ?>"><?= htmlspecialchars($displayNumber) ?></span></strong>
                        <?php if ($isAllegroRow && $buyerLogin): ?>
                            <span class="order-shop-id">(<?= htmlspecialchars($buyerLogin) ?>)</span>
                        <?php elseif (($r['source_type'] ?? '') === 'woocommerce'): ?>
                            <span class="order-shop-id">Woo #<?= (int)$r['woo_order_id'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if (isset($mi['into'])): ?>
                        <a class="merge-pill" href="order_view.php?id=<?= (int)$mi['into']['id'] ?>" title="Scalone - wysyłane razem z zamówieniem <?= htmlspecialchars($mi['into']['label']) ?>">scalone → <?= htmlspecialchars($mi['into']['label']) ?></a>
                    <?php elseif (!empty($mi['absorbed'])): ?>
                        <span class="merge-pill main" title="Scalone - w tej paczce także zamówienia: <?= htmlspecialchars(implode(', ', $mi['numbers'] ?? [])) ?>">+ <?= htmlspecialchars(implode(', ', $mi['numbers'] ?? [])) ?></span>
                    <?php endif; ?>
                    <?php if ($unread > 0): ?>
                        <a href="order_view.php?id=<?= (int)$r['woo_order_id'] ?>" class="msg-badge" title="Nowe wiadomości od klienta">✉ <?= (int)$unread ?></a>
                    <?php endif; ?>
                </td>
                <?php $cells['number'] = ob_get_clean(); ob_start(); ?>
                <td><?= sourceBadge($r['source_type'] ?? (\Pase\Services\ManualOrders::isManual((int) $r['woo_order_id']) ? 'manual' : null), $r['source_name'] ?? null, $r['source_config'] ?? null) ?></td>
                <?php $cells['source'] = ob_get_clean(); ob_start(); ?>
                <td>
                    <?= statusBadge($r['pase_status'] ?? '', $statusMap) ?>
                    <div class="order-indicators" data-order-id="<?= (int)$r['woo_order_id'] ?>">
                        <?php
                        // Jeśli aktywne filtry po wskaźnikach, były już policzone wcześniej (unikamy powtórki).
                        $payload = null;
                        if (isset($r['_indicators'])) {
                            $indicators = $r['_indicators'];
                        } else {
                            $payload = json_decode($r['payload'] ?? '{}', true);
                            $indicators = \Pase\Services\OrderIndicators::forOrder(
                                $r, is_array($payload) ? $payload : [],
                                $shipmentsByOrder === null ? null : ($shipmentsByOrder[(int)$r['woo_order_id']] ?? [])
                            );
                        }
                        if (!isset($indicators['receipt'])) {
                            $payloadForReceipt = $payload ?? json_decode($r['payload'] ?? '{}', true);
                            $indicators['receipt'] = \Pase\Services\OrderIndicators::receipt(
                                $r, is_array($payloadForReceipt) ? $payloadForReceipt : [], $indicators['invoice'],
                                !empty($r['invoice_issued']), !empty($r['receipt_issued'])
                            );
                        }
                        if (!empty($r['invoice_issued'])) {
                            $indicators['invoice'] = ['state' => 'ok', 'label' => 'Faktura wystawiona', 'literal' => true];
                        }
                        if (isset($mi['into']) && isset($indicators['shipping']) && !in_array($indicators['shipping']['state'], ['ok', 'transit', 'muted'], true)) {
                            $indicators['shipping'] = ['state' => 'info', 'label' => 'Wysyłka razem z zamówieniem ' . $mi['into']['label'], 'literal' => true];
                        }
                        // Uwaga kupującego (Woo: customer_note, Allegro: messageToSeller) - klik prowadzi do treści na karcie zamówienia.
                        $payloadForNote = $payload ?? json_decode($r['payload'] ?? '{}', true);
                        $noteIndicator = \Pase\Services\OrderIndicators::note(is_array($payloadForNote) ? $payloadForNote : []);
                        if ($noteIndicator !== null) {
                            $indicators['note'] = $noteIndicator + ['href' => 'order_view.php?id=' . (int)$r['woo_order_id'] . '#customer-note'];
                        }
                        // Rozszerzenia mogą zmienić stan ikon albo dodać własne (z polem 'icon' = ścieżki SVG 24x24).
                        $indicators = \Pase\Plugin\Hooks::applyFilters('orders.indicators', $indicators, $r);
                        // Te same ikony rysuje okienko „Opis ikon zamówienia” (order_icons.php).
                        $iconPaths = \Pase\Services\OrderIconDetails::ICON_PATHS;
                        // Faktura i paragon mają podobne ikony - dopisany skrót odróżnia je na pierwszy rzut oka.
                        $iconText = \Pase\Services\OrderIconDetails::ICON_TEXT;
                        foreach ($indicators as $kind => $indicator):
                            // Wskaźnik "muted" (nieznany/nie dotyczy - np. faktura nie zaznaczona,
                            // produkt nie wymaga wysyłki) niczego nie mówi - nie ma sensu go pokazywać.
                            // Odbiór osobisty też pomijamy - nie ma czego śledzić.
                            if ($indicator['state'] === 'muted' || $indicator['label'] === 'orders.indicator.shipping_pickup') { continue; }
                            $indicatorLabel = !empty($indicator['literal']) ? $indicator['label'] : t($indicator['label']);
                            $legendLabel = $indicatorLabel;   // opis do okienka ikon (bez dopisku o wFirma)
                            $indicatorHref = (string) ($indicator['href'] ?? 'order_view.php?id=' . (int)$r['woo_order_id']);
                            $indicatorTarget = '';
                            $remoteDoc = $kind === 'receipt' ? ($r['receipt_remote_id'] ?? null) : ($kind === 'invoice' ? ($r['invoice_remote_id'] ?? null) : null);
                            if (in_array($kind, ['receipt', 'invoice'], true) && $indicator['state'] === 'ok' && !empty($remoteDoc)) {
                                $indicatorHref = 'https://wfirma.pl/invoices/view/' . rawurlencode((string) $remoteDoc);
                                $indicatorTarget = ' target="_blank" rel="noopener noreferrer"';
                                $indicatorLabel .= ' — otwórz w wFirma';
                            }
                        ?>
                        <a class="order-indicator <?= htmlspecialchars($indicator['state']) ?><?= isset($iconText[$kind]) ? ' has-text' : '' ?>" href="<?= htmlspecialchars($indicatorHref) ?>"<?= $indicatorTarget ?>
                           data-kind="<?= htmlspecialchars($kind) ?>" data-legend="<?= htmlspecialchars($legendLabel) ?>"
                           aria-label="<?= htmlspecialchars($indicatorLabel) ?>" title="<?= htmlspecialchars($indicatorLabel) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $iconPaths[$kind] ?? ($indicator['icon'] ?? '<circle cx="12" cy="12" r="8"/>') ?></svg><?php if (isset($iconText[$kind])): ?><span class="ind-txt"><?= $iconText[$kind] ?></span><?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </td>
                <?php $cells['status'] = ob_get_clean(); ob_start(); ?>
                <td><?php if (($r['customer_name'] ?? '') !== '' && $canSeeCustomers): ?><a class="customer-link" href="customers.php?order=<?= (int)$r['woo_order_id'] ?>" title="Karta klienta: wszystkie zamówienia i kanały"><?= htmlspecialchars($r['customer_name']) ?></a><?php else: ?><?= htmlspecialchars($r['customer_name'] ?? '—') ?><?php endif; ?></td>
                <?php $cells['client'] = ob_get_clean(); ob_start(); ?>
                <td style="font-size:12px"><?= htmlspecialchars($r['customer_email'] ?? '—') ?></td>
                <?php $cells['email'] = ob_get_clean(); ob_start(); ?>
                <td class="col-amt"<?= !empty($mi['absorbed']) ? ' title="Łączna cena scalonych zamówień"' : '' ?>><?= htmlspecialchars(!empty($mi['absorbed']) ? number_format((float)($mi['group_total'] ?? 0), 2, '.', '') : (string)($r['total'] ?? '')) ?>&nbsp;<?= htmlspecialchars($r['currency'] ?? '') ?></td>
                <?php $cells['amount'] = ob_get_clean(); ob_start(); ?>
                <td class="col-delivery"><?php
                    // Przewoźnik: z nadanej przesyłki, a bez niej z metody dostawy wybranej przez klienta.
                    $payloadForDelivery = $payload ?? json_decode($r['payload'] ?? '{}', true);
                    echo \Pase\Services\OrderChannelIcons::deliveryCell(\Pase\Services\OrderChannelIcons::delivery(
                        is_array($payloadForDelivery) ? $payloadForDelivery : [], $shipmentsByOrder[(int)$r['woo_order_id']] ?? []
                    ));
                ?></td>
                <?php $cells['delivery'] = ob_get_clean(); ob_start(); ?>
                <td style="font-size:12px;font-family:var(--font-num);white-space:nowrap"><?= htmlspecialchars($r['date_created'] ?? '') ?></td>
                <?php $cells['date'] = ob_get_clean(); ob_start(); ?>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="order_view.php?id=<?= (int)$r['woo_order_id'] ?>"><?= htmlspecialchars(t('common.preview')) ?></a>
                    <?php if (canEdit()): ?>
                        <?php if ($view === 'trashed'): ?>
                            <button type="submit" class="btn secondary row-act" data-action="restore" data-id="<?= (int)$r['woo_order_id'] ?>" title="<?= htmlspecialchars(t('orders.bulk.restore')) ?>" aria-label="<?= htmlspecialchars(t('orders.bulk.restore')) ?>"><?= $icoRestore ?></button>
                            <button type="submit" class="btn danger row-act" data-action="purge" data-id="<?= (int)$r['woo_order_id'] ?>" data-confirm="<?= htmlspecialchars(t('orders.bulk.purge')) ?>?" title="<?= htmlspecialchars(t('orders.bulk.purge')) ?>" aria-label="<?= htmlspecialchars(t('orders.bulk.purge')) ?>"><?= $icoPurge ?></button>
                        <?php elseif ($view === 'archived'): ?>
                            <button type="submit" class="btn secondary row-act" data-action="restore" data-id="<?= (int)$r['woo_order_id'] ?>" title="<?= htmlspecialchars(t('orders.bulk.restore')) ?>" aria-label="<?= htmlspecialchars(t('orders.bulk.restore')) ?>"><?= $icoRestore ?></button>
                        <?php else: ?>
                            <button type="submit" class="btn danger row-act" data-action="trash" data-id="<?= (int)$r['woo_order_id'] ?>" data-confirm="<?= htmlspecialchars(t('orders.bulk.trash')) ?>?" title="<?= htmlspecialchars(t('orders.bulk.trash')) ?>" aria-label="<?= htmlspecialchars(t('orders.bulk.trash')) ?>"><?= $icoTrash ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <?php $cells['actions'] = ob_get_clean();
                foreach ($orderColumns as $colKey => $col) {
                    if (isset($col['cell'])) {
                        // Kolumna rozszerzenia: HTML z callbacku (błąd = pusta komórka, wpis w logu).
                        $html = \Pase\Plugin\Hooks::safe('orders.columns:' . $colKey, $col['cell'], $r);
                        echo '<td' . (!empty($col['class']) ? ' class="' . htmlspecialchars((string) $col['class']) . '"' : '') . '>' . (is_string($html) ? $html : '') . '</td>';
                    } else {
                        echo $cells[$colKey] ?? '<td></td>';
                    }
                } ?>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    </form>

    <!-- ===== Paginacja ===== -->
    <?php if (!$noTable) { $pagerPage = $page; $pagerPages = $pages; $pagerLink = $buildLink; require __DIR__ . '/_pager.php'; } ?>
    <?php if (!$noTable): ?>
        <div class="orders-perpage">
            <span><?= htmlspecialchars(t('orders.perpage_label')) ?></span>
            <?php // Lista rozwijana; wybór od razu przeładowuje listę (działa też po podmianie wyników z wyszukiwania na żywo). ?>
            <select class="orders-perpage-select" aria-label="<?= htmlspecialchars(t('orders.perpage_label')) ?>" onchange="window.location.href = this.value">
                <?php foreach ($perPageOptions as $n): ?>
                    <option value="<?= htmlspecialchars($perPageLink($n)) ?>"<?= $n === $perPage ? ' selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
</div>
