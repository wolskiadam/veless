<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
$orderId = max(0, (int) ($_GET['id'] ?? 0));
$beforeId = max(0, (int) ($_GET['before'] ?? 0));
$conditions = []; $params = [];
if ($orderId) { $conditions[] = 'order_id = ?'; $params[] = $orderId; }
if ($beforeId) { $conditions[] = 'id < ?'; $params[] = $beforeId; }
$sql = 'SELECT * FROM audit_events' . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') . ' ORDER BY id DESC LIMIT 51';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hasMore = count($events) > 50;
if ($hasMore) { array_pop($events); }
$labels = [
    'document.issue_started' => 'Rozpoczęcie wystawiania dokumentu',
    'document.absence_confirmed' => 'Potwierdzenie braku dokumentu w wFirma i odblokowanie',
    'order.status_changed' => 'Zmiana statusu', 'order.items_changed' => 'Zmiana produktów',
    'order.items_restored' => 'Przywrócenie produktów ze sklepu', 'order.contact_changed' => 'Zmiana danych kontaktowych',
    'order.invoice_data_changed' => 'Zmiana danych do faktury',
    'order.lifecycle_changed' => 'Archiwum / kosz / przywrócenie', 'order.deleted' => 'Trwałe usunięcie zamówienia',
    'order.payment_set' => 'Potwierdzenie wpłaty', 'order.created_manually' => 'Dodanie zamówienia ręcznie', 'order.no_shipping_changed' => 'Zamówienie wirtualne (bez wysyłki)', 'order.planned_ship_date_changed' => 'Planowana data nadania', 'order.merged' => 'Scalenie zamówień', 'order.unmerged' => 'Odłączenie scalonego zamówienia',
    'return.created' => 'Zwrot towaru', 'return.restocked' => 'Zwrot przyjęty na stan',
    'return.correction_issued' => 'Korekta faktury (zwrot)', 'return.refunded' => 'Zwrot pieniędzy',
    'allegro.issue_reply' => 'Odpowiedź w dyskusji Allegro', 'allegro.rating_answer' => 'Odpowiedź na ocenę Allegro',
    'document.linked' => 'Zapisanie dokumentu', 'document.unlinked' => 'Usunięcie lokalnego powiązania',
    'document.remote_deleted' => 'Usunięcie dokumentu z wFirma i lokalnego powiązania',
];
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$PAGE_TITLE = 'Historia zmian' . ($orderId ? ' — zamówienie #' . $orderId : '');
$PAGE_KEY = 'orders';
require __DIR__ . '/header.php';
?>
<div class="card">
    <a class="btn secondary" href="<?= $orderId ? 'order_view.php?id=' . $orderId : 'index.php' ?>">Powrót</a>
    <?php if ($orderId): ?><a class="btn secondary" href="order_history.php">Historia wszystkich zamówień</a><?php endif; ?>
    <p>Historia od momentu wdrożenia tej funkcji. Czas UTC. Dane kontaktowe: tylko nazwy zmienionych pól, bez ich wartości.</p>
    <?php if (!$events): ?><p>Brak zarejestrowanych zmian.</p><?php endif; ?>
    <?php foreach ($events as $event): ?>
        <details style="margin:12px 0;padding:12px;border:1px solid #ddd">
            <summary><?= $escape($event['created_at']) ?> · <?= $escape($event['actor_name']) ?>
                <?= $event['actor_id'] ? '(ID ' . (int) $event['actor_id'] . ')' : '' ?> ·
                #<?= (int) $event['order_id'] ?> · <?= $escape($labels[$event['action']] ?? $event['action']) ?></summary>
            <p>Przed:</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape(json_encode(json_decode($event['before_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <p>Po:</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape(json_encode(json_decode($event['after_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </details>
    <?php endforeach; ?>
    <?php if ($hasMore): ?><a class="btn" href="?id=<?= $orderId ?>&amp;before=<?= (int) end($events)['id'] ?>">Starsze wpisy</a><?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
