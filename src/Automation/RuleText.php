<?php
declare(strict_types=1);

namespace Pase\Automation;

/**
 * Reguła automatyzacji po ludzku, do listy w panelu:
 * „Gdy pobrano zamówienie i spełnione są wszystkie warunki: zamówienie jest opłacone, zamówienie jest wirtualne
 *  → 1. Wystaw fakturę VAT albo paragon, 2. Zmień status na „Zrealizowane”".
 *
 * Nazwy statusów, szablonów, sklepów itp. podaje wołający ($labels), bo pochodzą z bazy:
 *   status => [klucz => nazwa] (statusy CRM), shipment => [klucz => nazwa] (etapy przesyłki),
 *   email => [tpl_key => nazwa], source => ['integration:3' => nazwa], shop => [id => nazwa].
 */
final class RuleText
{
    /** @param array<string,array<string,string>> $labels */
    public function __construct(private readonly array $labels = []) {}

    /** Początek zdania: „Gdy pobrano zamówienie” / „Gdy zamówienie dostanie status „Do wysłania””. */
    public function when(array $rule): string
    {
        $event = (string) ($rule['event'] ?? '');
        $param = $this->eventParam($rule);
        $value = $param !== null ? $this->valueLabel($param['field'], (string) $param['value']) : null;
        return 'Gdy ' . match ($event) {
            'order.imported'   => 'pobrano zamówienie',
            'status.changed'   => $value !== null ? 'zamówienie dostanie status ' . self::q($value) : 'zmieni się status zamówienia',
            'order.paid'       => 'zamówienie zostanie opłacone',
            'shipment.created' => 'utworzono przesyłkę',
            'shipment.status'  => $value !== null ? 'przesyłka dostanie u kuriera status ' . self::q($value) : 'zmieni się status przesyłki u kuriera',
            'invoice.issued'   => 'wystawiono fakturę',
            'receipt.issued'   => 'wystawiono paragon',
            default            => mb_strtolower(Catalog::eventLabel($event)),
        };
    }

    /** Łącznik warunków: '' (brak / jeden warunek), „wszystkie” albo „dowolny”. */
    public function joinLabel(array $rule): string
    {
        $n = count($this->conditions($rule));
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return 'jeśli';
        }
        return ($rule['condition_match'] ?? 'all') === 'any'
            ? 'i spełniony jest co najmniej jeden warunek'
            : 'i spełnione są wszystkie warunki';
    }

    /** @return list<string> warunki reguły (bez parametru zdarzenia, który jest w when()) */
    public function conditions(array $rule): array
    {
        $param = $this->eventParam($rule);
        $out = [];
        foreach ((array) ($rule['conditions'] ?? []) as $c) {
            if ($param !== null && $c === $param['cond']) {
                continue;
            }
            $out[] = $this->condition($c);
        }
        return $out;
    }

    /** @return list<string> akcje w kolejności wykonania */
    public function actions(array $rule): array
    {
        return array_map(fn(array $a): string => $this->action($a), (array) ($rule['actions'] ?? []));
    }

    public function condition(array $c): string
    {
        $field = (string) ($c['field'] ?? '');
        $op    = (string) ($c['op'] ?? 'eq');
        $value = (string) ($c['value'] ?? '');
        $neg   = $op === 'ne';

        $yesNo = [
            'is_virtual'    => ['zamówienie jest wirtualne (bez wysyłki)', 'zamówienie wymaga wysyłki'],
            'wants_invoice' => ['klient chce fakturę', 'klient nie chce faktury'],
            'has_shipment'  => ['przesyłka jest już utworzona', 'nie ma jeszcze przesyłki'],
        ];
        if (isset($yesNo[$field])) {
            return $yesNo[$field][($value === '1') === !$neg ? 0 : 1];
        }

        if ($field === 'payment_status') {
            $pos = [
                'paid' => 'zamówienie jest opłacone', 'partial' => 'zamówienie jest opłacone częściowo',
                'unpaid' => 'zamówienie jest nieopłacone', 'cod' => 'zamówienie jest płatne za pobraniem',
                'failed' => 'płatność się nie udała', 'refunded' => 'płatność zwrócono', 'unknown' => 'brak danych o płatności',
            ];
            return $neg ? 'płatność inna niż ' . self::q(mb_strtolower(OrderEvents::PAYMENT_STATES[$value] ?? $value))
                        : ($pos[$value] ?? 'płatność: ' . $value);
        }

        if ($field === 'document') {
            $map = [
                'invoice' => ['wystawiono już fakturę VAT', 'nie wystawiono faktury VAT'],
                'receipt' => ['wystawiono już paragon', 'nie wystawiono paragonu'],
                'none'    => ['nie ma jeszcze faktury ani paragonu', 'jest już faktura lub paragon'],
            ];
            return $map[$value][$neg ? 1 : 0] ?? 'dokument sprzedaży: ' . $value;
        }

        if ($field === 'sku') {
            return ($neg ? 'zamówienie nie zawiera' : 'zamówienie zawiera') . ' SKU ' . self::q($value);
        }

        $subject = match ($field) {
            'pase_status'     => 'status CRM',
            'status'          => 'status w kanale sprzedaży',
            'shipment_status' => 'status przesyłki u kuriera',
            'source'          => 'źródło zamówienia',
            default           => mb_strtolower(Catalog::conditionFields()[$field] ?? $field),
        };
        $shown = $this->valueLabel($field, $value);
        $isNumber = in_array($field, ['total', 'items_qty'], true);
        $verb = match ($op) {
            'eq'        => $isNumber ? 'równa się' : 'to',
            'ne'        => $isNumber ? 'jest różna od' : 'inny niż',
            'gt'        => 'większa niż',
            'gte'       => 'co najmniej',
            'lt'        => 'mniejsza niż',
            'lte'       => 'najwyżej',
            'contains'  => 'zawiera',
            'ncontains' => 'nie zawiera',
            default     => $op,
        };
        return $subject . ' ' . $verb . ' ' . ($isNumber ? $shown : self::q($shown));
    }

    public function action(array $a): string
    {
        $type  = (string) ($a['type'] ?? '');
        $param = $a['params'] ?? [];
        $val   = is_array($param) && $param !== [] ? (string) reset($param) : '';
        $missing = static fn(string $what): string => ' (nie wybrano ' . $what . ')';

        return match ($type) {
            'set_status'    => 'Zmień status na ' . ($val !== '' ? self::q($this->labels['status'][$val] ?? $val) : '…' . $missing('statusu')),
            'send_email'    => 'Wyślij klientowi e-mail ' . ($val !== '' ? self::q($this->labels['email'][$val] ?? $val) : $missing('szablonu')),
            'send_sms'      => 'Wyślij klientowi SMS ' . ($val !== '' ? self::q(mb_strimwidth($val, 0, 60, '…')) : $missing('treści')),
            'issue_sales_document' => 'Wystaw fakturę VAT, jeśli klient jej chce, a jeśli nie – '
                                   . ($val === 'receipt_fiscal' ? 'paragon fiskalny' : 'paragon niefiskalny'),
            'issue_invoice' => $val === 'proforma' ? 'Wystaw fakturę proforma' : 'Wystaw fakturę VAT',
            'create_receipt'=> $val === 'receipt_fiscal' ? 'Wystaw paragon fiskalny' : 'Wystaw paragon niefiskalny',
            'forward_order' => 'Przekaż zamówienie do sklepu ' . ($val !== '' ? self::q($this->labels['shop'][$val] ?? '#' . $val) : $missing('sklepu')),
            'mark_paid'     => 'Oznacz zamówienie jako opłacone',
            'set_virtual'   => $val === '0' ? 'Oznacz, że zamówienie wymaga wysyłki' : 'Oznacz zamówienie jako wirtualne (bez wysyłki)',
            default         => Catalog::actionLabel($type) . ($val !== '' ? ': ' . $val : ''),
        };
    }

    /** Czytelna wartość warunku (nazwa statusu, sklepu, etapu przesyłki…). */
    public function valueLabel(string $field, string $value): string
    {
        $key = match ($field) {
            'pase_status' => 'status',
            'shipment_status' => 'shipment',
            'source' => 'source',
            default => null,
        };
        return $key !== null ? (string) ($this->labels[$key][$value] ?? $value) : $value;
    }

    /** Parametr zdarzenia (np. „Ustawiono status: X”) - pierwszy warunek pole = wartość dla tego zdarzenia. */
    private function eventParam(array $rule): ?array
    {
        $field = Catalog::eventParams()[(string) ($rule['event'] ?? '')] ?? null;
        if ($field === null) {
            return null;
        }
        foreach ((array) ($rule['conditions'] ?? []) as $c) {
            if (($c['field'] ?? '') === $field && ($c['op'] ?? '') === 'eq') {
                return ['field' => $field, 'value' => (string) ($c['value'] ?? ''), 'cond' => $c];
            }
        }
        return null;
    }

    private static function q(string $s): string
    {
        return '„' . $s . '”';
    }
}
