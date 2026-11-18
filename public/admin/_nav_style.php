<?php
declare(strict_types=1);

/**
 * Style paska nawigacji (nav.php). Dołączane w <head> przez header.php, a nie obok samego menu w <body>:
 * menu (wszystkie zakładki z opisami) to kilkanaście KB HTML, więc przeglądarka potrafiła je narysować,
 * zanim doszła do stylów - przy odświeżaniu wszystkie pozycje mignęły jako surowe linki na całą stronę.
 */
?>
<style>
    .nav-bar { display:flex; justify-content:space-between; align-items:center;
        background:var(--surface); font-family:var(--font-ui); border:1px solid var(--line);
        padding:0 16px 0 8px; border-radius:var(--radius); margin-bottom:25px;
        box-shadow:var(--shadow); min-height:52px; position:relative; z-index:1000; }
    .nav-quick { display:flex; align-items:center; gap:2px; margin-right:4px; flex-shrink:0; }
    .nav-home { display:inline-flex; color:var(--ink-2); text-decoration:none; padding:8px 10px; border-radius:8px; transition:all .15s; flex-shrink:0; }
    .nav-home:hover { color:var(--accent-ink); background:var(--accent-soft); }
    .nav-links { display:contents; gap:2px; align-items:stretch; height:100%; }
    .nav-group { position:relative; }
    .nav-group-btn { display:flex; align-items:center; gap:5px; color:var(--ink-2);
        background:transparent; border:none; padding:14px 14px; font-size:.82em; font-weight:600;
        cursor:pointer; transition:all .15s; white-space:nowrap; font-family:inherit; }
    .nav-group-btn:hover { color:var(--ink); background:var(--surface-2); }
    .nav-arrow { opacity:.55; transition:transform .2s; flex-shrink:0; }
    .nav-badge { background:#c0392b; color:#fff; font-size:.7em; font-weight:700; min-width:18px; height:18px; line-height:18px; text-align:center; border-radius:9px; padding:0 5px; margin-left:2px; }
    .nav-bell { position:relative; display:flex; align-items:center; }
    .nav-bell-btn { position:relative; display:inline-flex; align-items:center; justify-content:center; line-height:0; color:var(--ink-2); background:transparent; border:none; padding:8px 10px; border-radius:8px; cursor:pointer; transition:all .15s; }
    .nav-bell-btn:hover, .nav-bell.open .nav-bell-btn { color:var(--accent-ink); background:var(--accent-soft); }
    .nav-bell-count { position:absolute; top:2px; right:2px; background:#c0392b; color:#fff; font-size:10px; font-weight:700; min-width:16px; height:16px; line-height:16px; text-align:center; border-radius:8px; padding:0 4px; }
    .nav-bell-count[hidden], .nav-bell-pop[hidden] { display:none; }
    .nav-bell.ring .nav-bell-btn svg { animation:bellRing .9s ease; transform-origin:50% 10%; }
    @keyframes bellRing { 0%,100%{transform:rotate(0)} 20%{transform:rotate(14deg)} 40%{transform:rotate(-12deg)} 60%{transform:rotate(8deg)} 80%{transform:rotate(-4deg)} }
    .nav-bell-pop { position:absolute; top:calc(100% + 8px); right:0; left:auto; width:340px; max-width:calc(100vw - 32px); max-height:70vh; overflow:auto; background:var(--surface);
        border-radius:var(--radius); box-shadow:0 10px 34px -10px rgba(20,18,12,.28), 0 2px 8px rgba(20,18,12,.08); z-index:1000; padding:6px 0; }
    .nav-bell-head { display:flex; justify-content:space-between; align-items:center; gap:8px; font-weight:700; font-size:.85em; padding:8px 10px 6px 14px; color:var(--ink); }
    .nav-bell-tools { display:flex; align-items:center; gap:4px; }
    .nav-bell-all, .nav-bell-gear, .nav-bell-x, .nav-bell-read { background:none; border:0; cursor:pointer; font-family:inherit; color:var(--ink-3, #999); border-radius:6px; }
    .nav-bell-all { font-size:.85em; font-weight:600; padding:4px 6px; color:var(--accent-ink); }
    .nav-bell-all:hover, .nav-bell-read:hover { text-decoration:underline; }
    .nav-bell-all[hidden], .nav-bell-prefs[hidden] { display:none; }
    .nav-bell-gear { display:inline-flex; padding:5px; }
    .nav-bell-gear:hover, .nav-bell-gear.on { color:var(--ink); background:var(--surface-2); }
    .nav-bell-prefs { border-top:1px solid var(--line, #eef0f3); padding:8px 14px 10px; font-size:.82em; }
    .nav-bell-prefs label { display:flex; align-items:center; gap:8px; padding:4px 0; color:var(--ink-2); cursor:pointer; font-weight:500; }
    .nav-bell-prefs .hint { color:#999; font-size:.9em; margin:0 0 4px; }
    .nav-bell-push { border-top:1px solid var(--line, #eef0f3); padding:8px 14px 10px; font-size:.82em; color:var(--ink-2); }
    .nav-bell-prefs[hidden] + .nav-bell-push { display:none; }
    .nav-push-txt b { color:var(--ink); }
    .nav-push-btns { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
    .nav-push-btns button { font:inherit; font-weight:600; border:1px solid var(--line); background:var(--surface); color:var(--ink); border-radius:7px; padding:4px 10px; cursor:pointer; }
    .nav-push-btns button.on { background:var(--accent); border-color:var(--accent); color:#fff; }
    .nav-push-btns button:disabled { opacity:.5; cursor:default; }
    .nav-push-msg { margin-top:6px; color:var(--ink); }
    .nav-push-last { margin-top:4px; }
    .nav-push-dev { margin-top:8px; }
    .nav-push-devrow { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:3px 0; }
    .nav-push-devbtn { display:flex; gap:4px; flex-shrink:0; }
    .nav-push-devrow button { font:inherit; border:1px solid var(--line); background:var(--surface); color:var(--ink); border-radius:7px; padding:2px 8px; cursor:pointer; }
    .nav-push-src { margin-top:8px; }
    .nav-push-src label { display:flex; align-items:center; gap:8px; padding:3px 0; cursor:pointer; font-weight:500; }
    .nav-push-remind { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin:-13px 0 20px; padding:10px 14px;
        background:var(--accent-soft); color:var(--ink); border:1px solid var(--line); border-radius:var(--radius); font-family:var(--font-ui); font-size:.88em; }
    .nav-push-remind[hidden] { display:none; }
    .nav-push-remind span { flex:1 1 220px; }
    .nav-push-remind button { font:inherit; font-weight:600; border:1px solid var(--line); background:var(--surface); color:var(--ink); border-radius:7px; padding:6px 12px; cursor:pointer; }
    .nav-push-remind button.on { background:var(--accent); border-color:var(--accent); color:#fff; }
    .nav-push-remind button:disabled { opacity:.5; cursor:default; }
    .nav-bell-read { font-size:.85em; font-weight:500; padding:0 2px; }
    .nav-bell-gh .r { display:flex; align-items:center; gap:8px; }
    .nav-bell-row { display:flex; align-items:center; }
    /* Długa grupa (np. niski stan) przewija się w sobie, reszta grup zostaje widoczna. */
    .nav-bell-rows { max-height:320px; overflow-y:auto; overscroll-behavior:contain; }
    .nav-bell-more { display:block; padding:6px 14px 4px; font-size:.8em; font-weight:600; color:var(--accent-ink); text-decoration:none; }
    .nav-bell-more:hover { text-decoration:underline; }
    .nav-bell-row .nav-bell-it { flex:1; min-width:0; }
    .nav-bell-x { padding:2px 8px 2px 4px; font-size:16px; line-height:1; visibility:hidden; }
    .nav-bell-row:hover .nav-bell-x { visibility:visible; }
    .nav-bell-x:hover { color:#c0392b; }
    .nav-bell-empty { color:#999; font-size:.85em; padding:10px 14px 14px; }
    .nav-bell-grp { border-top:1px solid var(--line, #eef0f3); padding:8px 0 4px; }
    .nav-bell-gh { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:0 14px 4px; font-size:.8em; font-weight:700; color:var(--ink); }
    .nav-bell-gh a { color:inherit; text-decoration:none; }
    .nav-bell-gh a:hover { text-decoration:underline; }
    .nav-bell-gh .n { background:var(--accent-soft); color:var(--accent-ink); border-radius:9px; padding:0 7px; font-size:.95em; }
    .nav-bell-grp.err .nav-bell-gh { color:#b3261e; }
    .nav-bell-grp.err .nav-bell-gh .n { background:#fdecea; color:#b3261e; }
    .nav-bell-it { display:flex; justify-content:space-between; gap:10px; padding:5px 14px; font-size:.8em; color:var(--ink-2); text-decoration:none; }
    .nav-bell-it:hover { background:var(--surface-2); }
    .nav-bell-it .t { color:#999; white-space:nowrap; flex-shrink:0; }
    .nav-msgs { display:inline-flex; align-items:center; gap:3px; text-decoration:none; background:var(--warn-ink); color:#fff;
        font-size:.8em; font-weight:700; padding:5px 10px; border-radius:8px; margin-left:2px; transition:background .15s; }
    .nav-msgs:hover { background:#7a4f0c; }
    .nav-group.open .nav-arrow { transform:rotate(180deg); }
    .nav-group.open .nav-group-btn { color:var(--ink); background:var(--surface-2); }
    /* Panel menu: kolumny z małymi szarymi nagłówkami grup, pozycja = nazwa + jednolinijkowy opis. */
    .nav-dropdown { position:absolute; top:100%; left:0; display:none; background:var(--surface); border:1px solid var(--line);
        border-radius:var(--radius); box-shadow:0 14px 34px -14px rgba(20,18,12,.25); padding:14px 0 10px;
        z-index:1001; animation:navDropIn .15s ease-out; max-width:calc(100vw - 32px); max-height:calc(100vh - 120px); overflow-y:auto; }
    .nav-group.open .nav-dropdown { display:flex; }
    @keyframes navDropIn { from{opacity:0;transform:translateY(-4px);} to{opacity:1;transform:translateY(0);} }
    .nav-col { width:310px; padding:0 14px; box-sizing:border-box; }
    .nav-col + .nav-col { border-left:1px solid var(--line); }
    .nav-section { padding-bottom:10px; }
    .nav-section-title { font-size:.74em; font-weight:500; color:var(--ink-3); padding:6px 10px 4px; }
    .nav-dropdown-item { display:flex; flex-direction:column; gap:2px; padding:7px 10px; text-decoration:none; color:var(--ink);
        border-radius:6px; transition:background .12s; break-inside:avoid; }
    .nav-dropdown-item:hover { background:var(--surface-2); }
    .nav-dropdown-item.active { background:var(--accent-soft); }
    .nav-item-label { font-size:.86em; font-weight:500; color:var(--ink); line-height:1.3; }
    .nav-item-desc { font-size:.74em; color:var(--ink-2); line-height:1.35; }
    .nav-dropdown-item.active .nav-item-label { color:var(--accent-ink); font-weight:600; }
    /* Kanały (podkategorie): nazwa + opis, pozycje kanału w panelu obok. */
    .nav-dropdown.nav-has-subs { overflow:visible; max-height:none; }
    .nav-sub-btn { width:100%; flex-direction:row; align-items:center; justify-content:space-between; gap:12px; background:none; border:0;
        cursor:pointer; font:inherit; text-align:left; }
    .nav-sub-text { display:flex; flex-direction:column; gap:2px; min-width:0; }
    .nav-sub-arrow { color:var(--ink-3); flex-shrink:0; transition:transform .15s; }
    .nav-sub.open > .nav-sub-btn { background:var(--surface-2); }
    .nav-sub.open > .nav-sub-btn .nav-sub-arrow { color:var(--ink-2); transform:translateX(2px); }
    .nav-sub.active > .nav-sub-btn .nav-item-label { color:var(--accent-ink); font-weight:600; }
    .nav-flyout { display:none; position:absolute; top:-1px; left:calc(100% - 1px); min-height:calc(100% + 2px); box-sizing:border-box;
        background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:0 14px 34px -14px rgba(20,18,12,.25);
        padding:14px 0 10px; z-index:1002; }
    .nav-flyout.nav-flip { left:auto; right:calc(100% - 1px); }
    .nav-sub.open > .nav-flyout { display:flex; }
    .nav-user-area { display:flex; align-items:center; gap:10px; flex-shrink:0; }
    .nav-scale { position:relative; display:flex; align-items:center; }
    .nav-scale-btn { display:inline-flex; align-items:baseline; gap:4px; background:transparent; border:0; cursor:pointer; color:var(--ink-2); padding:7px 8px; border-radius:8px; font-family:inherit; }
    .nav-scale-btn .aa { font-weight:800; font-size:.9em; }
    .nav-scale-val { font-size:.72em; font-weight:600; font-variant-numeric:tabular-nums; }
    .nav-scale-btn:hover, .nav-scale.open .nav-scale-btn { color:var(--accent-ink); background:var(--accent-soft); }
    .nav-scale-pop { position:absolute; top:calc(100% + 8px); right:0; width:260px; background:var(--surface); border-radius:var(--radius);
        box-shadow:0 10px 34px -10px rgba(20,18,12,.28), 0 2px 8px rgba(20,18,12,.08); z-index:1000; padding:12px 14px; }
    .nav-scale-pop[hidden] { display:none; }
    .nav-scale-head { font-weight:700; font-size:.85em; margin-bottom:10px; }
    .nav-scale-row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .nav-scale-row .nav-scale-cur { font-size:1.05em; }
    .nav-scale-presets { display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; margin-top:10px; }
    .nav-scale-preset { border:1px solid var(--line); background:var(--surface); border-radius:7px; padding:6px 0; cursor:pointer; font-family:inherit; font-size:.8em; font-weight:600; color:var(--ink); font-variant-numeric:tabular-nums; }
    .nav-scale-preset:hover { border-color:var(--ink-3); background:var(--surface-2); }
    .nav-scale-preset.on { background:var(--accent); border-color:var(--accent); color:#fff; }
    .nav-scale-step { width:30px; height:30px; flex-shrink:0; border:1px solid var(--line); background:var(--surface); border-radius:8px; cursor:pointer; font-size:16px; font-weight:700; color:var(--ink); line-height:1; }
    .nav-scale-step:hover { background:var(--surface-2); border-color:var(--ink-3); }
    .nav-scale-foot { display:flex; justify-content:flex-end; align-items:center; margin-top:10px; font-size:.85em; }
    .nav-scale-cur { font-variant-numeric:tabular-nums; }
    .nav-scale-reset { background:none; border:0; color:var(--accent-ink); font-weight:600; cursor:pointer; font-family:inherit; font-size:1em; padding:2px 0; }
    .nav-scale-reset:hover { text-decoration:underline; }
    .nav-scale-hint { color:var(--ink-2); font-size:.72em; margin:10px 0 0; line-height:1.4; }
    .nav-lang { display:flex; gap:4px; }
    .nav-lang a { color:var(--ink-3); text-decoration:none; font-size:.72em; font-weight:700; padding:2px 5px; border-radius:5px; }
    .nav-lang a.on { color:var(--ink); background:var(--surface-2); }
    .nav-lang a:hover { color:var(--ink); }
    .nav-user-info { color:var(--ink-2); font-size:.8em; font-weight:600; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .nav-user-role { background:var(--muted-bg); color:var(--muted-ink); padding:2px 8px; border-radius:10px; font-size:.85em; margin-left:4px; }
    .logout-btn { display:inline-flex; align-items:center; gap:6px; color:var(--ink-2); text-decoration:none; padding:7px 14px; border-radius:8px; font-size:.8em;
        font-weight:600; background:var(--surface); border:1px solid var(--line); transition:all .15s; }
    .logout-btn:hover { color:var(--ink); background:var(--surface-2); border-color:var(--ink-3); }
    .nav-toggle { display:none; background:var(--surface-2); border:1px solid var(--line); color:var(--ink); font-size:1.4em; padding:6px 10px; border-radius:6px; cursor:pointer; line-height:1; }
    .nav-toggle:hover { background:var(--accent-soft); }
    .nav-account-mobile { display:none; }
    @media (max-width:900px) {
        .nav-bar { flex-wrap:wrap; justify-content:flex-start; padding:10px 14px; gap:6px; }
        .nav-toggle { display:block; order:0; }
        .nav-user-area { order:1; margin-left:auto; }
        .nav-links { order:2; width:100%; flex-direction:column; gap:2px; display:none; padding-top:8px; border-top:1px solid var(--line); }
        .nav-links.open { display:flex; }
        .nav-group-btn { width:100%; justify-content:space-between; padding:10px 12px; border-radius:6px; }
        .nav-group.open .nav-dropdown { display:block; }
        .nav-col { width:auto; padding:0; }
        .nav-col + .nav-col { border-left:0; }
        .nav-dropdown { position:static; width:auto; max-width:none;
            max-height:none; overflow:visible; box-shadow:none; border:0; background:var(--surface-2); border-radius:6px;
            margin:2px 0 4px; padding:6px 4px 2px; animation:none; }
        .nav-section { padding:0 4px 6px; }
        .nav-section-title { padding:6px 12px 2px; }
        .nav-dropdown-item { padding:8px 12px; }
        .nav-dropdown-item:hover { background:var(--accent-soft); }
        .nav-flyout, .nav-flyout.nav-flip { position:static; min-height:0; border:0; box-shadow:none; background:var(--surface);
            border-radius:6px; margin:2px 0 6px 12px; padding:4px 0; }
        .nav-sub.open > .nav-flyout { display:block; }
        .nav-sub.open > .nav-sub-btn .nav-sub-arrow { transform:rotate(90deg); }
    }
    /* Telefon: w pasku zostają ☰, start, dzwonek, skala i ikona wylogowania; reszta konta w rozwijanym menu. */
    @media (max-width:600px) {
        .nav-bar { padding:8px 10px; gap:4px; }
        .nav-user-area { gap:2px; }
        .nav-user-area > .nav-lang, .nav-user-area > .nav-user-info, .nav-user-area > .nav-2fa, .nav-logout-label { display:none; }
        .nav-logout { padding:8px 10px; margin-left:4px; }
        .nav-account-mobile { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:6px; padding:10px 12px 4px; border-top:1px solid var(--line); }
        .nav-account-name { color:var(--ink); font-size:.85em; font-weight:700; flex:1 1 100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .nav-account-mobile .nav-lang { margin-right:auto; }
        .nav-account-mobile .nav-lang a { font-size:.8em; padding:5px 8px; }
    }
    /* Wąski ekran: okienka dzwonka i skali na całą szerokość paska (liczone od .nav-bar), żeby nie wychodziły poza ekran. */
    @media (max-width:700px) {
        .nav-user-area, .nav-bell, .nav-scale { position:static; }
        .nav-bell-pop, .nav-scale-pop { left:0; right:0; width:auto; max-width:none; top:calc(100% + 6px); }
    }
</style>
