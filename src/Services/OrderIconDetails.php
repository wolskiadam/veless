<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Szczegóły do okienka „Opis ikon zamówienia” na liście zamówień (jak w BaseLinkerze):
 * dokumenty z wFirma (faktura / paragon) i przesyłki z linkiem do śledzenia.
 * Czysta logika bez bazy - dane podaje public/admin/order_icons.php.
 */
final class OrderIconDetails
{
    /** Ikony wskaźników (wspólne dla wiersza listy i okienka). */
    public const ICON_PATHS = [
        'receipt' => '<path d="M5 3l2 2 2-2 3 2 3-2 2 2 2-2v18l-2-2-2 2-3-2-3 2-2-2-2 2V3Z"/><path d="M8 9h8M8 13h8M8 17h4"/>',
        'payment' => '<path d="M12 3v18m5-14H9a3 3 0 0 0 0 6h6a3 3 0 0 1 0 6H6"/>',
        'invoice' => '<path d="M6 3h9l4 4v14l-3-2-3 2-3-2-4 2V3Z"/><path d="M14 3v5h5M9 11h7M9 15h7"/>',
        'shipping' => '<path d="M3 5h11v12H3zM14 10h4l3 4v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
        'note' => '<path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 9h8M8 12h5"/>',
    ];

    /** Skróty dopisywane do podobnych ikon faktury i paragonu. */
    public const ICON_TEXT = ['invoice' => 'FV', 'receipt' => 'PAR'];

    /**
     * Wszystkie ikony, jakie może pokazać lista (bez „muted” - tych lista nie rysuje).
     * Etykieta to klucz tłumaczenia albo gotowy tekst (literal).
     * @var list<array{kind:string,state:string,label:string,literal?:bool}>
     */
    public const LEGEND = [
        ['kind' => 'payment', 'state' => 'bad', 'label' => 'orders.indicator.payment_unpaid'],
        ['kind' => 'payment', 'state' => 'bad', 'label' => 'orders.indicator.payment_failed'],
        ['kind' => 'payment', 'state' => 'warn', 'label' => 'orders.indicator.payment_cod'],
        ['kind' => 'payment', 'state' => 'partial', 'label' => 'orders.indicator.payment_partial'],
        ['kind' => 'payment', 'state' => 'ok', 'label' => 'orders.indicator.payment_paid'],
        ['kind' => 'payment', 'state' => 'ok', 'label' => 'orders.indicator.payment_paid_manual'],
        ['kind' => 'invoice', 'state' => 'ok', 'label' => 'Faktura wystawiona', 'literal' => true],
        ['kind' => 'invoice', 'state' => 'warn', 'label' => 'orders.indicator.invoice_required'],
        ['kind' => 'invoice', 'state' => 'warn', 'label' => 'orders.indicator.invoice_nip'],
        ['kind' => 'receipt', 'state' => 'ok', 'label' => 'orders.indicator.receipt_issued'],
        ['kind' => 'receipt', 'state' => 'warn', 'label' => 'orders.indicator.receipt_missing'],
        ['kind' => 'shipping', 'state' => 'warn', 'label' => 'orders.indicator.shipping_needed'],
        ['kind' => 'shipping', 'state' => 'info', 'label' => 'orders.indicator.shipping_created'],
        ['kind' => 'shipping', 'state' => 'info', 'label' => 'Wysyłka razem z zamówieniem głównym (scalone)', 'literal' => true],
        ['kind' => 'shipping', 'state' => 'transit', 'label' => 'orders.indicator.shipping_sent'],
        ['kind' => 'shipping', 'state' => 'transit', 'label' => 'orders.indicator.shipping_in_transit'],
        ['kind' => 'shipping', 'state' => 'ok', 'label' => 'orders.indicator.shipping_delivered'],
        ['kind' => 'shipping', 'state' => 'bad', 'label' => 'orders.indicator.shipping_problem'],
        ['kind' => 'note', 'state' => 'note', 'label' => 'Klient dopisał uwagę do zamówienia', 'literal' => true],
    ];

    private const DOC_LABELS = [
        'normal' => 'Wystawiono fakturę',
        'receipt' => 'Wystawiono paragon',
        'receipt_fiscal' => 'Wystawiono paragon fiskalny',
        'proforma' => 'Wystawiono proformę',
    ];

    /** Postęp paska przesyłki (0-100) dla etapów ShipmentTracking. */
    private const PROGRESS = [
        'pending' => 15, 'in_transit' => 50, 'out_for_delivery' => 75,
        'ready_for_pickup' => 85, 'notice_left' => 85, 'delivered' => 100, 'returned' => 100, 'issue' => 60,
    ];

    /** Link do dokumentu w wFirma (ten sam, co na karcie zamówienia). */
    public static function wfirmaUrl(string $remoteId): string
    {
        return 'https://wfirma.pl/invoices/view/' . rawurlencode($remoteId);
    }

    /**
     * Numer dokumentu (np. „PAR 168/2026”) z opisu zapisanego przy powiązaniu.
     * Tabela order_documents trzyma tylko ID z wFirma - numer jest w wiadomości
     * („Paragon PAR 168/2026 wystawiony poza CRM…” / „Dokument FV 12/2026 wystawiony.”).
     */
    public static function documentNumber(string $message): ?string
    {
        if (preg_match('/^(?:Faktura|Paragon|Dokument)\s+(\S.*?)\s+wystawion/u', trim($message), $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * @param list<array<string,mixed>> $documents wiersze order_documents
     * @return list<array<string,mixed>>
     */
    public static function documents(array $documents): array
    {
        $out = [];
        foreach ($documents as $d) {
            if (($d['status'] ?? '') !== 'issued') { continue; }
            $type = (string) ($d['document_type'] ?? '');
            $remoteId = trim((string) ($d['remote_id'] ?? ''));
            $isWfirma = ($d['provider'] ?? '') === 'wfirma' && $remoteId !== '';
            $number = self::documentNumber((string) ($d['message'] ?? ''));
            $missing = [];
            if ($number === null) {
                $missing[] = 'Numer dokumentu nie jest zapisany w CRM' . ($isWfirma ? ' — pokazuję ID z wFirma.' : '.');
            }
            if (!$isWfirma) {
                $missing[] = 'Brak linku — dokument nie pochodzi z wFirma.';
            }
            $out[] = [
                'kind' => $type === 'normal' ? 'invoice' : (str_starts_with($type, 'receipt') ? 'receipt' : 'invoice'),
                'label' => self::DOC_LABELS[$type] ?? ('Dokument: ' . $type),
                'number' => $number,
                'display' => $number ?? ($remoteId !== '' ? 'ID ' . $remoteId : '—'),
                'url' => $isWfirma ? self::wfirmaUrl($remoteId) : null,
                'created_at' => (string) ($d['created_at'] ?? ''),
                'missing' => $missing,
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $shipments wiersze shipments
     * @return list<array<string,mixed>>
     */
    public static function shipments(array $shipments): array
    {
        $out = [];
        foreach ($shipments as $sh) {
            $status = (string) ($sh['status'] ?? 'created');
            $cancelled = in_array($status, ['cancelled', 'canceled'], true);
            $waybill = trim((string) ($sh['waybill_no'] ?? ''));
            $courier = trim((string) preg_replace('/\s*\(ręcznie\)\s*$/u', '', (string) ($sh['courier_code'] ?? '')));
            $trk = (string) ($sh['tracking_status'] ?? '');
            $url = $waybill !== '' && !$cancelled ? ShipmentTracking::trackingUrl($sh) : null;

            if ($cancelled) {
                [$label, $class] = ['Anulowana', 'bad'];
            } elseif ($status === 'error') {
                [$label, $class] = ['Błąd nadania', 'bad'];
            } elseif ($status === 'pending') {
                [$label, $class] = ['Przewoźnik tworzy przesyłkę', 'muted'];
            } elseif ($trk !== '') {
                [$label, $class] = [ShipmentTracking::label($trk), ShipmentTracking::pillClass($trk)];
            } else {
                [$label, $class] = ['Nadana', 'ok'];
            }

            $events = json_decode((string) ($sh['tracking_events'] ?? ''), true);
            $last = is_array($events) && $events !== [] ? end($events) : null;
            $lastAt = is_array($last) ? (string) ($last['at'] ?? '') : '';
            if ($lastAt === '' && !empty($sh['tracking_at'])) {
                $lastAt = (string) $sh['tracking_at'];
            }

            $missing = [];
            if ($waybill === '') {
                $missing[] = 'Brak numeru listu przewozowego.';
            } elseif ($url === null && !$cancelled) {
                $missing[] = 'Nie rozpoznano przewoźnika — brak linku do śledzenia.';
            }
            $out[] = [
                'waybill' => $waybill !== '' ? $waybill : null,
                'courier' => $courier,
                'url' => $url,
                'status' => $label,
                'status_class' => $class,
                'progress' => $cancelled || $status === 'error' ? 0 : (self::PROGRESS[$trk] ?? ($status === 'pending' ? 5 : 25)),
                'last_event' => is_array($last) ? (string) (($last['desc'] ?? '') !== '' ? $last['desc'] : ($last['code'] ?? '')) : '',
                'last_at' => $lastAt !== '' ? self::localTime($lastAt) : '',
                'cancelled' => $cancelled,
                'missing' => $missing,
            ];
        }
        return $out;
    }

    /** Czas przewoźnika (UTC) po polsku: 28.09.2026 10:57. */
    private static function localTime(string $utc): string
    {
        try {
            return (new \DateTime($utc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i');
        } catch (\Exception) {
            return '';
        }
    }
}
