<?php
declare(strict_types=1);

namespace Pase\Automation;

use PDO;
use Pase\Services\InvoiceData;
use Pase\Services\OrderIndicators;
use Pase\Support\Logger;

/**
 * Stan zamówienia dla automatyzacji (płatność, wirtualne, faktura, dokumenty) i zdarzenia,
 * które nie wynikają z jednego miejsca w kodzie:
 *  - 'order.paid'     - zamówienie przeszło z nieopłaconego na opłacone (import ze sklepu,
 *                       wpłata wpisana w CRM, link PayU); raz na zamówienie,
 *  - 'invoice.issued' / 'receipt.issued' - po wystawieniu dokumentu w wFirma.
 *
 * „Raz na zamówienie" pilnuje tabela automation_once (klucz zamówienie + zdarzenie), więc
 * ponowny import, cofnięcie i ponowne wpisanie wpłaty itp. nie uruchomią reguł drugi raz.
 * Stare zamówienia, które już były opłacone przed wdrożeniem, nie odpalą zdarzenia, bo
 * zdarzenie wymaga zaobserwowanego przejścia nieopłacone -> opłacone (albo nowego zamówienia).
 */
final class OrderEvents
{
    /** Stany płatności używane w warunkach reguł (klucz => etykieta). */
    public const PAYMENT_STATES = [
        'paid'     => 'Opłacone',
        'partial'  => 'Opłacone częściowo',
        'unpaid'   => 'Nieopłacone',
        'cod'      => 'Za pobraniem',
        'failed'   => 'Płatność nieudana',
        'refunded' => 'Zwrócone',
        'unknown'  => 'Brak danych o płatności',
    ];

    /** Dokumenty sprzedaży traktowane jako „dokument dla klienta” (faktura albo paragon). */
    public const SALES_DOCUMENTS = ['normal', 'receipt', 'receipt_fiscal'];

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS automation_once (
            order_id BIGINT NOT NULL, event_key VARCHAR(40) NOT NULL, created_at VARCHAR(30) NOT NULL,
            PRIMARY KEY (order_id, event_key)
        )" . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    }

    /** Stan płatności (klucz z PAYMENT_STATES) - ta sama logika co ikona płatności na liście zamówień. */
    public static function paymentState(array $order, array $payload): string
    {
        $label = OrderIndicators::forOrder($order, $payload, null)['payment']['label'];
        return match (substr($label, strlen('orders.indicator.'))) {
            'payment_paid', 'payment_paid_manual' => 'paid',
            'payment_partial'  => 'partial',
            'payment_unpaid'   => 'unpaid',
            'payment_cod'      => 'cod',
            'payment_failed'   => 'failed',
            'payment_refunded' => 'refunded',
            default            => 'unknown',
        };
    }

    /**
     * Zamówienie wirtualne (bez wysyłki): znacznik z karty zamówienia / magazynu (no_shipping),
     * a bez niego - sklep podał same wirtualne pozycje albo „nie wymaga wysyłki”.
     */
    public static function isVirtual(array $order, array $payload): bool
    {
        if (array_key_exists('no_shipping', $order) && $order['no_shipping'] !== null && $order['no_shipping'] !== '') {
            return (int) $order['no_shipping'] === 1;
        }
        if (($payload['needs_shipping'] ?? null) === false) {
            return true;
        }
        $items = $payload['line_items'] ?? [];
        if ($items === []) {
            return false;
        }
        foreach ($items as $item) {
            if (($item['virtual'] ?? null) !== true) {
                return false;
            }
        }
        return true;
    }

    /** Klient chce fakturę: zaznaczył to w sklepie / na Allegro albo podał NIP (poprawka z CRM wygrywa). */
    public static function wantsInvoice(array $order, array $payload): bool
    {
        $local = isset($order['local_invoice']) && $order['local_invoice'] !== '' ? (string) $order['local_invoice'] : null;
        $requested = InvoiceData::requested($payload, $local);
        if ($requested !== null) {
            return $requested;
        }
        return trim(InvoiceData::nip($payload, $local)) !== '';
    }

    /**
     * Typy dokumentów wFirma, które zamówienie już ma - wystawione ALBO w trakcie / o niepewnym wyniku
     * (rezerwacja w document_issue_operations), żeby automatyzacja nigdy nie wystawiła drugiego.
     * @return list<string>
     */
    public static function documentTypes(PDO $pdo, int $wooOrderId): array
    {
        $types = [];
        foreach ([
            "SELECT document_type FROM order_documents WHERE woo_order_id = ? AND provider = 'wfirma'",
            'SELECT document_type FROM document_issue_operations WHERE order_id = ?',
        ] as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$wooOrderId]);
                $types = array_merge($types, $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (\Throwable) {
                // starsza baza bez tabeli
            }
        }
        return array_values(array_unique(array_map('strval', $types)));
    }

    /** Czy zamówienie jest teraz opłacone (null = brak zamówienia). Wołać PRZED zmianą, żeby potem wykryć przejście. */
    public static function isPaidNow(PDO $pdo, int $wooOrderId): ?bool
    {
        try {
            $st = $pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return self::paymentState($row, json_decode((string) ($row['payload'] ?? ''), true) ?: []) === 'paid';
    }

    /**
     * Uruchamia reguły 'order.paid', jeśli zamówienie właśnie stało się opłacone.
     * @param bool|null $wasPaid wynik isPaidNow() sprzed zmiany (null = nowe zamówienie)
     * @return bool true = zdarzenie uruchomione
     */
    public static function paidTransition(PDO $pdo, int $wooOrderId, ?bool $wasPaid, ?RuleEngine $engine = null): bool
    {
        if ($wasPaid === true || self::isPaidNow($pdo, $wooOrderId) !== true) {
            return false;
        }
        if ($pdo->inTransaction()) {
            // wFirma nie może wystawiać dokumentu w otwartej transakcji (DocumentIssueGuard) - wołający musi to zrobić po commit.
            Logger::warn("Automatyzacja [order.paid]: pominięto zamówienie {$wooOrderId} - wywołanie w otwartej transakcji.");
            return false;
        }
        if (!self::claimOnce($pdo, $wooOrderId, 'order.paid')) {
            return false;
        }
        self::fire($pdo, 'order.paid', $wooOrderId, [], $engine);
        return true;
    }

    /** Zdarzenie po wystawieniu dokumentu w wFirma ('invoice.issued' dla faktury VAT, 'receipt.issued' dla paragonu). */
    public static function documentIssued(PDO $pdo, int $wooOrderId, string $docType): void
    {
        $event = match ($docType) {
            'normal' => 'invoice.issued',
            'receipt', 'receipt_fiscal' => 'receipt.issued',
            default => null,
        };
        if ($event !== null && !$pdo->inTransaction()) {
            self::fire($pdo, $event, $wooOrderId, ['document_type' => $docType]);
        }
    }

    /** Zapisuje, że zdarzenie dla zamówienia już było. false = było wcześniej (albo brak tabeli). */
    public static function claimOnce(PDO $pdo, int $wooOrderId, string $event): bool
    {
        try {
            $pdo->prepare('INSERT INTO automation_once (order_id, event_key, created_at) VALUES (?, ?, ?)')
                ->execute([$wooOrderId, $event, gmdate('Y-m-d H:i:s')]);
            return true;
        } catch (\PDOException) {
            return false;   // duplikat klucza = już uruchomione
        }
    }

    /** Reguły zdarzenia dla zamówienia; błąd reguł nigdy nie przerywa operacji, która je wywołała. */
    private static function fire(PDO $pdo, string $event, int $wooOrderId, array $extra, ?RuleEngine $engine = null): void
    {
        if ($engine === null) {
            \Pase\Services\ShipmentTracking::fire($pdo, $event, $wooOrderId, $extra);
            return;
        }
        try {
            $st = $pdo->prepare('SELECT payload FROM woo_orders WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $engine->dispatch($event, $wooOrderId, $extra + (json_decode((string) ($st->fetchColumn() ?: ''), true) ?: []));
        } catch (\Throwable $e) {
            Logger::warn("Automatyzacja [{$event}] dla zamówienia {$wooOrderId} nie powiodła się: " . $e->getMessage());
        }
    }
}
