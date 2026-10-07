<?php
declare(strict_types=1);

/**
 * Zakładki „Dyskusje" i „Oceny" w Wiadomościach Allegro (dołączane z allegro_messages.php).
 * Dane pochodzą z tabel allegro_issues / allegro_ratings (cron co 30 min albo „Odśwież z Allegro");
 * czat dyskusji pobieramy na żywo przy otwarciu sprawy. Problemy z zakupem (typ PROBLEM) to wątki
 * Centrum wiadomości — ich czat i odpowiedź idą przez /messaging (beta.v1), nie /sale/issues.
 *
 * @var string $tab
 * @var \Pase\Services\AllegroFeedback $feedback
 * @var \PasePlugin\Allegro\AllegroClient $client
 * @var callable $e
 * @var int $lastSync
 */

use Pase\Services\AllegroFeedback;
use Pase\Services\AllegroThreads;

if (!defined('PASE_ROOT')) {
    http_response_code(404);
    exit;
}

$tz = new DateTimeZone('Europe/Warsaw');
$fmt = static function (?string $utc) use ($tz): string {
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y H:i');
    } catch (\Throwable) {
        return '';
    }
};
$orderLink = static function (array $row) use ($e): string {
    if ($row['woo_order_id'] !== null && $row['woo_order_id'] !== '') {
        return '<a href="order_view.php?id=' . (int) $row['woo_order_id'] . '">Zamówienie w CRM →</a>';
    }
    return $row['order_ref'] ? '<span class="fb-muted" title="' . $e($row['order_ref']) . '">zamówienia nie ma w CRM</span>' : '';
};
$syncError = (string) ($settings->get(AllegroFeedback::SYNC_ERROR_KEY, '') ?? '');
?>

<div class="fb-bar">
    <span class="fb-muted">
        Dane z Allegro odświeżają się same co <?= AllegroFeedback::SYNC_EVERY_MIN ?> min<?= $lastSync ? ' (ostatnio ' . $e(date('d.m.Y H:i', $lastSync)) . ')' : ' — jeszcze nie pobrano' ?>.
        Dyskusje i oceny wpływają na <a href="allegro_dashboard.php">jakość sprzedaży →</a>
    </span>
    <?php if (canEdit()): ?>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="sync">
            <button class="btn secondary" type="submit">↻ Odśwież z Allegro</button>
        </form>
    <?php endif; ?>
</div>
<?php if ($syncError !== ''): ?>
    <div class="flash err">Ostatnie pobieranie: <?= $e($syncError) ?><?php if (str_contains($syncError, 'token') || str_contains($syncError, 'uprawnienia')): ?> <a href="allegro_connect.php">Połącz konto →</a><?php endif; ?></div>
<?php endif; ?>

<?php if ($tab === 'issues'):
    $filter = (string) ($_GET['f'] ?? '');
    if (!in_array($filter, ['', 'open', 'closed'], true)) { $filter = ''; }
    $issues = $feedback->issues($filter);
    $issueId = trim((string) ($_GET['issue'] ?? ''));
    $issue = $issueId !== '' ? $feedback->issue($issueId) : null;
?>
<div class="al-msg-layout">
    <div class="card" style="padding:8px">
        <div class="fb-filters">
            <?php foreach (['' => 'Wszystkie', 'open' => 'Otwarte', 'closed' => 'Zamknięte'] as $k => $label): ?>
                <a href="allegro_messages.php?tab=issues<?= $k !== '' ? '&f=' . $k : '' ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($issues === []): ?>
            <p class="fb-muted" style="padding:8px">Brak dyskusji, reklamacji i problemów z zakupem.</p>
        <?php endif; ?>
        <?php foreach ($issues as $is):
            $open = AllegroFeedback::isOpen($is);
            $awaits = AllegroFeedback::awaitsSeller($is);
        ?>
            <a class="al-thread fb-issue <?= $issueId === $is['remote_id'] ? 'on' : '' ?> <?= $open ? 'fb-open' : '' ?>"
               href="allegro_messages.php?tab=issues<?= $filter !== '' ? '&f=' . $e($filter) : '' ?>&issue=<?= urlencode((string) $is['remote_id']) ?>">
                <span style="min-width:0">
                    <span class="al-thread-name"><?= $e($is['buyer_login'] ?? 'Kupujący') ?></span>
                    <span class="fb-sub"><?= $e(AllegroFeedback::typeLabel((string) $is['type'])) ?> · <?= $e(AllegroFeedback::statusLabel((string) $is['status'])) ?></span>
                    <?php if ($is['subject']): ?><span class="fb-sub"><?= $e(mb_strimwidth((string) $is['subject'], 0, 60, '…')) ?></span><?php endif; ?>
                </span>
                <?php if ($awaits): ?><span class="al-dot" title="Czeka na Twoją odpowiedź"></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <?php if ($issue === null): ?>
            <p class="fb-muted"><?= $issueId !== '' ? 'Nie znaleziono tej sprawy — odśwież dane z Allegro.' : 'Wybierz dyskusję z listy po lewej. Otwarte sprawy są na górze, kropka oznacza, że kupujący czeka na odpowiedź.' ?></p>
        <?php else:
            $problem = $issue['type'] === AllegroFeedback::TYPE_PROBLEM;
            if ($problem) {
                $me = $client->accountLogin();
                $chat = ['ok' => true, 'items' => $client->threadMessages((string) $issue['remote_id']), 'message' => ''];
                // Rola autora jak w dyskusjach (SELLER = my), także gdy Allegro poda samo USER.
                foreach ($chat['items'] as &$m) {
                    $m['author'] = ['role' => AllegroThreads::authorRole((array) ($m['author'] ?? []), $me)] + (array) ($m['author'] ?? []);
                }
                unset($m);
                $client->markThreadRead((string) $issue['remote_id']);
                \Pase\Services\Notifications::forgetAllegroThread((string) $issue['remote_id']);
            } else {
                $chat = $client->issueChat((string) $issue['remote_id']);
            }
            $msgs = $chat['items'];
            usort($msgs, static fn($a, $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));
            $open = AllegroFeedback::isOpen($issue);
        ?>
            <div class="al-conv-head">
                <span>
                    <strong><?= $e(AllegroFeedback::typeLabel((string) $issue['type'])) ?><?= $issue['reference_number'] ? ' ' . $e($issue['reference_number']) : '' ?></strong>
                    z <strong><?= $e($issue['buyer_login'] ?? 'kupującym') ?></strong>
                    · <span class="fb-badge <?= $open ? 'warn' : '' ?>"><?= $e(AllegroFeedback::statusLabel((string) $issue['status'])) ?></span>
                </span>
                <span><?= $orderLink($issue) ?></span>
            </div>
            <div class="fb-facts">
                <?php if ($issue['subject']): ?><div><span class="fb-muted"><?= $problem ? 'Rodzaj problemu:' : 'Temat:' ?></span> <?= $e($issue['subject']) ?></div><?php endif; ?>
                <?php if ($issue['opened_at']): ?><div><span class="fb-muted">Otwarta:</span> <?= $e($fmt($issue['opened_at'])) ?></div><?php endif; ?>
                <?php if ($open && $issue['status_due_at']): ?><div><span class="fb-muted">Termin:</span> <strong><?= $e($fmt($issue['status_due_at'])) ?></strong></div><?php endif; ?>
                <?php if ($issue['decision_due_at']): ?><div><span class="fb-muted">Decyzja do:</span> <strong><?= $e($fmt($issue['decision_due_at'])) ?></strong></div><?php endif; ?>
                <?php if ($issue['offer_id']): ?><div><a href="https://allegro.pl/oferta/<?= $e($issue['offer_id']) ?>" target="_blank" rel="noopener">Oferta <?= $e($issue['offer_id']) ?> ↗</a></div><?php endif; ?>
            </div>

            <?php if (!$chat['ok']): ?><div class="flash err"><?= $e($chat['message']) ?></div><?php endif; ?>
            <div class="al-conv" id="alConv">
                <?php if ($msgs === [] && $chat['ok']): ?><p class="fb-muted">Brak wiadomości w tej sprawie.</p><?php endif; ?>
                <?php foreach ($msgs as $m):
                    $role = strtoupper((string) ($m['author']['role'] ?? ''));
                    $mine = in_array($role, ['SELLER', 'FULFILLMENT'], true);
                    $who = $role === 'ADMIN' ? 'Allegro' : (string) ($m['author']['login'] ?? '');
                    $text = html_entity_decode((string) ($m['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $files = (array) ($m['attachments'] ?? []);
                    try { $date = (new DateTimeImmutable((string) ($m['createdAt'] ?? 'now')))->setTimezone($tz)->format('d.m.Y H:i'); } catch (\Throwable) { $date = ''; }
                ?>
                    <div class="al-msg <?= $mine ? 'mine' : 'theirs' ?>">
                        <div class="al-who"><?= $mine ? 'Ty' : $e($who !== '' ? $who : 'Kupujący') ?><?= $role === 'ADMIN' ? ' (administrator)' : '' ?> <span>· <?= $e($date) ?></span></div>
                        <div class="al-bubble <?= $mine ? 'mine' : ($role === 'ADMIN' ? 'admin' : 'theirs') ?>">
                            <div class="al-bubble-text"><?= nl2br($e($text)) ?></div>
                            <?php if ($files): ?><div class="al-rel">Załączniki: <?= $e(implode(', ', array_map(static fn($f) => (string) ($f['fileName'] ?? 'załącznik'), array_filter($files, 'is_array')))) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <script>(function(){var c=document.getElementById('alConv'); if(c){c.scrollTop=c.scrollHeight;}})();</script>

            <?php if (canEdit() && $open && (int) $issue['chat_active'] === 1): ?>
            <form method="post" class="al-reply fb-reply" onsubmit="return confirm('Wysłać tę odpowiedź do kupującego na Allegro?');">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="issue_reply">
                <input type="hidden" name="issue_id" value="<?= $e($issue['remote_id']) ?>">
                <textarea name="text" rows="3" placeholder="Odpowiedź dla kupującego (widzi ją kupujący i Allegro)..." required></textarea>
                <div class="fb-reply-actions">
                    <?php if ($issue['type'] === 'DISPUTE'): ?>
                        <select name="type">
                            <?php foreach (AllegroFeedback::MESSAGE_TYPES as $k => $label): ?><option value="<?= $e($k) ?>"><?= $e($label) ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button class="btn" type="submit" style="background:#ff5a00">Wyślij do kupującego</button>
                </div>
            </form>
            <?php elseif (!$open): ?>
                <p class="fb-muted" style="margin-top:12px">Sprawa jest zamknięta — nie można już odpisać.</p>
            <?php elseif ((int) $issue['chat_active'] !== 1): ?>
                <p class="fb-muted" style="margin-top:12px">Allegro nie pozwala teraz pisać w tej sprawie (czat nieaktywny).</p>
            <?php endif; ?>
            <?php if ($problem): ?>
                <p class="fb-muted" style="margin-top:8px">Problem z zakupem jest wątkiem w Centrum wiadomości Allegro — ta sama rozmowa jest też w zakładce <a href="allegro_messages.php?thread=<?= urlencode((string) $issue['remote_id']) ?>">Wiadomości</a>.</p>
            <?php endif; ?>
            <?php if ($issue['type'] === 'CLAIM'): ?>
                <p class="fb-muted" style="margin-top:8px">Uznanie lub odrzucenie reklamacji zrób na Allegro — CRM tylko pokazuje reklamację i pozwala odpisać.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php else: /* oceny */
    $filter = (string) ($_GET['f'] ?? 'unanswered');
    if (!in_array($filter, ['', 'unanswered', 'negative', 'positive'], true)) { $filter = 'unanswered'; }
    $ratings = $feedback->ratings($filter);
?>
<div class="card">
    <div class="fb-filters">
        <?php foreach (['unanswered' => 'Negatywne bez odpowiedzi', 'negative' => 'Negatywne', 'positive' => 'Pozytywne', '' => 'Wszystkie'] as $k => $label): ?>
            <a href="allegro_messages.php?tab=ratings&f=<?= $e($k) ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($ratings === []): ?>
        <p class="fb-muted" style="padding:8px 4px"><?= $filter === 'unanswered' ? 'Brak negatywnych ocen bez odpowiedzi.' : 'Brak ocen.' ?></p>
    <?php endif; ?>
    <?php foreach ($ratings as $r):
        $neg = (int) $r['recommended'] === 0;
    ?>
        <div class="fb-rating <?= $neg ? 'neg' : 'pos' ?>" id="r-<?= $e($r['remote_id']) ?>">
            <div class="fb-rating-head">
                <span><span class="fb-thumb"><?= $neg ? '👎 Nie poleca' : '👍 Poleca' ?></span> · <strong><?= $e($r['buyer_login'] ?? 'Kupujący') ?></strong> · <span class="fb-muted"><?= $e($fmt($r['rated_at'])) ?></span>
                    <?php if ((int) $r['excluded'] === 1): ?><span class="fb-badge" title="Allegro nie wlicza tej oceny do średniej">nie wliczana</span><?php endif; ?>
                </span>
                <span><?= $orderLink($r) ?></span>
            </div>
            <div class="fb-rating-text"><?= $r['comment'] ? nl2br($e($r['comment'])) : '<span class="fb-muted">(bez komentarza)</span>' ?></div>
            <?php if ($r['answer_text']): ?>
                <div class="fb-answer"><strong>Twoja odpowiedź</strong><?= $r['answer_at'] ? ' <span class="fb-muted">· ' . $e($fmt($r['answer_at'])) . '</span>' : '' ?><br><?= nl2br($e($r['answer_text'])) ?></div>
            <?php elseif (canEdit()): ?>
                <details <?= $neg ? 'open' : '' ?>>
                    <summary>Odpowiedz publicznie</summary>
                    <form method="post" class="al-reply" onsubmit="return confirm('Opublikować tę odpowiedź pod oceną na Allegro? Zobaczą ją wszyscy kupujący.');">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="rating_answer">
                        <input type="hidden" name="rating_id" value="<?= $e($r['remote_id']) ?>">
                        <textarea name="text" rows="2" maxlength="1000" placeholder="Odpowiedź będzie widoczna publicznie pod oceną..." required></textarea>
                        <button class="btn" type="submit" style="background:#ff5a00">Opublikuj odpowiedź</button>
                    </form>
                </details>
            <?php endif; ?>
            <?php if ($neg && $r['removal_possible_to'] && $r['removal_possible_to'] > gmdate('Y-m-d H:i:s')): ?>
                <p class="fb-muted" style="margin:6px 0 0;font-size:12px">O usunięcie tej oceny można poprosić do <?= $e($fmt($r['removal_possible_to'])) ?> (na Allegro, po wyjaśnieniu sprawy z kupującym).</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
    .fb-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .fb-muted { color:#888; font-size:13px; }
    .fb-filters { display:flex; gap:6px; flex-wrap:wrap; padding:4px 4px 10px; }
    .fb-filters a { padding:4px 10px; border-radius:14px; background:#f1f3f5; color:#444; text-decoration:none; font-size:12px; }
    .fb-filters a.on { background:#ff5a00; color:#fff; }
    .fb-issue { align-items:flex-start; }
    .fb-issue.fb-open { border-left:3px solid #d93025; }
    .fb-sub { display:block; font-size:12px; color:#777; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .fb-badge { display:inline-block; padding:1px 8px; border-radius:10px; background:#eef0f3; font-size:12px; color:#555; }
    .fb-badge.warn { background:#fdecea; color:#a3341f; }
    .fb-facts { display:flex; gap:16px; flex-wrap:wrap; font-size:13px; margin:0 4px 12px; }
    .al-bubble.admin { background:#eef4ff; color:#1f3a66; border-bottom-left-radius:4px; }
    .al-reply.fb-reply { flex-direction:column; align-items:stretch; }
    .al-reply.fb-reply textarea { flex:none; width:100%; box-sizing:border-box; }
    .fb-reply-actions { display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; }
    .fb-reply-actions select { width:auto; max-width:100%; padding:6px 8px; border:1px solid #ddd; border-radius:8px; }
    .fb-rating { border:1px solid #eef0f3; border-radius:10px; padding:12px 14px; margin-bottom:10px; }
    .fb-rating.neg { border-color:#f3c2bc; background:#fff7f6; }
    .fb-rating-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; font-size:14px; }
    .fb-rating.neg .fb-thumb { color:#a3341f; font-weight:700; }
    .fb-rating.pos .fb-thumb { color:#1c6b36; font-weight:700; }
    .fb-rating-text { margin:8px 0; font-size:14px; line-height:1.45; }
    .fb-answer { background:#f5f7fa; border-radius:8px; padding:8px 10px; font-size:13px; }
    .fb-rating summary { cursor:pointer; color:#ff5a00; font-size:13px; font-weight:600; }
</style>
