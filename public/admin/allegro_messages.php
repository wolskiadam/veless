<?php
declare(strict_types=1);

/**
 * Wiadomości Allegro, w trzech zakładkach:
 *   - Wiadomości (Messaging) — wątki z kupującymi (GET /messaging/threads), podgląd i odpowiedź,
 *   - Dyskusje — dyskusje i reklamacje po zakupie (GET /sale/issues, zapisane przez cron),
 *     czat na żywo (GET /sale/issues/{id}/chat) i odpowiedź (POST /sale/issues/{id}/message),
 *   - Oceny — oceny sprzedaży (GET /sale/user-ratings, zapisane przez cron) i publiczna odpowiedź
 *     (PUT /sale/user-ratings/{id}/answer).
 * Nic nie wychodzi do kupującego bez kliknięcia „Wyślij" przez operatora.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroFeedback;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$feedback = new AllegroFeedback($pdo);
$settings = new SettingsRepository($pdo);

$tab = (string) ($_GET['tab'] ?? 'messages');
if (!in_array($tab, ['messages', 'issues', 'ratings'], true)) {
    $tab = 'messages';
}
$threadId = isset($_GET['thread']) ? trim((string) $_GET['thread']) : '';
$flashOk = $flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? 'message');
    if ($action === 'message') {
        // Odpowiedź w wątku wiadomości.
        $tid  = trim($_POST['thread_id'] ?? '');
        $text = trim($_POST['text'] ?? '');
        if ($tid !== '' && $text !== '') {
            $res = $client->sendMessage($tid, $text);
            if ($res['ok']) { $flashOk = 'Wysłano odpowiedź.'; } else { $flashErr = $res['message']; }
            $threadId = $tid;
        }
    } else {
        if ($action === 'sync') {
            if ($client->bearerToken() === null) {
                flash('Konto Allegro nie jest połączone.', 'err');
            } else {
                $settings->setMany([AllegroFeedback::SYNC_AT_KEY => (string) time()]);
                $r = $feedback->sync($client);
                $settings->setMany([AllegroFeedback::SYNC_ERROR_KEY => implode(' | ', $r['messages'])]);
                flash($r['ok'] ? 'Pobrano z Allegro: dyskusje i reklamacje (' . $r['issues'] . '), zmienione oceny (' . $r['ratings'] . ').'
                    : implode(' ', $r['messages']), $r['ok'] ? 'ok' : 'err');
            }
        } elseif ($action === 'issue_reply') {
            $r = $feedback->replyToIssue($client, (string) ($_POST['issue_id'] ?? ''), (string) ($_POST['text'] ?? ''), (string) ($_POST['type'] ?? 'REGULAR'));
            flash($r['message'], $r['ok'] ? 'ok' : 'err');
        } elseif ($action === 'rating_answer') {
            $r = $feedback->answerRating($client, (string) ($_POST['rating_id'] ?? ''), (string) ($_POST['text'] ?? ''));
            flash($r['message'], $r['ok'] ? 'ok' : 'err');
        }
        redirectAfterPost();
    }
}

$counts = $feedback->counts();
$threads = [];
$threadsRes = ['ok' => true, 'message' => ''];
$messages = [];
if ($tab === 'messages') {
    // Allegro oddaje maks. 20 wątków na raz - pobieramy 3 strony (60 najnowszych rozmów).
    $threadsRes = $client->messageThreads(20, 0);
    $threads    = $threadsRes['threads'];
    for ($off = 20; $threadsRes['ok'] && count($threads) === $off && $off < 60; $off += 20) {
        $more = $client->messageThreads(20, $off);
        if (!$more['ok']) {
            break;
        }
        $threads = array_merge($threads, $more['threads']);
    }
    $messages   = $threadId !== '' ? $client->threadMessages($threadId) : [];
    // Otwarty wątek = przeczytany: oznaczamy go na Allegro i zdejmujemy z dzwoneczka.
    if ($threadId !== '') {
        foreach ($threads as &$t) {
            if ((string) ($t['id'] ?? '') === $threadId && empty($t['read'])) {
                $client->markThreadRead($threadId);
                $t['read'] = true;
            }
        }
        unset($t);
        \Pase\Services\Notifications::forgetAllegroThread($threadId);
    }
}

$e = static fn($v) => htmlspecialchars((string) $v);
$PAGE_TITLE = 'Wiadomości Allegro';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= $e($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= $e($flashErr) ?></div><?php endif; ?>
<?php $lastSync = (int) ($settings->get(AllegroFeedback::SYNC_AT_KEY, '0') ?? '0'); ?>
<nav class="al-tabs">
    <a href="allegro_messages.php" class="<?= $tab === 'messages' ? 'on' : '' ?>">Wiadomości</a>
    <a href="allegro_messages.php?tab=issues" class="<?= $tab === 'issues' ? 'on' : '' ?>">Dyskusje<?php if ($counts['open'] > 0): ?> <span class="al-count <?= $counts['awaiting'] > 0 ? 'hot' : '' ?>" title="Otwarte dyskusje i reklamacje<?= $counts['awaiting'] > 0 ? ' (' . $counts['awaiting'] . ' czeka na Twoją odpowiedź)' : '' ?>"><?= $counts['open'] ?></span><?php endif; ?></a>
    <a href="allegro_messages.php?tab=ratings" class="<?= $tab === 'ratings' ? 'on' : '' ?>">Oceny<?php if ($counts['negative_unanswered'] > 0): ?> <span class="al-count hot" title="Negatywne oceny bez odpowiedzi"><?= $counts['negative_unanswered'] ?></span><?php endif; ?></a>
</nav>

<?php if ($tab !== 'messages'): ?>
    <?php require __DIR__ . '/_allegro_feedback.php'; ?>
<?php else: ?>
<?php if (!$threadsRes['ok']): ?><div class="flash err"><?= $e($threadsRes['message']) ?><?php if (str_contains($threadsRes['message'], 'token') || str_contains($threadsRes['message'], '401')): ?> <a href="allegro_connect.php">Połącz konto →</a><?php endif; ?></div><?php endif; ?>

<div class="al-msg-layout">
    <!-- LEWA: wątki -->
    <div class="card" style="padding:8px">
        <strong style="display:block;padding:8px 8px 10px">Wątki</strong>
        <?php if ($threads === []): ?>
            <p style="color:#888;padding:8px;font-size:13px">Brak wiadomości.</p>
        <?php else: ?>
            <?php foreach ($threads as $t):
                $tid = (string) ($t['id'] ?? '');
                $login = $t['interlocutor']['login'] ?? '(kupujący)';
                $read = !empty($t['read']);
            ?>
                <a class="al-thread <?= $threadId === $tid ? 'on' : '' ?> <?= $read ? '' : 'unread' ?>"
                   href="allegro_messages.php?thread=<?= urlencode($tid) ?>">
                    <span class="al-thread-name"><?= $e($login) ?></span>
                    <?php if (!$read): ?><span class="al-dot" title="Nieprzeczytane"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- PRAWA: wątek -->
    <div class="card">
        <?php if ($threadId === ''): ?>
            <p style="color:#888">Wybierz wątek z listy po lewej.</p>
        <?php elseif ($messages === []): ?>
            <p style="color:#888">Brak wiadomości w tym wątku (lub brak dostępu).</p>
        <?php else: ?>
            <?php
                // Allegro zwraca od najnowszej - pokazujemy jak w komunikatorze: najstarsza u góry.
                usort($messages, static fn($a, $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));
                $tz = new DateTimeZone('Europe/Warsaw');
                $buyer = '';
                foreach ($threads as $t) { if ((string) ($t['id'] ?? '') === $threadId) { $buyer = (string) ($t['interlocutor']['login'] ?? ''); } }
                foreach ($messages as $m) { if ($buyer === '' && !empty($m['author']['isInterlocutor'])) { $buyer = (string) ($m['author']['login'] ?? ''); } }
            ?>
            <div class="al-conv-head">
                <span>Rozmowa z <strong><?= $e($buyer !== '' ? $buyer : 'kupującym') ?></strong></span>
                <span class="al-legend"><i class="lg theirs"></i> kupujący <i class="lg mine"></i> Ty (sprzedawca)</span>
            </div>
            <div class="al-conv" id="alConv">
                <?php foreach ($messages as $m):
                    $a = $m['author'] ?? [];
                    // Allegro: author.isInterlocutor = true → pisał kupujący; false → my (sprzedawca).
                    $mine = isset($a['isInterlocutor']) ? !$a['isInterlocutor'] : (($a['role'] ?? '') === 'SELLER');
                    $who  = (string) ($a['login'] ?? '');
                    $text = html_entity_decode((string) ($m['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    try { $date = (new DateTimeImmutable((string) ($m['createdAt'] ?? 'now')))->setTimezone($tz)->format('d.m.Y H:i'); } catch (\Throwable) { $date = ''; }
                    $rel = $m['relatesTo'] ?? [];
                    $files = $m['attachments'] ?? [];
                ?>
                    <div class="al-msg <?= $mine ? 'mine' : 'theirs' ?>">
                        <div class="al-who"><?= $mine ? 'Ty' . ($who !== '' ? ' (' . $e($who) . ')' : '') : $e($who !== '' ? $who : 'Kupujący') ?> <span>· <?= $e($date) ?></span></div>
                        <div class="al-bubble <?= $mine ? 'mine' : 'theirs' ?>">
                            <div class="al-bubble-text"><?= nl2br($e($text)) ?></div>
                            <?php if (!empty($rel['offer']['id']) || !empty($rel['order']['id'])): ?>
                                <div class="al-rel">Dotyczy:
                                    <?php if (!empty($rel['offer']['id'])): ?><a href="https://allegro.pl/oferta/<?= $e($rel['offer']['id']) ?>" target="_blank" rel="noopener">oferty <?= $e($rel['offer']['id']) ?> ↗</a><?php endif; ?>
                                    <?php if (!empty($rel['order']['id'])): ?> zamówienia <?= $e(substr((string) $rel['order']['id'], 0, 8)) ?>…<?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($files): ?><div class="al-rel">Załączniki: <?= $e(implode(', ', array_map(static fn($f) => (string) ($f['fileName'] ?? 'załącznik'), $files))) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <script>(function(){var c=document.getElementById('alConv'); if(c){c.scrollTop=c.scrollHeight;}})();</script>

            <?php if (canEdit()): ?>
            <form method="post" class="al-reply">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="message">
                <input type="hidden" name="thread_id" value="<?= $e($threadId) ?>">
                <textarea name="text" rows="3" placeholder="Napisz odpowiedź..." required></textarea>
                <button class="btn" type="submit" style="background:#ff5a00">Wyślij</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
    .al-msg-layout { display:grid; grid-template-columns:260px 1fr; gap:16px; align-items:start; }
    .al-thread { display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-radius:8px; text-decoration:none; color:#2c3e50; margin-bottom:3px; }
    .al-thread:hover { background:#f5f7fa; }
    .al-thread.on { background:#fff4ef; }
    .al-thread.unread .al-thread-name { font-weight:700; }
    .al-dot { width:8px; height:8px; border-radius:50%; background:#ff5a00; flex-shrink:0; }
    .al-conv { display:flex; flex-direction:column; gap:10px; max-height:480px; overflow:auto; padding:4px; }
    .al-conv-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:0 4px 10px; border-bottom:1px solid #eef0f3; margin-bottom:10px; font-size:14px; }
    .al-legend { font-size:12px; color:#888; display:flex; align-items:center; gap:6px; }
    .al-legend .lg { display:inline-block; width:12px; height:12px; border-radius:4px; margin-left:6px; }
    .al-legend .lg.theirs { background:#f1f3f5; border:1px solid #dde1e6; }
    .al-legend .lg.mine { background:#ff5a00; }
    .al-msg { display:flex; flex-direction:column; max-width:75%; }
    .al-msg.theirs { align-self:flex-start; }
    .al-msg.mine { align-self:flex-end; align-items:flex-end; }
    .al-who { font-size:12px; font-weight:600; color:#555; margin:0 4px 3px; }
    .al-who span { font-weight:400; color:#999; }
    .al-bubble { padding:9px 13px; border-radius:14px; font-size:14px; line-height:1.45; }
    .al-bubble.theirs { background:#f1f3f5; color:#222; border-bottom-left-radius:4px; }
    .al-bubble.mine { background:#ff5a00; color:#fff; border-bottom-right-radius:4px; }
    .al-rel { font-size:12px; opacity:.8; margin-top:6px; }
    .al-bubble.mine .al-rel a { color:#fff; }
    .al-reply { display:flex; gap:8px; align-items:flex-end; margin-top:14px; border-top:1px solid #eef0f3; padding-top:12px; }
    .al-reply textarea { flex:1; padding:8px 10px; border:1px solid #ddd; border-radius:8px; font-family:inherit; font-size:14px; resize:vertical; }
    .al-tabs { display:flex; gap:4px; border-bottom:1px solid #e3e6ea; margin-bottom:16px; flex-wrap:wrap; }
    .al-tabs a { padding:9px 16px; text-decoration:none; color:#555; border-bottom:2px solid transparent; margin-bottom:-1px; font-weight:600; font-size:14px; }
    .al-tabs a.on { color:#ff5a00; border-bottom-color:#ff5a00; }
    .al-count { display:inline-block; min-width:18px; padding:1px 6px; border-radius:9px; background:#e3e6ea; color:#333; font-size:11px; text-align:center; }
    .al-count.hot { background:#d93025; color:#fff; }
    @media (max-width:900px) { .al-msg-layout { grid-template-columns:1fr; } }
</style>

<?php require __DIR__ . '/footer.php'; ?>
