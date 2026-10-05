<?php
declare(strict_types=1);

/**
 * Wspólna paginacja list w panelu (Zamówienia, Produkty, Oferty Allegro).
 *
 * Użycie:
 *   $pagerPage  = bieżąca strona (int, od 1)
 *   $pagerPages = liczba stron (int)
 *   $pagerLink  = fn(int $p): string  - URL strony $p z zachowaniem filtrów
 *   require __DIR__ . '/_pager.php';
 *
 * Pokazuje: ← Poprzednia · 1 … 4 5 [6] 7 8 … 20 · Następna → · „Idź do strony [__]”.
 */

/** @var int $pagerPage */
/** @var int $pagerPages */
/** @var callable $pagerLink */

if (($pagerPages ?? 1) <= 1) {
    return;
}
$pagerPage = max(1, min((int) $pagerPage, (int) $pagerPages));

// Numery do pokazania: pierwsza, ostatnia i okno ±2 wokół bieżącej; luki jako „…”.
$pagerNums = array_unique(array_merge([1, $pagerPages], range(max(1, $pagerPage - 2), min($pagerPages, $pagerPage + 2))));
sort($pagerNums);

// Szablon linku do pola „Idź do strony” - podmieniamy znacznik na wpisany numer.
$pagerTpl = $pagerLink(987654321);
?>
<nav class="pager" aria-label="Paginacja">
    <?php if ($pagerPage > 1): ?>
        <a class="btn secondary pager-step" href="<?= htmlspecialchars($pagerLink($pagerPage - 1)) ?>"><?= htmlspecialchars(t('orders.prev')) ?></a>
    <?php endif; ?>

    <span class="pager-nums">
        <?php $prev = 0; foreach ($pagerNums as $n): ?>
            <?php if ($n - $prev > 1): ?><span class="pager-gap">…</span><?php endif; ?>
            <?php if ($n === $pagerPage): ?>
                <span class="pager-num on" aria-current="page"><?= $n ?></span>
            <?php else: ?>
                <a class="pager-num" href="<?= htmlspecialchars($pagerLink($n)) ?>"><?= $n ?></a>
            <?php endif; ?>
        <?php $prev = $n; endforeach; ?>
    </span>

    <?php if ($pagerPage < $pagerPages): ?>
        <a class="btn secondary pager-step" href="<?= htmlspecialchars($pagerLink($pagerPage + 1)) ?>"><?= htmlspecialchars(t('orders.next')) ?></a>
    <?php endif; ?>

    <form class="pager-go" onsubmit="var n=parseInt(this.p.value,10);if(n>=1&&n<=<?= (int) $pagerPages ?>){location.href=this.dataset.tpl.replace('987654321',n);}return false;" data-tpl="<?= htmlspecialchars($pagerTpl) ?>">
        <label><?= htmlspecialchars(t('pager.goto')) ?></label>
        <input name="p" type="number" min="1" max="<?= (int) $pagerPages ?>" placeholder="<?= $pagerPage ?>" aria-label="<?= htmlspecialchars(t('pager.goto')) ?>">
        <span class="pager-of"><?= htmlspecialchars(t('pager.of', ['pages' => $pagerPages])) ?></span>
        <button class="btn secondary" type="submit"><?= htmlspecialchars(t('pager.go')) ?></button>
    </form>
</nav>
<?php if (!defined('PASE_PAGER_CSS')): define('PASE_PAGER_CSS', true); ?>
<style>
.pager{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;justify-content:center;margin-top:16px}
.pager-nums{display:flex;gap:4px;align-items:center}
.pager-num{min-width:34px;height:34px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:14px;text-decoration:none;color:#2c2a26;border:1px solid #e5e2da;background:#fff;box-sizing:border-box}
.pager-num:hover{background:#f5f3ee}
.pager-num.on{background:#8a6a3b;border-color:#8a6a3b;color:#fff;font-weight:700}
.pager-gap{color:#999;padding:0 2px}
.pager-go{display:inline-flex;align-items:center;gap:6px;margin:0;font-size:13px;color:#666}
.pager-go input{width:64px}
</style>
<?php endif; ?>
