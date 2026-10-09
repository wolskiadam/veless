<?php
declare(strict_types=1);

/**
 * Wspólny nagłówek + nawigacja panelu PASE.
 * Strona ustawia $PAGE_TITLE i $PAGE_KEY przed include, np.:
 *   $PAGE_TITLE = 'Mapowania SKU'; $PAGE_KEY = 'mappings';
 *   require __DIR__ . '/header.php';
 */

$pageTitle = $PAGE_TITLE ?? 'Veless';
$pageKey   = $PAGE_KEY ?? '';
// Skala interfejsu (Aa w pasku nawigacji) - per konto, jak powiększenie strony w przeglądarce.
$uiScale = (isset($pdo) && function_exists('currentUserId'))
    ? \Pase\Support\UiScale::current($pdo, currentUserId()) : \Pase\Support\UiScale::DEFAULT;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> — Veless</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap">
    <style>
        :root {
            /* Neutralne + akcent (bursztynowy brąz) zamiast gradientu. */
            --bg:#f6f5f2; --surface:#ffffff; --surface-2:#fbfaf7; --line:#e6e3da; --border:var(--line);
            --ink:#22252b; --ink-2:#6d7076; --ink-3:#a1a3a8;
            --accent:#9c6b2e; --accent-hover:#835a24; --accent-soft:#f2e6d0; --accent-ink:#5a3c14;
            --brand:var(--accent); --brand2:var(--accent); /* kompatybilność wsteczna nazw */
            --success-bg:#e3f1e9; --success-ink:#276a49;
            --warn-bg:#fbeed9; --warn-ink:#93630f;
            --danger-bg:#fbe6e1; --danger-ink:#a3341f;
            --muted-bg:#eef0ee; --muted-ink:#5f625d;
            --radius:10px; --radius-sm:8px;
            --shadow:0 1px 2px rgba(30,25,15,.04), 0 6px 18px -10px rgba(30,25,15,.12);
            --font-ui:'Plus Jakarta Sans', system-ui, "Segoe UI", sans-serif;
            --font-num:'IBM Plex Mono', ui-monospace, monospace;
            /* Skala interfejsu; elementy na cały ekran (100vh/100vw) dzielą przez nią, bo zoom skaluje też vh/vw. */
            --ui-zoom:<?= rtrim(rtrim(number_format($uiScale / 100, 2, '.', ''), '0'), '.') ?>;
        }
        html { zoom:var(--ui-zoom); }
        * { box-sizing:border-box; }
        body { margin:0; font-family:var(--font-ui); background:var(--bg); color:var(--ink); }
        input,select,button,textarea { font-family:inherit; }
        /* Linki bez własnej klasy: zamiast domyślnego niebieskiego/fioletowego kolor akcentu, a w tabelach kolor tekstu
           z delikatnym podkreśleniem (jak numer zamówienia na liście). :where() = zerowa specyficzność, więc każda
           reguła strony wygrywa. */
        :where(a:not([class])) { color:var(--accent); text-underline-offset:3px; }
        :where(a:not([class])):hover { color:var(--accent-hover); }
        :where(td a:not([class])) { color:inherit; text-decoration-color:#cbd3e1; }
        :where(td a:not([class])):hover { color:var(--accent); text-decoration-color:currentColor; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
        /* Pełna szerokość jak w sds-generator - bez wąskiego limitu kolumny. */
        .page { width:100%; margin:0; padding:18px 24px 40px; }
        main { padding:0; }
        h1 { font-size:22px; font-weight:800; letter-spacing:-.01em; margin:0 0 18px; }
        .card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:20px; margin-bottom:18px; }
        table { width:100%; border-collapse:collapse; font-size:14px; }
        th,td { text-align:left; padding:9px 10px; border-bottom:1px solid var(--line); }
        th { color:var(--ink-2); font-weight:700; font-size:11.5px; text-transform:uppercase; letter-spacing:.05em; }
        input,select { padding:8px 10px; border:1px solid var(--line); border-radius:7px; font-size:14px; color:var(--ink); background:var(--surface); }
        input:focus,select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
        .btn { background:var(--accent); color:#fff; border:0; padding:9px 16px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; transition:background .15s ease; }
        .btn:hover { background:var(--accent-hover); }
        .btn.secondary { background:var(--muted-bg); color:var(--ink); }
        .btn.secondary:hover { background:#e4e6e2; }
        .btn.danger { background:var(--danger-bg); color:var(--danger-ink); }
        .btn.danger:hover { background:#f7d8d0; }
        .pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; }
        .pill.ok { background:var(--success-bg); color:var(--success-ink); }
        .pill.warn { background:var(--warn-bg); color:var(--warn-ink); }
        .pill.bad { background:var(--danger-bg); color:var(--danger-ink); }
        .pill.muted { background:var(--muted-bg); color:var(--muted-ink); }
        .pill.info { background:var(--info-bg, #e8f0fe); color:var(--info-ink, #1a56db); }
        .flash { padding:12px 14px; border-radius:var(--radius-sm); margin-bottom:16px; font-size:14px; }
        .flash.ok { background:var(--success-bg); color:var(--success-ink); }
        .flash.err { background:var(--danger-bg); color:var(--danger-ink); }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; }
        .stat { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:18px; }
        .stat .num { font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:30px; font-weight:600; }
        .stat .lbl { color:var(--ink-2); font-size:13px; }
        .page-title-row { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 18px; }
        .page-title-row h1 { margin:0; }
        .alert-chips { flex:1; display:flex; flex-wrap:wrap; gap:8px; align-items:center; min-width:0; }
        .alert-chip { display:inline-flex; align-items:center; gap:6px; padding:5px 10px 5px 8px; border-radius:999px; background:var(--surface); border:1px solid var(--line);
            box-shadow:var(--shadow); color:var(--ink); text-decoration:none; font-size:13px; font-weight:600; white-space:nowrap; }
        .alert-chip:hover { border-color:var(--ink-2); }
        .alert-chip .ic { font-size:15px; line-height:1; }
        .alert-chip .n { min-width:20px; padding:1px 6px; border-radius:999px; background:var(--muted-bg); color:var(--ink); font-size:12px; text-align:center; font-variant-numeric:tabular-nums; }
        .alert-chip.err .n { background:#d93025; color:#fff; }
        .alert-chip.err { border-color:#f3c2bc; }
        .alert-chip.ok .n { background:var(--success-bg); color:var(--success-ink); }
        @media (max-width:700px) { .page-title-row { flex-wrap:wrap; } .alert-chips { order:3; flex-basis:100%; } .alert-chip .lb { display:none; } }
    </style>
    <?php // Style strony ładowane w <head>, żeby nic nie „mignęło” przed ich wczytaniem (np. duże ikony). ?>
    <?= $PAGE_HEAD ?? '' ?>
    <?php // Rozszerzenia (extensions/): własne style i skrypty na wszystkich stronach panelu + hak admin.head. ?>
    <?= \Pase\Plugin\Hooks::assetTags('admin') . \Pase\Plugin\Hooks::render('admin.head', $pageKey) ?>
</head>
<body>
    <?= \Pase\Support\Demo::bannerHtml() ?>
    <div class="page">
        <?php require __DIR__ . '/nav.php'; ?>
        <main>
            <div class="page-title-row">
                <h1><?= htmlspecialchars($pageTitle) ?></h1>
                <?php // Ważne sprawy jako ikony (te same dane co dzwoneczek, wypełnia je skrypt w nav.php). ?>
                <div class="alert-chips" id="alertChips" hidden></div>
                <?php if (!empty($PAGE_TITLE_ACTIONS)): ?>
                    <?= $PAGE_TITLE_ACTIONS ?>
                <?php endif; ?>
            </div>

            <?php
            // Przypomnienie o wstrzymanej pracy w tle (System → Obciążenie serwera) - dla osób, które mogą ją wznowić.
            if (isset($pdo) && function_exists('canOpenPage') && function_exists('isAdmin') && (isAdmin() || (function_exists('usesPagePermissions') && usesPagePermissions() && canOpenPage('server_usage.php')))):
                try { $hdrPauses = (new \Pase\Services\ProcessControl(new \Pase\Repository\SettingsRepository($pdo)))->activePauses(); } catch (\Throwable) { $hdrPauses = []; }
                if ($hdrPauses): ?>
                <div class="flash pause-banner">⏸ Wstrzymano pracę w tle:
                    <?= htmlspecialchars(implode(', ', array_map(static fn($p) => \Pase\Services\ProcessControl::PROCESSES[$p][0], array_keys($hdrPauses)))) ?>
                    — <a href="server_usage.php#processes">zarządzaj</a></div>
                <style>.pause-banner { background:var(--warn-bg); color:var(--warn-ink); font-weight:600; } .pause-banner a { color:inherit; }</style>
            <?php endif; endif; ?>

            <?php if (!empty($GLOBALS['pase_handover_away'])): ?>
                <?php // Services\Handover: druga instalacja pracuje, ta jest wstrzymana. ?>
                <div class="flash err" style="font-weight:600">⏸ Praca przeniesiona na <?= $GLOBALS['pase_handover_away']['to'] === 'local' ? 'komputer' : 'online' ?>
                    (od <?= htmlspecialchars((new DateTimeImmutable('@' . (int) $GLOBALS['pase_handover_away']['since']))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i')) ?>).
                    Tutaj tylko podgląd: zamówienia, maile i synchronizacja działają po drugiej stronie.
                    <?php if (function_exists('isAdmin') && isAdmin()): ?> — <a href="handover.php" style="color:inherit">Przeniesienie danych</a><?php endif; ?></div>
            <?php endif; ?>

            <?php if (function_exists('pageViewOnly') && pageViewOnly()): ?>
                <?php // Tryb podglądu (uprawnienia do stron): zapis i tak blokuje serwer, tu tylko czytelny sygnał. ?>
                <div class="flash view-only-banner">👁️ Tryb podglądu - masz dostęp tylko do odczytu. Zapisywanie zmian jest zablokowane.</div>
                <style>
                    .view-only-banner { background:var(--warn-bg); color:var(--warn-ink); font-weight:600; }
                    main form[method="post"] button, main form[method="POST"] button,
                    main form[method="post"] input[type="submit"], main form[method="POST"] input[type="submit"],
                    main .btn.danger { opacity:.4; pointer-events:none; cursor:not-allowed; }
                    main form[method="post"] input:not([type="hidden"]), main form[method="post"] select, main form[method="post"] textarea,
                    main form[method="POST"] input:not([type="hidden"]), main form[method="POST"] select, main form[method="POST"] textarea { pointer-events:none; opacity:.7; background:var(--surface-2); }
                </style>
            <?php endif; ?>

            <?php
            // Komunikaty odłożone przed przekierowaniem (patrz flash() w auth.php).
            // Strony korzystające z PRG nie renderują ich już u siebie.
            // login.php nie includuje auth.php, więc sprawdzamy, czy funkcja istnieje.
            if (function_exists('flashTake')):
                foreach (flashTake() as $flashMessage): ?>
                    <div class="flash <?= $flashMessage['type'] === 'err' ? 'err' : 'ok' ?>"><?= htmlspecialchars($flashMessage['msg']) ?></div>
                <?php endforeach;
            endif; ?>
