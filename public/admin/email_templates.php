<?php
declare(strict_types=1);

/**
 * Szablony e-mail (Konfiguracja → Szablony e-mail). CRUD szablonów używanych
 * w automatyzacjach (akcja „Wyślij e-mail"). Body to HTML; zmienne {{...}}.
 */

use Pase\Repository\EmailTemplateRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo    = new EmailTemplateRepository($pdo);
$flashOk = $flashErr = null;

// Zmienne dostępne w szablonie (do ściągi w panelu).
$vars = [
    'customer_name'  => 'Imię i nazwisko klienta',
    'customer_email' => 'E-mail klienta',
    'order_number'   => 'Numer zamówienia (Woo)',
    'pase_number'    => 'Numer CRM',
    'total'          => 'Wartość zamówienia',
    'currency'       => 'Waluta',
    'status'         => 'Status Woo',
    'shop_name'      => 'Nazwa sklepu (nadawca konta e-mail)',
    'waybill'        => 'Numer przesyłki (ostatniej nadanej, też wpisanej ręcznie)',
    'tracking_link'  => 'Link do śledzenia przesyłki u przewoźnika',
    'carrier'        => 'Przewoźnik (np. InPost)',
    'order_link'     => 'Link do strony zamówienia (w domenie sklepu)',
    'shipment_status'=> 'Status przesyłki',
    'product_list'   => 'Lista produktów (ilość × nazwa)',
    'review_link'    => 'Link do opinii sklepu (Konfiguracja → E-mail)',
    'shop_logo'      => 'Logo sklepu (obrazek, Konfiguracja → E-mail)',
];
$mailAccounts = (new \Pase\Services\MailAccounts($pdo))->all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name     = trim($_POST['name'] ?? '');
        $subject  = trim($_POST['subject'] ?? '');
        $body     = (string) ($_POST['body'] ?? '');
        $isActive = isset($_POST['is_active']);

        if ($name === '' || $subject === '') {
            $flashErr = 'Nazwa i temat są wymagane.';
        } elseif ($action === 'create') {
            $newId = $repo->create($name, $subject, $body, $isActive);
            $repo->setMailAccount($newId, (int) ($_POST['mail_account_id'] ?? 0));
            $repo->setAttachInvoice($newId, isset($_POST['attach_invoice']));
            $flashOk = 'Dodano szablon.';
        } else {
            $repo->update((int) $_POST['id'], $name, $subject, $body, $isActive);
            $repo->setMailAccount((int) $_POST['id'], (int) ($_POST['mail_account_id'] ?? 0));
            $repo->setAttachInvoice((int) $_POST['id'], isset($_POST['attach_invoice']));
            $flashOk = 'Zapisano szablon.';
        }
    } elseif ($action === 'test_send') {
        // Testowa wysyłka zapisanego szablonu na wskazany adres (dane zamówienia albo przykładowe).
        $tpl = $repo->find((int) ($_POST['id'] ?? 0));
        if ($tpl === null) {
            $flashErr = 'Nie ma takiego szablonu.';
        } else {
            $r = (new \Pase\Services\TemplateTestMail($pdo))->send($tpl, (string) ($_POST['test_to'] ?? ''), (string) ($_POST['test_order'] ?? ''));
            if ($r['ok']) { $flashOk = $r['message']; } else { $flashErr = $r['message']; }
        }
        $_GET['edit'] = (string) (int) ($_POST['id'] ?? 0);
    } elseif ($action === 'delete') {
        $repo->delete((int) $_POST['id']);
        $flashOk = 'Usunięto szablon.';
    }
}

$edit = isset($_GET['edit']) ? $repo->find((int) $_GET['edit']) : null;
$list = $repo->all();

$PAGE_TITLE = 'Szablony e-mail';
$PAGE_KEY   = 'email_templates';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="card">
    <strong><?= $edit ? 'Edytuj szablon' : 'Nowy szablon' ?></strong>
    <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
            <div style="flex:1;min-width:240px"><label style="font-size:12px;color:#888;display:block">Nazwa</label>
                <input name="name" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>" style="width:100%" placeholder="np. Zamówienie w realizacji"></div>
            <div style="min-width:240px"><label style="font-size:12px;color:#888;display:block">Wyślij z</label>
                <?php $curAcc = (int) ($edit['mail_account_id'] ?? 0); ?>
                <select name="mail_account_id" style="width:100%">
                    <option value="0">Automatycznie — konto sklepu zamówienia</option>
                    <?php foreach ($mailAccounts as $ma): ?>
                        <option value="<?= (int) $ma['id'] ?>" <?= $curAcc === (int) $ma['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ma['name'] . ' <' . $ma['from_email'] . '>') ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;color:#888;display:block">Aktywny</label>
                <input type="checkbox" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>></div>
            <div title="Przy wysyłce (automatyzacja albo odpowiedź z karty zamówienia) CRM pobiera z wFirma PDF faktury VAT zamówienia i dołącza go do e-maila. Bez wystawionej faktury e-mail nie zostanie wysłany.">
                <label style="font-size:12px;color:#888;display:block">Załącznik</label>
                <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" name="attach_invoice" <?= !empty($edit['attach_invoice']) ? 'checked' : '' ?>> Dołącz fakturę z wFirma (PDF)</label></div>
        </div>

        <div style="margin-top:12px"><label style="font-size:12px;color:#888;display:block">Temat</label>
            <input name="subject" required value="<?= htmlspecialchars($edit['subject'] ?? '') ?>" style="width:100%" placeholder="np. Zamówienie {{order_number}} jest realizowane"></div>

        <div style="margin-top:12px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <label style="font-size:12px;color:#888">Treść</label>
                <div class="ed-tabs">
                    <button type="button" class="ed-tab active" data-mode="visual">Wizualnie</button>
                    <button type="button" class="ed-tab" data-mode="html">HTML</button>
                    <button type="button" class="ed-tab" data-mode="preview">Podgląd</button>
                </div>
            </div>

            <!-- Tryb wizualny (Quill) -->
            <div id="edVisualWrap">
                <div id="edToolbar">
                    <span class="ql-formats">
                        <select class="ql-header"><option value="1"></option><option value="2"></option><option selected></option></select>
                        <button class="ql-bold"></button><button class="ql-italic"></button><button class="ql-underline"></button>
                    </span>
                    <span class="ql-formats">
                        <button class="ql-list" value="ordered"></button><button class="ql-list" value="bullet"></button>
                        <select class="ql-align"></select>
                    </span>
                    <span class="ql-formats">
                        <button class="ql-link"></button><button class="ql-clean"></button>
                    </span>
                </div>
                <div id="edVisual" style="min-height:240px;background:#fff"></div>
            </div>

            <!-- Tryb HTML (źródło) - to pole jest wysyłane w formularzu -->
            <textarea id="edHtml" name="body" rows="14" style="width:100%;font-family:monospace;font-size:13px;display:none" placeholder="<p>Dzień dobry {{customer_name}}...</p>"><?= htmlspecialchars($edit['body'] ?? '') ?></textarea>

            <!-- Podgląd na żywo (przykładowe dane) -->
            <div id="edPreviewWrap" style="display:none">
                <p style="font-size:12px;color:#888;margin:0 0 6px">Podgląd renderuje przykładowe dane w miejsce zmiennych <code>{{...}}</code>.</p>
                <iframe id="edPreview" style="width:100%;height:300px;border:1px solid #e5e7eb;border-radius:8px;background:#fff"></iframe>
            </div>
        </div>

        <p style="margin-top:14px">
            <button class="btn" type="submit" id="edSubmit"><?= $edit ? 'Zapisz szablon' : 'Dodaj szablon' ?></button>
            <?php if ($edit): ?><a class="btn secondary" href="email_templates.php">Anuluj</a><?php endif; ?>
        </p>
    </form>

    <?php if ($edit):
        $testTo = (string) ($_POST['test_to'] ?? '');
        if ($testTo === '') {
            try {
                $st = $pdo->prepare('SELECT email FROM admin_users WHERE id = ?');
                $st->execute([currentUserId()]);
                $testTo = (string) ($st->fetchColumn() ?: '');
            } catch (\Throwable) {}
        }
        if ($testTo === '') {
            $testTo = (string) ((new \Pase\Services\MailAccounts($pdo))->mailerConfig(null, (int) ($edit['mail_account_id'] ?? 0))['from_email'] ?? '');
        }
    ?>
    <form method="post" id="tplTestForm" style="margin-top:14px;border-top:1px solid #eef0f3;padding-top:12px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="test_send">
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <strong style="font-size:13px">Testowa wysyłka</strong>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-top:8px">
            <div style="min-width:240px;flex:1"><label style="font-size:12px;color:#888;display:block">Wyślij na adres</label>
                <input type="email" name="test_to" required value="<?= htmlspecialchars($testTo) ?>" style="width:100%" placeholder="twoj@adres.pl"></div>
            <div style="min-width:180px"><label style="font-size:12px;color:#888;display:block">Dane z zamówienia (opcjonalnie)</label>
                <input name="test_order" value="<?= htmlspecialchars((string) ($_POST['test_order'] ?? '')) ?>" style="width:100%" placeholder="np. 517"></div>
            <button class="btn secondary" type="submit">Wyślij test</button>
        </div>
        <p style="color:#888;font-size:12px;margin-top:6px">Wysyłany jest zapisany szablon, z tematem „[TEST]”. Bez numeru zamówienia zmienne dostają przykładowe dane. Klient niczego nie dostaje.</p>
    </form>
    <?php endif; ?>

    <div style="margin-top:14px;border-top:1px solid #eef0f3;padding-top:12px">
        <strong style="font-size:13px">Dostępne zmienne</strong>
        <span style="color:#888;font-size:12px">— kliknij, aby wstawić do treści</span>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
            <?php foreach ($vars as $k => $desc): ?>
                <button type="button" class="var-chip" data-var="{{<?= $k ?>}}" title="<?= htmlspecialchars($desc) ?>"><code>{{<?= $k ?>}}</code> <span class="var-chip-label"><?= htmlspecialchars(preg_replace('/\s*\(.*$/u', '', $desc)) ?></span></button>
            <?php endforeach; ?>
        </div>
        <p style="color:#888;font-size:12px;margin-top:8px">System automatycznie dołącza wersję tekstową e-maila.</p>
    </div>
</div>

<div class="card">
    <strong>Szablony</strong>
    <table style="margin-top:10px">
        <tr><th>Nazwa</th><th>Klucz</th><th>Temat</th><th>Wysyłka z</th><th>Aktywny</th><th></th></tr>
        <?php $maNames = array_column($mailAccounts, 'name', 'id'); ?>
        <?php if ($list === []): ?>
            <tr><td colspan="6" style="color:#888">Brak szablonów. Dodaj pierwszy powyżej.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $t): ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['name']) ?></strong><?php if (!empty($t['attach_invoice'])): ?> <span class="pill muted" title="Dołącza PDF faktury z wFirma">📎 faktura</span><?php endif; ?></td>
                <td style="font-size:12px;color:#888"><code><?= htmlspecialchars($t['tpl_key']) ?></code></td>
                <td style="font-size:13px;color:#555"><?= htmlspecialchars($t['subject']) ?></td>
                <td style="font-size:13px;color:#555"><?= htmlspecialchars(!empty($t['mail_account_id']) ? ($maNames[$t['mail_account_id']] ?? '—') : 'sklep zamówienia') ?></td>
                <td><?= $t['is_active'] ? '<span class="pill ok">tak</span>' : '<span class="pill muted">nie</span>' ?></td>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="?edit=<?= (int)$t['id'] ?>">Edytuj</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć szablon „<?= htmlspecialchars($t['name']) ?>”?')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">Szablon wybierasz w regule automatyzacji (akcja „Wyślij e-mail"). Wysyłka wymaga konta nadawcy (Konfiguracja → E-mail) — domyślnie z konta przypisanego do sklepu, z którego jest zamówienie.</p>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet" integrity="sha384-ecIckRi4QlKYya/FQUbBUjS4qp65jF/J87Guw5uzTbO1C1Jfa/6kYmd6dXUF6D7i" crossorigin="anonymous">
<style>
    .ed-tabs { display:inline-flex; gap:2px; }
    .ed-tab { background:#eef0f6; border:0; padding:5px 12px; font-size:12px; cursor:pointer; color:#555; border-radius:6px; }
    .ed-tab.active { background:#1a73e8; color:#fff; font-weight:600; }
    #edToolbar { border:1px solid #ccc; border-bottom:0; border-radius:8px 8px 0 0; }
    #edVisual { border:1px solid #ccc; border-radius:0 0 8px 8px; font-size:14px; }
    .ql-editor { min-height:240px; }
    .var-chip { background:#eef4ff; border:1px solid #cfe0ff; border-radius:6px; padding:3px 8px; cursor:pointer; font-size:12px; }
    .var-chip:hover { background:#dbe8ff; }
    .var-chip code { color:#1a73e8; background:none; }
    .var-chip-label { color:#555; }
</style>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" integrity="sha384-utBUCeG4SYaCm4m7GQZYr8Hy8Fpy3V4KGjBZaf4WTKOcwhCYpt/0PfeEe3HNlwx8" crossorigin="anonymous"></script>
<script>
(function () {
    var htmlArea = document.getElementById('edHtml');
    if (!htmlArea) return;

    // Przykładowe dane do podglądu (po stronie klienta, bez wysyłki).
    var SAMPLE = {
        customer_name: 'Jan Kowalski', customer_email: 'jan@example.com',
        order_number: '12345', pase_number: '42', total: '149,00',
        currency: 'PLN', status: 'processing', shop_name: <?= json_encode(($mailAccounts[0]['from_name'] ?? '') ?: 'Sklep') ?>,
        waybill: '620000111222333', tracking_number: '620000111222333', carrier: 'InPost',
        tracking_link: 'https://inpost.pl/sledzenie-przesylek?number=620000111222333', shipment_status: 'Doręczona',
        order_link: 'https://sklep.pl/zamowienie.php?token=abc123',
        product_list: '2 × Świeca sojowa 180 ml<br>1 × Wosk zapachowy',
        review_link: 'https://g.page/r/przyklad/review',
        shop_logo: <?= json_encode((function () use ($pdo) { $ma = new \Pase\Services\MailAccounts($pdo); foreach ($ma->all() as $a) { $h = \Pase\Services\MailAccounts::logoHtml(['logo_url' => $ma->logoUrl($a), 'from_name' => $a['from_name']]); if ($h !== '') { return $h; } } return '<strong style="font-size:20px">LOGO</strong>'; })()) ?>
    };

    // Inicjalizacja Quill.
    var quill = null;
    if (window.Quill) {
        quill = new Quill('#edVisual', {
            theme: 'snow',
            modules: { toolbar: '#edToolbar' }
        });
        // Wstaw startową treść z textarea (źródło prawdy).
        quill.clipboard.dangerouslyPasteHTML(htmlArea.value || '');
        // Każda zmiana w edytorze -> aktualizuj textarea (to ona idzie do POST).
        quill.on('text-change', function () {
            htmlArea.value = quill.root.innerHTML;
        });
    }

    var modeVisual = document.getElementById('edVisualWrap');
    var modePrev   = document.getElementById('edPreviewWrap');
    var prevFrame  = document.getElementById('edPreview');

    function render(html) {
        return html.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, function (m, k) {
            return (k in SAMPLE) ? SAMPLE[k] : '';
        });
    }
    function refreshPreview() {
        var doc = prevFrame.contentDocument || prevFrame.contentWindow.document;
        doc.open();
        doc.write('<!doctype html><meta charset="utf-8"><body style="font-family:system-ui,sans-serif;padding:14px;color:#222">'
            + render(htmlArea.value || '') + '</body>');
        doc.close();
    }

    // Przełączanie zakładek: Wizualnie / HTML / Podgląd.
    document.querySelectorAll('.ed-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var mode = tab.dataset.mode;
            document.querySelectorAll('.ed-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });

            // Zsynchronizuj treść między trybami przed przełączeniem.
            if (mode === 'html' && quill) {
                htmlArea.value = quill.root.innerHTML;
            } else if (mode === 'visual' && quill) {
                quill.clipboard.dangerouslyPasteHTML(htmlArea.value || '');
            }

            modeVisual.style.display = (mode === 'visual') ? '' : 'none';
            htmlArea.style.display   = (mode === 'html')   ? '' : 'none';
            modePrev.style.display   = (mode === 'preview')? '' : 'none';
            if (mode === 'preview') refreshPreview();
        });
    });

    // Gdy edytujesz źródło HTML ręcznie - przy powrocie do wizualnego zsynchronizuje się.
    // Przed wysłaniem formularza upewnij się, że textarea ma aktualną treść z edytora.
    var form = htmlArea.form;
    if (form) {
        form.addEventListener('submit', function () {
            if (quill && modeVisual.style.display !== 'none') {
                htmlArea.value = quill.root.innerHTML;
            }
        });
    }

    // Wstawianie zmiennych klikiem - do aktywnego trybu.
    document.querySelectorAll('.var-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var v = chip.dataset.var;
            if (quill && modeVisual.style.display !== 'none') {
                var range = quill.getSelection(true);
                quill.insertText(range ? range.index : quill.getLength(), v, 'user');
                htmlArea.value = quill.root.innerHTML;
            } else {
                // tryb HTML: wstaw w miejscu kursora textarea
                var s = htmlArea.selectionStart || htmlArea.value.length;
                htmlArea.value = htmlArea.value.slice(0, s) + v + htmlArea.value.slice(s);
                htmlArea.focus();
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
