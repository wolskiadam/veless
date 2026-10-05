<?php
/**
 * Licznik postępu importu produktów (import_products.php).
 * $progressFetching = true: sklep jest jeszcze pobierany (liczby rosną), false: czekamy tylko na worker.
 * Dane z import_products.php?progress=1, odświeżane co 3 s aż do końca.
 */
$progressFetching = $progressFetching ?? false;
?>
<div id="impProgress" data-fetching="<?= $progressFetching ? '1' : '0' ?>" style="margin-top:12px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
        <strong class="ip-title">Zapisywanie do magazynu</strong>
        <span class="ip-pct" style="font-size:22px;font-weight:700">—</span>
    </div>
    <div style="height:10px;background:#eee;border-radius:6px;overflow:hidden;margin:8px 0 10px">
        <div class="ip-bar" style="height:100%;width:0;background:#16a34a;transition:width .4s"></div>
    </div>
    <div class="ip-stats" style="display:flex;gap:18px;flex-wrap:wrap;font-size:14px">
        <span>✅ Zaimportowane: <strong class="ip-done">0</strong></span>
        <span>⏳ Czeka w kolejce: <strong class="ip-pending">0</strong></span>
        <span>⚙️ W trakcie: <strong class="ip-reserved">0</strong></span>
        <span>❌ Błędy: <strong class="ip-failed">0</strong></span>
        <span style="color:#888">Razem: <strong class="ip-total">0</strong></span>
    </div>
    <p class="ip-note" style="font-size:12px;color:#888;margin:8px 0 0"></p>
    <ul class="ip-errors" style="font-size:12px;color:#c5221f;margin:6px 0 0;padding-left:18px"></ul>
</div>
<script>
(function () {
    var box = document.getElementById('impProgress');
    if (!box) return;
    var fetching = box.dataset.fetching === '1';
    function q(c) { return box.querySelector(c); }
    function tick() {
        fetch('import_products.php?progress=1', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
            var finished = d.done + d.failed;
            var pct = d.total > 0 ? Math.floor(finished * 100 / d.total) : 0;
            q('.ip-done').textContent = d.done;
            q('.ip-pending').textContent = d.pending;
            q('.ip-reserved').textContent = d.reserved;
            q('.ip-failed').textContent = d.failed;
            q('.ip-total').textContent = d.total;
            q('.ip-pct').textContent = d.total > 0 ? pct + '%' : '—';
            q('.ip-bar').style.width = pct + '%';
            q('.ip-bar').style.background = d.failed > 0 ? '#b06000' : '#16a34a';

            var note = '';
            var waiting = d.pending + d.reserved;
            if (fetching) {
                note = 'Trwa pobieranie ze sklepu — liczba produktów jeszcze rośnie.';
            } else if (waiting === 0) {
                note = d.total > 0 ? 'Gotowe — wszystkie produkty zostały przetworzone.' : 'Brak produktów do zapisania.';
                q('.ip-title').textContent = d.failed > 0 ? 'Zakończono (z błędami)' : 'Zakończono';
            } else if (d.worker_ago === null || d.worker_ago > 300) {
                note = '⚠️ Worker nie działał od ' + (d.worker_ago === null ? 'dawna' : Math.round(d.worker_ago / 60) + ' min') +
                       ' — produkty czekają w kolejce. Sprawdź cron workera na hostingu.';
            } else {
                note = 'Worker zapisuje produkty w tle (ostatni przebieg ' + Math.round(d.worker_ago / 60) + ' min temu). Możesz zamknąć tę stronę — import dokończy się sam.';
            }
            q('.ip-note').textContent = note;
            q('.ip-errors').innerHTML = '';
            (d.errors || []).forEach(function (e) { var li = document.createElement('li'); li.textContent = e; q('.ip-errors').appendChild(li); });

            if (fetching || waiting > 0) setTimeout(tick, 3000);
        }).catch(function () { setTimeout(tick, 5000); });
    }
    tick();
})();
</script>
