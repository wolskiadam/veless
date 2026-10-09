<?php
declare(strict_types=1);

/**
 * Wiadomości Allegro, w trzech zakładkach:
 *   - Wiadomości (Messaging, beta.v1) — wątki z kupującymi (GET /messaging/threads), podgląd i odpowiedź,
 *   - Dyskusje — dyskusje i reklamacje po zakupie (GET /sale/issues, zapisane przez cron),
 *     czat na żywo (GET /sale/issues/{id}/chat) i odpowiedź (POST /sale/issues/{id}/message),
 *     oraz Problemy z zakupem (wątki Centrum wiadomości typu POST_PURCHASE_ISSUE, od 28.10.2026),
 *   - Oceny — oceny sprzedaży (GET /sale/user-ratings, zapisane przez cron) i publiczna odpowiedź
 *     (PUT /sale/user-ratings/{id}/answer).
 * Nic nie wychodzi do kupującego bez kliknięcia „Wyślij" przez operatora.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroFeedback;
use Pase\Services\AllegroInbox;
use Pase\Services\AllegroThreads;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$feedback = new AllegroFeedback($pdo);
$inbox = new AllegroInbox($pdo);
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
                flash($r['ok'] ? 'Pobrano z Allegro: dyskusje, reklamacje i problemy z zakupem (' . $r['issues'] . '), zmienione oceny (' . $r['ratings'] . ').'
                    : implode(' ', $r['messages']), $r['ok'] ? 'ok' : 'err');
            }
        } elseif ($action === 'issue_reply') {
            $r = $feedback->replyToIssue($client, (string) ($_POST['issue_id'] ?? ''), (string) ($_POST['text'] ?? ''), (string) ($_POST['type'] ?? 'REGULAR'));
            flash($r['message'], $r['ok'] ? 'ok' : 'err');
        } elseif ($action === 'thread_archive' || $action === 'thread_restore') {
            $tid = trim((string) ($_POST['thread_id'] ?? ''));
            $ok = $action === 'thread_archive' ? $inbox->archive($tid) : $inbox->restore($tid);
            flash($ok ? ($action === 'thread_archive' ? 'Rozmowa przeniesiona do archiwum.' : 'Rozmowa przywrócona z archiwum.') : 'Nie znaleziono tej rozmowy.', $ok ? 'ok' : 'err');
        } elseif ($action === 'rating_answer') {
            $r = $feedback->answerRating($client, (string) ($_POST['rating_id'] ?? ''), (string) ($_POST['text'] ?? ''));
            flash($r['message'], $r['ok'] ? 'ok' : 'err');
        }
        redirectAfterPost();
    }
}

$counts = $feedback->counts();
$threadsRes = ['ok' => true, 'message' => ''];
$messages = [];
$listFilter = (string) ($_GET['f'] ?? '');
if (!in_array($listFilter, ['', 'unread', 'archive'], true)) {
    $listFilter = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$list = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$inboxCounts = ['unread' => 0, 'archive' => 0];
$open = null;
$openRow = null;
$me = '';
if ($tab === 'messages') {
    // Rozmowy są zapisane w CRM (lista, wyszukiwarka, archiwum). Przy wejściu dociągamy z Allegro
    // tylko najnowszą stronę zmienionych rozmów; starsze i pełne pierwsze pobranie robi cron.
    $threadsRes = $client->bearerToken() !== null
        ? $inbox->sync($client, 1, false)
        : ['ok' => false, 'message' => 'Brak tokenu Allegro — połącz konto.'];
    if ($threadId !== '') {
        $me = $client->accountLogin();
        $open = $client->messageThread($threadId);
        $messages = $client->threadMessages($threadId);
        // Otwarta rozmowa = przeczytana: oznaczamy ją na Allegro i zdejmujemy z dzwoneczka.
        if ($open !== null && empty($open['read'])) {
            $client->markThreadRead($threadId);
        }
        if ($open !== null) {
            $inbox->opened($open, $messages, $me);
        }
        \Pase\Services\Notifications::forgetAllegroThread($threadId);
        $openRow = $inbox->thread($threadId);
    }
    $list = $inbox->page($listFilter, $q, max(1, (int) ($_GET['p'] ?? 1)));
    $inboxCounts = $inbox->counts();
}
$listUrl = static function (array $over = []) use ($listFilter, $q, $list): string {
    $params = array_filter(['f' => $listFilter, 'q' => $q, 'p' => $list['page'] > 1 ? $list['page'] : null] + [], static fn($v) => $v !== null && $v !== '');
    $params = array_filter(array_merge($params, $over), static fn($v) => $v !== null && $v !== '');
    return 'allegro_messages.php' . ($params ? '?' . http_build_query($params) : '');
};

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
        <form method="get" class="al-search">
            <?php if ($listFilter !== ''): ?><input type="hidden" name="f" value="<?= $e($listFilter) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Szukaj: login, nr zamówienia, treść…">
        </form>
        <div class="fb-filters">
            <?php foreach (['' => 'Rozmowy', 'unread' => 'Nieprzeczytane', 'archive' => 'Archiwum'] as $k => $label):
                $n = $k === 'unread' ? $inboxCounts['unread'] : ($k === 'archive' ? $inboxCounts['archive'] : 0); ?>
                <a href="<?= $e($listUrl(['f' => $k, 'p' => null, 'thread' => null])) ?>" class="<?= $listFilter === $k ? 'on' : '' ?>"><?= $e($label) ?><?= $n > 0 ? ' (' . $n . ')' : '' ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($list['rows'] === []): ?>
            <p style="color:#888;padding:8px;font-size:13px"><?= $q !== '' ? 'Nic nie znaleziono.' : ($listFilter === 'archive' ? 'Archiwum jest puste.' : 'Brak rozmów.') ?></p>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $t):
            $tid = (string) $t['remote_id'];
            $read = (int) $t['is_read'] === 1;
            $orderNo = AllegroInbox::orderLabel($t);
            $when = '';
            if ($t['last_message_at']) {
                try { $when = (new DateTimeImmutable($t['last_message_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m H:i'); } catch (\Throwable) {}
            }
        ?>
            <a class="al-thread <?= $threadId === $tid ? 'on' : '' ?> <?= $read ? '' : 'unread' ?>"
               href="<?= $e($listUrl(['thread' => $tid])) ?>">
                <span style="min-width:0;flex:1">
                    <span class="al-thread-top"><span class="al-thread-name"><?= $e($t['buyer_login'] ?: '(kupujący)') ?></span><span class="al-when"><?= $e($when) ?></span></span>
                    <?php if ($t['type'] === AllegroThreads::PROBLEM): ?><span class="al-tag">Problem z zakupem<?= $t['status'] === 'CLOSED' ? ' · zamknięty' : '' ?></span><?php endif; ?>
                    <span class="al-snip"><?= $t['last_role'] === 'SELLER' ? 'Ty: ' : '' ?><?= $e(mb_strimwidth((string) ($t['last_text'] ?? ''), 0, 70, '…')) ?></span>
                    <?php if ($orderNo !== ''): ?><span class="al-snip">Zamówienie <?= $e($orderNo) ?></span><?php endif; ?>
                </span>
                <?php if (!$read): ?><span class="al-dot" title="Nieprzeczytane"></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <?php if ($list['pages'] > 1): ?>
            <div class="al-pager">
                <?php if ($list['page'] > 1): ?><a href="<?= $e($listUrl(['p' => $list['page'] - 1, 'thread' => $threadId ?: null])) ?>">← Nowsze</a><?php else: ?><span></span><?php endif; ?>
                <span><?= $list['page'] ?> / <?= $list['pages'] ?> (<?= $list['total'] ?>)</span>
                <?php if ($list['page'] < $list['pages']): ?><a href="<?= $e($listUrl(['p' => $list['page'] + 1, 'thread' => $threadId ?: null])) ?>">Starsze →</a><?php else: ?><span></span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- PRAWA: wątek -->
    <div class="card">
        <?php if ($threadId === ''): ?>
            <p style="color:#888">Wybierz wątek z listy po lewej.</p>
        <?php elseif ($messages === [] && $openRow === null): ?>
            <p style="color:#888">Brak wiadomości w tym wątku (lub brak dostępu).</p>
        <?php else: ?>
            <?php
                // Allegro zwraca od najnowszej - pokazujemy jak w komunikatorze: najstarsza u góry.
                usort($messages, static fn($a, $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));
                $tz = new DateTimeZone('Europe/Warsaw');
                $buyer = $open !== null ? AllegroThreads::buyerLogin($open, $me) : (string) ($openRow['buyer_login'] ?? '');
                $orderNo = $openRow !== null ? AllegroInbox::orderLabel($openRow) : '';
                foreach ($messages as $m) { if ($buyer === '' && !AllegroThreads::isMine((array) ($m['author'] ?? []), $me)) { $buyer = (string) ($m['author']['login'] ?? ''); } }
            ?>
            <div class="al-conv-head">
                <span>Rozmowa z <strong><?= $e($buyer !== '' ? $buyer : 'kupującym') ?></strong>
                    <?php if ($orderNo !== ''): ?> · <a href="order_view.php?id=<?= (int) $openRow['woo_order_id'] ?>">Zamówienie <?= $e($orderNo) ?> →</a><?php endif; ?>
                    <?php if ($open !== null && AllegroThreads::isProblem($open)): ?> · <a class="al-tag" href="allegro_messages.php?tab=issues&amp;issue=<?= urlencode($threadId) ?>">Problem z zakupem →</a><?php endif; ?></span>
                <?php if (canEdit() && $openRow !== null): $inArchive = (int) $openRow['archived'] === 1; ?>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="<?= $inArchive ? 'thread_restore' : 'thread_archive' ?>">
                        <input type="hidden" name="thread_id" value="<?= $e($threadId) ?>">
                        <button class="btn secondary" type="submit" title="<?= $inArchive ? 'Wróci na listę rozmów' : 'Archiwum jest tylko w CRM — na Allegro rozmowa zostaje bez zmian' ?>"><?= $inArchive ? '↩ Przywróć z archiwum' : '🗄 Do archiwum' ?></button>
                    </form>
                <?php endif; ?>
                <span class="al-legend"><i class="lg theirs"></i> kupujący <i class="lg mine"></i> Ty (sprzedawca)</span>
            </div>
            <div class="al-conv" id="alConv">
                <?php if ($messages === []): ?><p style="color:#888">Nie udało się teraz pobrać tej rozmowy z Allegro.</p><?php endif; ?>
                <?php foreach ($messages as $m):
                    $a = $m['author'] ?? [];
                    // beta.v1: author.role BUYER / SELLER (w zwykłych rozmowach USER - wtedy po loginie konta).
                    $mine = AllegroThreads::isMine((array) $a, $me);
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
    .al-tag { display:block; font-size:11px; font-weight:600; color:#a3341f; text-decoration:none; }
    .al-conv-head a.al-tag { display:inline; font-size:12px; }
    .al-search input { width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #ddd; border-radius:8px; font-size:13px; margin:4px 0 8px; }
    .al-thread-top { display:flex; justify-content:space-between; gap:6px; }
    .al-when { font-size:11px; color:#999; white-space:nowrap; }
    .al-snip { display:block; font-size:12px; color:#777; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .al-pager { display:flex; justify-content:space-between; align-items:center; gap:6px; padding:8px 4px 2px; font-size:12px; color:#777; }
    .al-pager a { color:#ff5a00; text-decoration:none; font-weight:600; }
    .fb-filters { display:flex; gap:6px; flex-wrap:wrap; padding:4px 4px 10px; }
    .fb-filters a { padding:4px 10px; border-radius:14px; background:#f1f3f5; color:#444; text-decoration:none; font-size:12px; }
    .fb-filters a.on { background:#ff5a00; color:#fff; }
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
