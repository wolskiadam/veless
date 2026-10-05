<?php
declare(strict_types=1);

/**
 * Automatyzacje (model BaseLinker): ZDARZENIE + warunki TO lista akcji.
 *
 * Lista reguł z uchwytem, toggle aktywności, duplikuj, usuń. Edycja inline:
 * po kliknięciu „Edytuj" wiersz rozwija edytor (zdarzenie + warunki | lista akcji | zapis).
 * „Dodaj automatyczną akcję" otwiera pusty edytor na górze listy.
 */

use Pase\Automation\Catalog;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\EmailTemplateRepository;
use Pase\Repository\OrderStatusRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$repo     = new AutomationRuleRepository($pdo);
$canEdit  = canEdit();
$flashOk  = $flashErr = null;

$events     = Catalog::events();
$actionDefs = Catalog::actions();
$condFields = Catalog::conditionFields() + ['shipment_status' => 'Status przesyłki (u kuriera)'];
$condOps    = Catalog::conditionOps();
// Zdarzenia z parametrem wybieranym obok zdarzenia: „Zmieniono status przesyłki" -> NA JAKI etap,
// „Ustawiono status" -> NA JAKI status. Zapisywany jako warunek (pole => eq).
$eventParam = Catalog::eventParams();

// Statusy PASE do dropdownu akcji „Ustaw status".
$statuses = (new OrderStatusRepository($pdo))->all();
$statusLabels = array_column($statuses, 'label', 'status_key');
$shipStatusLabels = array_map(static fn($d) => $d[0], \Pase\Services\ShipmentTracking::STATUSES);
// Szablony e-mail do dropdownu akcji „Wyślij e-mail".
$emailTpls = (new EmailTemplateRepository($pdo))->active();

// Źródła zamówień = konta kanałów sprzedaży (sklepy WooCommerce, konta Allegro).
$sourceOptions = [];
foreach ((new \Pase\Repository\IntegrationAccountRepository($pdo))->all() as $acc) {
    if (in_array($acc['type'], ['woocommerce', 'allegro'], true)) {
        $sourceOptions[] = ['integration:' . (int) $acc['id'], ($acc['name'] ?: $acc['type']) . ($acc['type'] === 'allegro' ? ' (Allegro)' : '')];
    }
}
$statusOptions = array_map(static fn($s) => [$s['status_key'], $s['label']], $statuses);
$shipOptions   = array_map(static fn($k, $l) => [$k, $l], array_keys($shipStatusLabels), $shipStatusLabels);

// Definicje pól warunków dla edytora: typ decyduje o polu wartości i operatorach.
$condDefs = [];
foreach ($condFields as $fk => $fl) {
    $condDefs[$fk] = match ($fk) {
        'pase_status'     => ['label' => $fl, 'type' => 'select', 'options' => $statusOptions],
        'shipment_status' => ['label' => $fl, 'type' => 'select', 'options' => $shipOptions],
        'source'          => ['label' => $fl, 'type' => 'select', 'options' => $sourceOptions],
        'payment_status'  => ['label' => $fl, 'type' => 'select', 'options' => array_map(null, array_keys(\Pase\Automation\OrderEvents::PAYMENT_STATES), array_values(\Pase\Automation\OrderEvents::PAYMENT_STATES))],
        'is_virtual', 'wants_invoice', 'has_shipment'
                          => ['label' => $fl, 'type' => 'select', 'options' => [['1', 'Tak'], ['0', 'Nie']]],
        'document'        => ['label' => $fl, 'type' => 'select', 'options' => [['invoice', 'Faktura VAT wystawiona'], ['receipt', 'Paragon wystawiony'], ['none', 'Brak faktury i paragonu']]],
        'total', 'items_qty' => ['label' => $fl, 'type' => 'number'],
        'payment_method', 'shipping_method', 'product_name', 'customer_email'
                          => ['label' => $fl, 'type' => 'textc'],
        default           => ['label' => $fl, 'type' => 'text'],
    };
}
$opsByType = [
    'select' => [['eq', 'jest'], ['ne', 'nie jest']],
    'number' => [['eq', '='], ['ne', '≠'], ['gt', '>'], ['gte', '≥'], ['lt', '<'], ['lte', '≤']],
    'text'   => [['eq', 'jest'], ['ne', 'nie jest'], ['contains', 'zawiera'], ['ncontains', 'nie zawiera']],
    // Pola z opisem (metoda płatności / dostawy, nazwa produktu): najczęściej „zawiera”.
    'textc'  => [['contains', 'zawiera'], ['ncontains', 'nie zawiera'], ['eq', 'jest'], ['ne', 'nie jest']],
];
// Pola warunków „pierwszego wyboru" dla zdarzeń (pierwsze dodaje się samo po wyborze zdarzenia).
$eventHints = [
    'order.imported'  => ['source'],
];
$hasSms = $hasWfirma = false;
$wooShops = [];
foreach ((new \Pase\Repository\IntegrationAccountRepository($pdo))->all() as $acc) {
    if (empty($acc['is_active'])) {
        continue;
    }
    $hasWfirma = $hasWfirma || $acc['type'] === 'wfirma';
    $hasSms    = $hasSms || $acc['type'] === 'smsapi';
    if ($acc['type'] === 'woocommerce') {
        $wooShops[] = [(string) (int) $acc['id'], (string) ($acc['name'] ?: 'Sklep #' . $acc['id'])];
    }
}
// Akcje dla edytora: etykieta + parametr (z listą wyboru, gdy jest).
$actionUi = [];
foreach ($actionDefs as $ak => $ad) {
    $pLabel = (string) (array_values($ad['params'])[0] ?? 'Wartość');
    $param  = ['label' => $pLabel];
    if ($ak === 'set_status') {
        $param = ['label' => 'Status docelowy', 'options' => $statusOptions];
    } elseif ($ak === 'send_email') {
        $param = ['label' => 'Szablon e-mail', 'options' => array_map(static fn($t) => [$t['tpl_key'], $t['name']], $emailTpls)];
    } elseif ($ak === 'send_sms') {
        $param = ['label' => 'Treść SMS', 'textarea' => true,
                  'placeholder' => 'Np. Twoja paczka {{waybill}} do zamówienia {{order_number}} została doręczona. Dziękujemy! {{shop_name}}',
                  'hint' => 'Tagi: {{customer_name}} {{order_number}} {{pase_number}} {{total}} {{currency}} {{waybill}} {{shipment_status}} {{shop_name}}'
                      . ($hasSms ? '' : ' · ⚠ Brak aktywnej integracji SMS — dodaj SMSAPI w Integracjach.')];
    } elseif ($ak === 'issue_invoice' || $ak === 'create_receipt') {
        $param = ['label' => $ak === 'issue_invoice' ? 'Rodzaj dokumentu' : 'Rodzaj paragonu',
                  'options' => $ak === 'issue_invoice' ? [['normal', 'Faktura VAT'], ['proforma', 'Proforma']]
                                                       : [['receipt', 'Paragon niefiskalny'], ['receipt_fiscal', 'Paragon fiskalny']],
                  'hint' => $hasWfirma ? 'Wystawiany w wFirma na dane z zamówienia; drugi taki sam dokument dla zamówienia nie powstanie.'
                                       : '⚠ Brak aktywnej integracji wFirma — dodaj ją w Integracjach.'];
    } elseif ($ak === 'issue_sales_document') {
        $param = ['label' => 'Gdy bez faktury', 'options' => [['receipt', 'Paragon niefiskalny'], ['receipt_fiscal', 'Paragon fiskalny']],
                  'hint' => 'Faktura VAT, gdy klient o nią poprosił lub podał NIP; w przeciwnym razie wybrany paragon. '
                      . 'Pomija zamówienie, które ma już fakturę albo paragon.'
                      . ($hasWfirma ? '' : ' · ⚠ Brak aktywnej integracji wFirma — dodaj ją w Integracjach.')];
    } elseif ($ak === 'mark_paid') {
        $param = null;
    } elseif ($ak === 'set_virtual') {
        $param = ['label' => 'Znacznik', 'options' => [['1', 'Wirtualne — bez wysyłki'], ['0', 'Wymaga wysyłki']]];
    } elseif ($ak === 'forward_order') {
        $param = ['label' => 'Sklep docelowy', 'options' => $wooShops,
                  'hint' => 'Tworzy kopię zamówienia w wybranym sklepie WooCommerce (produkty dopasowane po SKU). Do CRM nie wraca jako nowe zamówienie.'];
    }
    if (array_key_exists('run', $ad)) {
        // Akcja rozszerzenia: lista wyboru z 'options' albo pole tekstowe (bez parametru - bez pola).
        $param = $ad['params'] === [] ? null : array_filter(['label' => $pLabel, 'options' => $ad['options'] ?: null, 'hint' => $ad['hint'] ?: null]);
    }
    $actionUi[$ak] = ['label' => $ad['label'], 'ready' => $ad['ready'], 'param' => $param];
}

// Reguły na liście jako zdania („Gdy … i … → 1. … 2. …”), z nazwami z bazy zamiast kluczy.
$ruleText = new \Pase\Automation\RuleText([
    'status'   => $statusLabels,
    'shipment' => $shipStatusLabels,
    'email'    => array_column($emailTpls, 'name', 'tpl_key'),
    'source'   => array_column($sourceOptions, 1, 0),
    'shop'     => array_column($wooShops, 1, 0),
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int) ($_POST['id'] ?? 0) ?: null;
        $name     = trim($_POST['name'] ?? '');
        $event    = $_POST['event'] ?? '';
        // Nowa reguła trafia na koniec listy; edycja zachowuje miejsce ustawione przeciąganiem.
        $priority = $id ? (int) ($_POST['priority'] ?? 100) : $repo->nextPriority();
        $isActive = isset($_POST['is_active']);

        if (!isset($events[$event])) {
            $event = array_key_first($events);
        }

        // Warunki: tablice równoległe cond_field[] / cond_op[] / cond_value[].
        $conditions = [];
        $cf = $_POST['cond_field'] ?? [];
        $co = $_POST['cond_op'] ?? [];
        $cv = $_POST['cond_value'] ?? [];
        foreach ($cf as $i => $field) {
            $field = (string) $field;
            $val   = trim((string) ($cv[$i] ?? ''));
            $op    = (string) ($co[$i] ?? 'eq');
            if ($field !== '' && $val !== '' && isset($condFields[$field])) {
                $conditions[] = ['field' => $field, 'op' => $op, 'value' => $val];
            }
        }

        // Parametr zdarzenia („na jaki status") zapisujemy jako pierwszy warunek reguły.
        $evParamField = $eventParam[$event] ?? null;
        $evParamValue = trim((string) ($_POST['event_param'] ?? ''));
        if ($evParamField !== null) {
            $conditions = array_values(array_filter($conditions, static fn($c) => !($c['field'] === $evParamField && $c['op'] === 'eq')));
            if ($evParamValue !== '') {
                array_unshift($conditions, ['field' => $evParamField, 'op' => 'eq', 'value' => $evParamValue]);
            }
        }

        // Akcje: act_type[] + act_param[] (jeden parametr per akcja na start).
        $actions = [];
        $at = $_POST['act_type'] ?? [];
        $ap = $_POST['act_param'] ?? [];
        foreach ($at as $i => $type) {
            $type = (string) $type;
            if (!isset($actionDefs[$type])) {
                continue;
            }
            $paramKeys = array_keys($actionDefs[$type]['params']);
            $actions[] = ['type' => $type, 'params' => $paramKeys === [] ? [] : [$paramKeys[0] => trim((string) ($ap[$i] ?? ''))]];
        }

        if ($name === '') {
            // Opis jest opcjonalny - bez niego nazwa = zdarzenie (widoczna jako podpis na liście).
            $name = Catalog::eventLabel($event);
        }
        if ($actions === []) {
            $flashErr = 'Dodaj przynajmniej jedną akcję.';
        } else {
            $match = ($_POST['condition_match'] ?? '') === AutomationRuleRepository::MATCH_ANY
                ? AutomationRuleRepository::MATCH_ANY : AutomationRuleRepository::MATCH_ALL;
            $repo->save($id, $name, $event, $isActive, $priority, $conditions, $actions, $match);
            $flashOk = $id ? 'Zaktualizowano regułę.' : 'Dodano regułę.';
        }
    } elseif ($action === 'delete') {
        $repo->delete((int) $_POST['id']);
        $flashOk = 'Usunięto regułę.';
    } elseif ($action === 'toggle') {
        $repo->setActive((int) $_POST['id'], ($_POST['to'] ?? '') === '1');
        $flashOk = 'Zmieniono stan reguły.';
    } elseif ($action === 'reorder') {
        // Przeciągnięcie reguły na liście (fetch z JS) - zapis kolejności bez przeładowania strony.
        $repo->reorder(array_map('intval', (array) ($_POST['ids'] ?? [])));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($action === 'duplicate') {
        $repo->duplicate((int) $_POST['id']);
        $flashOk = 'Zduplikowano regułę.';
    }
}

// Pobranie reguł odporne na błąd bazy: zamiast wywalać stronę / pustki bez kontekstu,
// pokazujemy jasny komunikat (np. gdy tabela nie istnieje / brak uprawnień).
$rules = [];
$rulesError = null;
try {
    $rules = $repo->all();
} catch (\Throwable $e) {
    $rulesError = $e->getMessage();
    \Pase\Support\Logger::warn('Automatyzacje: nie udało się pobrać reguł: ' . $rulesError);
}

$PAGE_TITLE = 'Automatyzacje';
$PAGE_KEY   = 'automations';
require __DIR__ . '/header.php';

?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>
<?php if ($rulesError !== null): ?>
    <div class="flash err">
        Nie udało się odczytać reguł z bazy danych.
        <span style="color:#a33;font-size:12px">(<?= htmlspecialchars($rulesError) ?>)</span><br>
        Sprawdź połączenie z bazą i czy tabela <code>automation_rules</code> istnieje
        (powstaje automatycznie przy poprawnym połączeniu z bazą).
    </div>
<?php endif; ?>

<div class="auto-head">
    <div class="auto-count"><?= count($rules) ?> z <?= count($rules) ?> pozycji</div>
    <?php if ($canEdit): ?>
        <button class="btn" type="button" onclick="autoNewRule()">＋ Dodaj automatyczną akcję</button>
    <?php endif; ?>
</div>

<!-- Szablon pustego edytora (dla nowej reguły / klonowany do edycji) -->
<template id="auto-editor-tpl">
    <?= renderEditor($events) ?>
</template>

<div class="card" style="padding:0;overflow:visible">
    <table class="auto-table">
        <thead>
            <tr><th style="width:30px"></th><th style="width:48%">KIEDY</th><th>CO SIĘ STANIE</th><th style="width:170px"></th></tr>
        </thead>
        <tbody id="auto-rows">
        <?php if ($rules === [] && $rulesError === null): ?>
            <tr><td colspan="4" style="color:#666;padding:18px;line-height:1.6">
                <strong>Brak reguł automatyzacji.</strong> Kliknij „Dodaj automatyczną akcję", aby utworzyć pierwszą.<br>
                <span style="color:#888;font-size:13px">
                    Uwaga: status zamówienia (np. „W realizacji") bywa ustawiany <strong>automatycznie przy imporcie</strong>
                    na podstawie statusu w sklepie (mapowanie WooCommerce → CRM) — niezależnie od reguł.
                    Reguły dodawaj, gdy potrzebujesz czegoś więcej (inny status niż z Woo, e-mail, warunki itp.).
                </span>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($rules as $r): ?>
            <?php
            $conds = $ruleText->conditions($r);
            $isOn  = !empty($r['is_active']);
            ?>
            <tr class="auto-row<?= $isOn ? '' : ' off' ?>" data-id="<?= (int)$r['id'] ?>">
                <td class="auto-grip"<?= $canEdit ? ' title="Przeciągnij, aby zmienić kolejność"' : '' ?>>⋮⋮</td>
                <td class="auto-when">
                    <?php if ($r['name'] !== Catalog::eventLabel($r['event'])): ?><div class="auto-title"><?= htmlspecialchars($r['name']) ?></div><?php endif; ?>
                    <div class="auto-event-line"><?= htmlspecialchars($ruleText->when($r)) ?><?= match (count($conds)) {
                        0 => '', 1 => ', jeśli ' . htmlspecialchars($conds[0]), default => ' ' . htmlspecialchars($ruleText->joinLabel($r)) . ':',
                    } ?>
                        <?php if (!$isOn): ?><span class="auto-off-badge">wyłączona</span><?php endif; ?>
                    </div>
                    <?php if (count($conds) > 1): ?>
                        <ul class="auto-cond-list<?= count($conds) > 1 && $r['condition_match'] === 'any' ? ' any' : '' ?>">
                            <?php foreach ($conds as $i => $c): ?>
                                <li><?php if ($i > 0): ?><span class="auto-join"><?= $r['condition_match'] === 'any' ? 'lub' : 'i' ?></span><?php endif; ?><?= htmlspecialchars($c) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
                <td class="auto-then">
                    <ol class="auto-act-list">
                        <?php foreach ($r['actions'] as $a): ?>
                            <li<?= Catalog::isActionReady($a['type']) ? '' : ' class="todo"' ?>><?= htmlspecialchars($ruleText->action($a)) ?>
                                <?php if (!Catalog::isActionReady($a['type'])): ?><span class="auto-soon" title="Akcja jeszcze nieobsługiwana przez worker">wkrótce</span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </td>
                <td class="auto-actions">
                    <?php if ($canEdit): ?>
                        <form method="post" style="display:inline" title="<?= $r['is_active'] ? 'Wyłącz' : 'Włącz' ?>">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="to" value="<?= $r['is_active'] ? '0' : '1' ?>">
                            <button class="auto-toggle<?= $r['is_active'] ? ' on' : '' ?>" type="submit"><span></span></button>
                        </form>
                        <button class="auto-ic" type="button" title="Edytuj" onclick="autoEdit(this)">✎</button>
                        <form method="post" style="display:inline" title="Duplikuj">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="duplicate">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="auto-ic" type="submit">⧉</button>
                        </form>
                        <form method="post" style="display:inline" title="Usuń" onsubmit="return confirm('Usunąć regułę?')">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="auto-ic danger" type="submit">✕</button>
                        </form>
                    <?php else: ?>
                        <span class="pill <?= $r['is_active'] ? 'ok' : 'muted' ?>"><?= $r['is_active'] ? 'aktywna' : 'wył.' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <!-- Ukryte dane reguły do wypełnienia edytora (JSON). -->
            <script type="application/json" class="auto-data" data-for="<?= (int)$r['id'] ?>"><?= json_encode([
                'id' => (int)$r['id'], 'name' => $r['name'], 'event' => $r['event'],
                'priority' => (int)$r['priority'], 'is_active' => (bool)$r['is_active'],
                'conditions' => $r['conditions'], 'actions' => $r['actions'], 'condition_match' => $r['condition_match'],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p style="color:#888;font-size:12px;margin-top:10px">
    Reguły jednego zdarzenia wykonują się od góry listy — przeciągnij za ⋮⋮, aby zmienić kolejność.
    SMS wymaga integracji SMSAPI, faktury i paragony — wFirma (Integracje). Automatyzacja nie wystawi drugiej
    faktury ani paragonu dla zamówienia, które ma już dokument sprzedaży.
</p>

<?php
/**
 * Pusty edytor reguły (klonowany w JS dla nowej reguły i do edycji istniejącej).
 * Układ jak w BaseLinkerze: ZDARZENIE + warunki | numerowana lista akcji | włącznik, opis, zapis.
 * Wiersze warunków i akcji buduje JS (autoAddCond / autoAddAction) z definicji AUTO_DEFS.
 */
function renderEditor(array $events): string
{
    ob_start(); ?>
    <td colspan="4" class="auto-editor-cell">
    <form method="post" class="auto-editor" onsubmit="return autoBeforeSubmit(this)">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="priority" value="100">
        <div class="auto-editor-grid">
            <!-- LEWA: zdarzenie + warunki -->
            <div class="auto-col auto-col-cond">
                <div class="auto-event-box">
                    <select name="event" class="auto-event" onchange="autoEventChanged(this)">
                        <?php foreach ($events as $ek => $ev): ?>
                            <option value="<?= $ek ?>"><?= htmlspecialchars($ev['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="auto-event-param" hidden>
                        <label>na</label>
                        <select name="event_param" disabled></select>
                    </div>
                </div>
                <div class="auto-match" hidden>
                    <label>Warunki:</label>
                    <select name="condition_match" onchange="autoMatchChanged(this)">
                        <option value="all">muszą być spełnione wszystkie (i)</option>
                        <option value="any">wystarczy jeden z nich (lub)</option>
                    </select>
                </div>
                <div class="auto-conds" data-match="all"></div>
                <button type="button" class="auto-add" onclick="autoAddCond(this)">Dodaj warunek</button>
                <div class="auto-rule-id"></div>
            </div>
            <!-- ŚRODEK: numerowana lista akcji -->
            <div class="auto-col auto-col-act">
                <div class="auto-acts"></div>
                <button type="button" class="auto-add" onclick="autoAddAction(this)">Dodaj akcję do wykonania</button>
            </div>
            <!-- PRAWA: włącznik, opis, zapis -->
            <div class="auto-col auto-meta">
                <label class="auto-switch" title="Reguła aktywna">
                    <input type="checkbox" name="is_active" checked><span></span>
                </label>
                <input name="name" placeholder="Opis (opcjonalnie)" maxlength="190">
                <div class="auto-save">
                    <button class="btn auto-btn-save" type="submit">Zapisz</button>
                    <button class="btn secondary" type="button" onclick="autoCancel(this)">Anuluj</button>
                </div>
            </div>
        </div>
        <div class="auto-preview" aria-live="polite"></div>
    </form>
    </td>
    <?php
    return (string) ob_get_clean();
}
?>

<style>
.auto-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.auto-count { color:#888; font-size:13px; }
.auto-table { width:100%; border-collapse:collapse; }
.auto-table thead th { text-align:left; font-size:11px; letter-spacing:.04em; color:#9aa3af; font-weight:700; padding:14px 16px; border-bottom:1px solid #eef0f3; }
.auto-row td { padding:14px 16px; border-bottom:1px solid #f1f3f5; vertical-align:top; }
.auto-row:hover { background:#fafbfc; }
.auto-grip { color:#b5bbc3; cursor:grab; user-select:none; letter-spacing:-3px; touch-action:none; }
.auto-grip:hover { color:#555; }
.auto-row.dragging { background:#f3f7ff; box-shadow:0 2px 10px rgba(0,0,0,.12); position:relative; z-index:2; }
.auto-row.dragging .auto-grip { cursor:grabbing; }
body.auto-dragging { cursor:grabbing; user-select:none; }
.auto-order-msg { font-size:12px; color:#1f7a3f; margin-left:10px; }
.auto-order-msg.err { color:#c0392b; }
.auto-row.off td.auto-when, .auto-row.off td.auto-then { opacity:.5; }
.auto-title { font-weight:700; color:#222; margin-bottom:3px; }
.auto-event-line { color:#333; line-height:1.45; }
.auto-off-badge { font-size:11px; color:#777; background:#eef0f3; border-radius:4px; padding:1px 6px; margin-left:6px; }
.auto-cond-list { list-style:none; margin:6px 0 0; padding:0; }
.auto-cond-list li { padding:3px 0 3px 12px; color:#222; line-height:1.4; }
.auto-join { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    color:#1f7a3f; background:#e9f5ee; border-radius:4px; padding:0 5px; margin-right:6px; vertical-align:1px; }
.auto-cond-list.any .auto-join { color:#8a6208; background:#fff4dc; }
.auto-act-list { margin:0; padding-left:22px; }
.auto-act-list li { padding:3px 0; line-height:1.4; color:#222; }
.auto-act-list li::marker { color:#9aa3af; font-size:12px; }
.auto-act-list li.todo { color:#888; }
.auto-match { display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid #e7ebf0; background:#fbfbfc; }
.auto-match[hidden] { display:none; }
.auto-match label { color:#555; font-size:14px; flex:0 0 auto; }
.auto-match select { flex:1; min-width:0; }
.auto-cond-row { position:relative; }
.auto-conds .auto-cond-row + .auto-cond-row::before { content:'I'; position:absolute; top:-9px; left:14px; font-size:10px; font-weight:700;
    color:#1f7a3f; background:#e9f5ee; border-radius:4px; padding:1px 6px; }
.auto-conds[data-match=any] .auto-cond-row + .auto-cond-row::before { content:'LUB'; color:#8a6208; background:#fff4dc; }
.auto-preview { border-top:1px solid #e7ebf0; background:#fff; padding:10px 14px; font-size:13px; color:#444; line-height:1.5; }
.auto-preview:empty { display:none; }
.auto-preview b { color:#222; }
.auto-cond { display:inline-flex; margin:4px 4px 0 0; font-size:12px; border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; }
.auto-cond .k { background:#f6f7f9; padding:2px 8px; color:#555; }
.auto-cond .v { padding:2px 8px; color:#222; }
.auto-act { display:flex; align-items:center; gap:8px; margin:3px 0; }
.auto-act .num { color:#9aa3af; font-size:12px; min-width:16px; }
.auto-act-pill { font-size:12px; border:1px solid #e5e7eb; border-radius:6px; padding:3px 10px; background:#fff; }
.auto-act-pill.todo { border-style:dashed; color:#777; }
.auto-soon { font-size:10px; color:#b8860b; background:#fff7e6; border-radius:4px; padding:1px 5px; }
.auto-actions { white-space:nowrap; text-align:right; }
.auto-ic { background:#fff; border:1px solid #e5e7eb; border-radius:50%; width:30px; height:30px; cursor:pointer; color:#667; margin-left:4px; }
.auto-ic:hover { background:#f0f4ff; color:#1a73e8; }
.auto-ic.danger:hover { background:#fdecec; color:#c0392b; border-color:#f3c4c4; }
.auto-toggle { width:42px; height:22px; border-radius:11px; border:none; background:#cfd4da; position:relative; cursor:pointer; vertical-align:middle; }
.auto-toggle span { position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff; transition:left .15s; }
.auto-toggle.on { background:#22a06b; }
.auto-toggle.on span { left:22px; }

/* ===== Edytor inline (układ jak w BaseLinkerze) ===== */
.auto-editor-cell { background:#fafafa; padding:0 !important; border-bottom:1px solid #e7ebf0; }
.auto-editor-grid { display:grid; grid-template-columns:1fr 1fr 190px; min-height:220px; }
.auto-col { border-right:1px solid #e7ebf0; display:flex; flex-direction:column; }
.auto-col:last-child { border-right:none; }
.auto-event-box { padding:14px 14px 12px; border-bottom:1px solid #e7ebf0; background:#fff; }
.auto-event { width:100%; font-size:15px; padding:10px 12px; }
.auto-event-param { display:flex; align-items:center; gap:10px; margin-top:10px; }
.auto-event-param[hidden] { display:none; }
.auto-event-param label { color:#555; font-size:14px; flex:0 0 auto; }
.auto-event-param select { flex:1; min-width:0; font-size:15px; padding:9px 12px; font-weight:600; }
.auto-ev-val { color:#1f7a3f; }
.auto-conds:empty, .auto-acts:empty { display:none; }
.auto-cond-row { display:flex; gap:8px; align-items:center; padding:12px 14px; border-bottom:1px solid #e7ebf0; background:#fff; flex-wrap:wrap; }
.auto-cond-row .c-field { flex:0 0 190px; min-width:0; }
.auto-cond-row .c-op { flex:0 0 auto; width:auto; }
.auto-cond-row .c-val { flex:1 1 140px; min-width:0; }
.auto-act-row { padding:12px 14px; border-bottom:1px solid #e7ebf0; background:#fff; }
.auto-act-top { display:flex; gap:8px; align-items:center; }
.auto-act-top select { flex:1; min-width:0; }
.auto-num { flex:0 0 auto; background:#e8f0fe; color:#1a56db; font-weight:700; font-size:12px; border-radius:6px; padding:4px 7px; align-self:flex-start; margin-top:6px; }
.auto-param { display:flex; align-items:center; gap:10px; margin:10px 0 0 34px; }
.auto-param[hidden] { display:none; }
.auto-param label { flex:0 0 auto; color:#555; font-size:14px; }
.auto-param > select, .auto-param > input, .auto-param > textarea { flex:1; min-width:0; }
.auto-param > textarea { font:inherit; padding:8px 10px; border:1px solid #ddd; border-radius:8px; resize:vertical; }
.auto-param-hint { margin:6px 0 0 34px; font-size:12px; color:#888; }
.auto-sq { background:#fff; border:1px solid #e5e7eb; border-radius:6px; width:36px; height:36px; cursor:pointer; color:#667; flex:0 0 auto; font-size:15px; }
.auto-sq:hover { background:#f3f4f6; color:#222; }
.auto-sq.del:hover { color:#c0392b; border-color:#f3c4c4; background:#fdf2f2; }
.auto-sq:disabled { opacity:.35; cursor:default; }
.auto-add { background:none; border:0; color:#1f7a3f; font-weight:600; font-size:14px; padding:22px 12px; cursor:pointer; width:100%; }
.auto-add:hover { text-decoration:underline; }
.auto-rule-id { margin-top:auto; padding:10px 14px; color:#aaa; font-size:12px; }
.auto-meta { padding:14px 12px; gap:10px; align-items:stretch; background:#fff; }
.auto-meta input[name=name] { width:100%; }
.auto-switch { align-self:center; position:relative; width:52px; height:28px; cursor:pointer; }
.auto-switch input { opacity:0; width:0; height:0; position:absolute; }
.auto-switch span { position:absolute; inset:0; border-radius:14px; background:#cfd4da; transition:background .15s; }
.auto-switch span::after { content:''; position:absolute; top:3px; left:3px; width:22px; height:22px; border-radius:50%; background:#fff; transition:left .15s; box-shadow:0 1px 2px rgba(0,0,0,.2); }
.auto-switch input:checked + span { background:#22a06b; }
.auto-switch input:checked + span::after { left:27px; }
.auto-switch input:focus-visible + span { outline:2px solid #1a73e8; outline-offset:2px; }
.auto-save { display:flex; gap:6px; }
.auto-save .btn { flex:1; padding-left:8px; padding-right:8px; }
.auto-hint { font-size:12px; color:#888; padding:8px 14px 0; }
@media (max-width:900px){ .auto-editor-grid{ grid-template-columns:1fr; } .auto-col{ border-right:none; border-bottom:1px solid #e7ebf0; } .auto-cond-row .c-field{ flex:1 1 100%; } }
</style>

<script>
// Definicje z serwera: pola warunków (typ + lista wartości), operatory per typ, akcje i ich parametry.
const AUTO_DEFS = <?= json_encode([
    'cond'    => $condDefs,
    'ops'     => $opsByType,
    'actions' => $actionUi,
    'eventHints' => $eventHints,
    'eventParam' => $eventParam,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

const AUTO = { editorTpl: document.getElementById('auto-editor-tpl') };

function autoEl(tag, attrs, children) {
    const el = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
        if (k === 'text') el.textContent = v; else if (k === 'on') Object.entries(v).forEach(([e, f]) => el.addEventListener(e, f));
        else el.setAttribute(k, v);
    });
    (children || []).forEach(c => el.appendChild(c));
    return el;
}
function autoOptions(sel, list, value) {
    sel.innerHTML = '';
    list.forEach(([v, l]) => { const o = autoEl('option', {value: v, text: l}); sel.appendChild(o); });
    if (value !== undefined && value !== null && value !== '') sel.value = value;
}

function autoCloseOpen() {
    document.querySelectorAll('tr.auto-editing').forEach(tr => tr.remove());
    document.querySelectorAll('tr.auto-row.hidden-by-edit').forEach(tr => { tr.style.display=''; tr.classList.remove('hidden-by-edit'); });
}

// ---------- Warunki ----------
function autoAddCond(btn, data) {
    const col = btn.closest('.auto-col');
    const wrap = col.querySelector('.auto-conds');
    const event = col.querySelector('[name="event"]').value;
    // Pola najbardziej pasujące do zdarzenia na górze listy.
    const hint = (AUTO_DEFS.eventHints[event] || []).filter(k => AUTO_DEFS.cond[k]);
    const keys = Object.keys(AUTO_DEFS.cond).sort((a, b) => (hint.includes(b) ? 1 : 0) - (hint.includes(a) ? 1 : 0));
    const field = autoEl('select', {name: 'cond_field[]', class: 'c-field'});
    autoOptions(field, keys.map(k => [k, AUTO_DEFS.cond[k].label]));
    const op = autoEl('select', {name: 'cond_op[]', class: 'c-op'});
    const slot = autoEl('span', {class: 'c-val-slot', style: 'display:contents'});
    const del = autoEl('button', {type: 'button', class: 'auto-sq del', title: 'Usuń warunek', text: '🗑',
        on: {click: () => row.remove()}});
    const row = autoEl('div', {class: 'auto-cond-row'}, [field, op, slot, del]);
    wrap.appendChild(row);
    field.value = (data && data.field) || hint[0] || keys[0];
    field.addEventListener('change', () => autoCondBuild(row));
    autoCondBuild(row, data);
    if (!data) row.querySelector('.c-val').focus();
}

// Pole wartości i operatory zależą od typu pola: lista wyboru / liczba / tekst.
function autoCondBuild(row, data) {
    const field = row.querySelector('.c-field').value;
    const def = AUTO_DEFS.cond[field] || {type: 'text', label: 'wartość'};
    const op = row.querySelector('.c-op');
    autoOptions(op, AUTO_DEFS.ops[def.type] || AUTO_DEFS.ops.text, data ? data.op : undefined);
    const slot = row.querySelector('.c-val-slot');
    slot.innerHTML = '';
    let val;
    if (def.type === 'select') {
        val = autoEl('select', {name: 'cond_value[]', class: 'c-val'});
        let opts = [['', '— wybierz —']].concat(def.options);
        if (data && data.value && !opts.some(o => o[0] === data.value)) opts.push([data.value, data.value]);
        autoOptions(val, opts, data ? data.value : undefined);
    } else {
        val = autoEl('input', {name: 'cond_value[]', class: 'c-val', placeholder: def.label,
            type: def.type === 'number' ? 'number' : 'text', step: '0.01'});
        if (data) val.value = data.value || '';
    }
    slot.appendChild(val);
}

// ---------- Akcje ----------
function autoAddAction(btn, data) {
    const wrap = btn.closest('.auto-col').querySelector('.auto-acts');
    const type = autoEl('select', {name: 'act_type[]'});
    autoOptions(type, Object.entries(AUTO_DEFS.actions).map(([k, a]) => [k, a.label + (a.ready ? '' : ' (wkrótce)')]));
    const num = autoEl('span', {class: 'auto-num'});
    const up = autoEl('button', {type: 'button', class: 'auto-sq', title: 'W górę', text: '↑', on: {click: () => autoMove(row, -1)}});
    const down = autoEl('button', {type: 'button', class: 'auto-sq', title: 'W dół', text: '↓', on: {click: () => autoMove(row, 1)}});
    const del = autoEl('button', {type: 'button', class: 'auto-sq del', title: 'Usuń akcję', text: '🗑',
        on: {click: () => { const w = row.parentNode; row.remove(); autoRenumber(w); }}});
    const top = autoEl('div', {class: 'auto-act-top'}, [type, up, down, del]);
    const param = autoEl('div', {class: 'auto-param'});
    const row = autoEl('div', {class: 'auto-act-row'}, [autoEl('div', {style: 'display:flex;gap:8px'}, [num, autoEl('div', {style: 'flex:1;min-width:0'}, [top, param])])]);
    wrap.appendChild(row);
    type.value = (data && data.type) || 'set_status';
    type.addEventListener('change', () => autoActBuild(row));
    autoActBuild(row, data && data.params ? Object.values(data.params)[0] : '');
    autoRenumber(wrap);
}

function autoActBuild(row, value) {
    const def = AUTO_DEFS.actions[row.querySelector('[name="act_type[]"]').value];
    const param = row.querySelector('.auto-param');
    param.innerHTML = '';
    const p = def.param;
    const old = row.querySelector('.auto-param-hint');
    if (old) old.remove();
    if (!p) {
        // Akcja bez parametru - puste pole trzyma równoległe tablice act_type[] / act_param[].
        param.appendChild(autoEl('input', {type: 'hidden', name: 'act_param[]', value: ''}));
        param.hidden = true;
        return;
    }
    param.hidden = false;
    let input;
    if (p.options) {
        input = autoEl('select', {name: 'act_param[]'});
        autoOptions(input, p.options.length ? p.options : [['', '— brak —']], value);
    } else if (p.textarea) {
        input = autoEl('textarea', {name: 'act_param[]', rows: '3', placeholder: p.placeholder || ''});
        input.value = value || '';
    } else {
        input = autoEl('input', {name: 'act_param[]', placeholder: p.label});
        input.value = value || '';
    }
    param.appendChild(autoEl('label', {text: p.label}));
    param.appendChild(input);
    if (!def.ready) param.appendChild(autoEl('span', {class: 'auto-soon', text: 'wkrótce'}));
    if (p.hint) param.after(autoEl('div', {class: 'auto-param-hint', text: p.hint}));
}

function autoMove(row, dir) {
    const w = row.parentNode;
    if (dir < 0 && row.previousElementSibling) w.insertBefore(row, row.previousElementSibling);
    if (dir > 0 && row.nextElementSibling) w.insertBefore(row.nextElementSibling, row);
    autoRenumber(w);
}
function autoRenumber(wrap) {
    const rows = [...wrap.children];
    rows.forEach((r, i) => {
        r.querySelector('.auto-num').textContent = (i + 1) + '.';
        const b = r.querySelectorAll('.auto-sq');
        b[0].disabled = i === 0; b[1].disabled = i === rows.length - 1; b[2].disabled = rows.length === 1;
    });
}

// ---------- Zdarzenie ----------
// Zdarzenia z „własnym" parametrem (np. zmiana statusu przesyłki - NA JAKI etap)
// od razu dostają odpowiedni warunek, żeby reguła nie odpalała się przy każdej zmianie.
function autoEventChanged(sel, paramValue) {
    const col = sel.closest('.auto-col');
    const box = col.querySelector('.auto-event-param');
    const pSel = box.querySelector('select');
    const pField = AUTO_DEFS.eventParam[sel.value];
    if (pField && AUTO_DEFS.cond[pField]) {
        const def = AUTO_DEFS.cond[pField];
        autoOptions(pSel, [['', '— dowolny —']].concat(def.options), paramValue !== undefined ? paramValue : pSel.dataset.last);
        box.querySelector('label').textContent = pField === 'shipment_status' ? 'Status przesyłki' : 'Status zamówienia';
        box.hidden = false; pSel.disabled = false;
    } else {
        box.hidden = true; pSel.disabled = true;
    }
    const hint = AUTO_DEFS.eventHints[sel.value] || [];
    // Puste warunki podsunięte dla poprzedniego zdarzenia usuwamy (pusty = pomijany przy zapisie).
    col.querySelectorAll('.auto-cond-row').forEach(r => {
        if (r.querySelector('.c-val').value === '' && !hint.includes(r.querySelector('.c-field').value)) r.remove();
    });
    if (!hint.length) return;
    // Podpowiedź tylko w pustym edytorze - istniejącej reguły nie uzupełniamy o pusty warunek.
    if (!col.querySelector('.auto-cond-row')) autoAddCond(col.querySelector('.auto-add'), {field: hint[0], op: 'eq', value: ''});
}

function autoBeforeSubmit(form) {
    if (!form.querySelector('[name="act_type[]"]')) { alert('Dodaj przynajmniej jedną akcję.'); return false; }
    return true;
}

function autoFillEditor(tr, data) {
    const condBtn = tr.querySelector('.auto-col-cond .auto-add');
    const actBtn  = tr.querySelector('.auto-col-act .auto-add');
    if (data) {
        tr.querySelector('[name="event"]').value = data.event || '';
        tr.querySelector('[name="name"]').value = data.name || '';
        tr.querySelector('[name="priority"]').value = data.priority || 100;
        tr.querySelector('[name="is_active"]').checked = !!data.is_active;
        tr.querySelector('[name="id"]').value = data.id || 0;
        tr.querySelector('.auto-rule-id').textContent = 'ID: ' + data.id;
    }
    const evSel = tr.querySelector('[name="event"]');
    const pField = data ? AUTO_DEFS.eventParam[data.event] : null;
    let pVal = '';
    (data && data.conditions || []).forEach(c => {
        if (pField && !pVal && c.field === pField && c.op === 'eq') { pVal = c.value; return; }
        autoAddCond(condBtn, c);
    });
    autoEventChanged(evSel, pVal);
    const acts = (data && data.actions && data.actions.length) ? data.actions : [{type: 'set_status', params: {}}];
    acts.forEach(a => autoAddAction(actBtn, a));
    const form = tr.querySelector('form');
    form.querySelector('[name="condition_match"]').value = (data && data.condition_match === 'any') ? 'any' : 'all';
    // Podgląd zdania i przełącznik „wszystkie / dowolny” odświeżają się przy każdej zmianie w edytorze.
    const refresh = () => autoRefresh(form);
    form.addEventListener('change', refresh);
    form.addEventListener('input', refresh);
    const mo = new MutationObserver(refresh);
    form.querySelectorAll('.auto-conds, .auto-acts').forEach(el => mo.observe(el, {childList: true}));
    refresh();
}

function autoMatchChanged(sel) { autoRefresh(sel.form); }

// Tekst wybranej opcji (albo wartość pola), bez „— wybierz —".
function autoText(el) {
    if (!el) return '';
    if (el.tagName === 'SELECT') { const o = el.selectedOptions[0]; return o && o.value !== '' ? o.textContent.trim() : ''; }
    return el.value.trim();
}

function autoRefresh(form) {
    if (!form) return;
    const match = form.querySelector('[name="condition_match"]');
    const conds = form.querySelector('.auto-conds');
    const rows = [...conds.querySelectorAll('.auto-cond-row')];
    form.querySelector('.auto-match').hidden = rows.filter(r => autoText(r.querySelector('.c-val')) !== '').length < 2;
    conds.dataset.match = match.value;

    let text = 'Gdy ' + autoText(form.querySelector('[name="event"]')).toLowerCase();
    const pSel = form.querySelector('[name="event_param"]');
    if (!pSel.disabled && autoText(pSel)) text += ': „' + autoText(pSel) + '”';
    const parts = rows.map(r => {
        const v = autoText(r.querySelector('.c-val'));
        return v ? autoText(r.querySelector('.c-field')).toLowerCase() + ' ' + autoText(r.querySelector('.c-op')) + ' „' + v + '”' : '';
    }).filter(Boolean);
    if (parts.length) text += ', jeśli ' + parts.join(match.value === 'any' ? ' LUB ' : ' I ');
    const acts = [...form.querySelectorAll('.auto-act-row')].map((r, i) => {
        const p = r.querySelector('.auto-param');
        const val = p && !p.hidden ? autoText(p.querySelector('select, textarea, input')) : '';
        return (i + 1) + '. ' + autoText(r.querySelector('[name="act_type[]"]')).replace(/ \(wkrótce\)$/, '')
            + (val ? ': ' + (val.length > 50 ? val.slice(0, 50) + '…' : val) : '');
    });
    const box = form.querySelector('.auto-preview');
    box.textContent = '';
    box.appendChild(autoEl('b', {text: 'Podgląd: '}));
    box.appendChild(document.createTextNode(text + (acts.length ? ' → ' + acts.join('; ') : '')));
}

function autoNewRule() {
    autoCloseOpen();
    const tr = document.createElement('tr');
    tr.className = 'auto-editing';
    tr.innerHTML = AUTO.editorTpl.innerHTML;
    const tbody = document.getElementById('auto-rows');
    tbody.insertBefore(tr, tbody.firstChild);
    autoFillEditor(tr, null);
}

function autoEdit(btn) {
    autoCloseOpen();
    const row = btn.closest('tr.auto-row');
    const id = row.dataset.id;
    const data = JSON.parse(document.querySelector('.auto-data[data-for="'+id+'"]').textContent);
    const tr = document.createElement('tr');
    tr.className = 'auto-editing';
    tr.innerHTML = AUTO.editorTpl.innerHTML;
    row.classList.add('hidden-by-edit');
    row.style.display = 'none';
    row.parentNode.insertBefore(tr, row.nextSibling);
    autoFillEditor(tr, data);
}

function autoCancel(btn) { autoCloseOpen(); }

// ---------- Kolejność reguł: przeciągnij za uchwyt ⋮⋮ (myszą i palcem) ----------
<?php if ($canEdit): ?>
(function () {
    const tbody = document.getElementById('auto-rows');
    const csrf = <?= json_encode(csrfToken()) ?>;
    const order = () => [...tbody.querySelectorAll('tr.auto-row')].map(r => r.dataset.id);
    let drag = null;

    tbody.addEventListener('pointerdown', e => {
        const grip = e.target.closest('.auto-grip');
        if (!grip || e.button > 0 || tbody.querySelector('tr.auto-editing')) return;
        e.preventDefault();
        const row = grip.closest('tr.auto-row');
        drag = {row, before: order().join(',')};
        row.classList.add('dragging');
        document.body.classList.add('auto-dragging');
        grip.setPointerCapture(e.pointerId);
    });
    tbody.addEventListener('pointermove', e => {
        if (!drag) return;
        const rows = [...tbody.querySelectorAll('tr.auto-row')].filter(r => r !== drag.row);
        // Wstaw przed pierwszym wierszem, którego środek jest poniżej kursora.
        const next = rows.find(r => { const b = r.getBoundingClientRect(); return e.clientY < b.top + b.height / 2; });
        if (next) { if (drag.row.nextElementSibling !== next) tbody.insertBefore(drag.row, next); }
        else if (rows.length) { const last = rows[rows.length - 1]; if (last.nextSibling !== drag.row) last.after(drag.row); }
    });
    const drop = () => {
        if (!drag) return;
        const {row, before} = drag;
        drag = null;
        row.classList.remove('dragging');
        document.body.classList.remove('auto-dragging');
        const ids = order();
        if (ids.join(',') === before) return;
        const body = new URLSearchParams({csrf, action: 'reorder'});
        ids.forEach(id => body.append('ids[]', id));
        const msg = document.querySelector('.auto-order-msg') || document.querySelector('.auto-count').appendChild(autoEl('span', {class: 'auto-order-msg'}));
        fetch(location.pathname, {method: 'POST', body, credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : Promise.reject())
            .then(() => { msg.className = 'auto-order-msg'; msg.textContent = '✓ Zapisano kolejność'; })
            .catch(() => { msg.className = 'auto-order-msg err'; msg.textContent = 'Nie zapisano kolejności — odśwież stronę.'; });
    };
    tbody.addEventListener('pointerup', drop);
    tbody.addEventListener('pointercancel', drop);
})();
<?php endif; ?>
</script>

<?php require __DIR__ . '/footer.php'; ?>
