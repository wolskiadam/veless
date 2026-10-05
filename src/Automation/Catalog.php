<?php
declare(strict_types=1);

namespace Pase\Automation;

/**
 * Katalog zdarzeń i akcji automatyzacji (model BaseLinker: ZDARZENIE + warunki TO lista akcji).
 *
 * Jedno źródło prawdy dla panelu (automations.php) i silnika (RuleEngine):
 *  - events()      - zdarzenia wyzwalające regułę,
 *  - actions()     - typy akcji, które można dodać do listy,
 *  - conditionFields() - pola, po których budujemy warunki.
 *
 * Flaga 'ready' => akcja jest faktycznie wykonywana przez RuleEngine.
 * 'ready' => false => akcja widoczna w UI, ale na razie pomijana (placeholder).
 * 'params' => [] => akcja bez parametru (np. „Oznacz jako opłacone”).
 */
final class Catalog
{
    /** @return array<string,array{label:string,desc:string}> */
    public static function events(): array
    {
        return self::withExtensions('automation.events', self::coreEvents(), static fn(array $d): array => [
            'label' => (string) $d['label'], 'desc' => (string) ($d['desc'] ?? ''),
        ]);
    }

    /** @return array<string,array{label:string,desc:string}> */
    private static function coreEvents(): array
    {
        return [
            'order.imported'   => ['label' => 'Pobrano zamówienie',            'desc' => 'Po imporcie nowego zamówienia (z każdego źródła)'],
            'status.changed'   => ['label' => 'Ustawiono status',              'desc' => 'Gdy zmieni się status CRM zamówienia'],
            'order.paid'       => ['label' => 'Zamówienie zostało opłacone',    'desc' => 'Gdy zamówienie stanie się opłacone (w sklepie, wpłata w CRM, PayU) - raz na zamówienie'],
            'shipment.created' => ['label' => 'Utworzono przesyłkę',           'desc' => 'Po nadaniu przesyłki kurierskiej'],
            'shipment.status'  => ['label' => 'Zmieniono status przesyłki u kuriera', 'desc' => 'Aktualizacja statusu u kuriera'],
            'invoice.issued'   => ['label' => 'Wystawiono fakturę',            'desc' => 'Po wystawieniu faktury VAT w wFirma'],
            'receipt.issued'   => ['label' => 'Wystawiono paragon',            'desc' => 'Po wystawieniu paragonu w wFirma'],
        ];
    }

    /**
     * Akcje rdzenia + akcje rozszerzeń (filtr automation.actions). Akcja rozszerzenia:
     * ['label' => ..., 'params' => ['klucz' => 'Etykieta'] (najwyżej jeden), 'options' => [[wartość, etykieta], ...],
     *  'hint' => ..., 'run' => fn(int $orderId, array $params, array $payload, PDO $pdo): void].
     * @return array<string,array{label:string,ready:bool,params:array<string,string>}>
     */
    public static function actions(): array
    {
        return self::withExtensions('automation.actions', self::coreActions(), static function (array $d): ?array {
            if (!is_callable($d['run'] ?? null)) {
                return null;
            }
            return ['label' => (string) $d['label'], 'ready' => true, 'params' => array_slice((array) ($d['params'] ?? []), 0, 1, true),
                    'options' => (array) ($d['options'] ?? []), 'hint' => (string) ($d['hint'] ?? ''), 'run' => $d['run']];
        });
    }

    /** @return array<string,array{label:string,ready:bool,params:array<string,string>}> */
    private static function coreActions(): array
    {
        return [
            'set_status'    => ['label' => 'Ustaw status',        'ready' => true,  'params' => ['status' => 'Status docelowy']],
            'send_email'    => ['label' => 'Wyślij e-mail',       'ready' => true,  'params' => ['template' => 'Szablon e-mail']],
            'send_sms'      => ['label' => 'Wyślij SMS',          'ready' => true,  'params' => ['template' => 'Treść SMS']],
            'issue_sales_document' => ['label' => 'Wystaw fakturę lub paragon', 'ready' => true, 'params' => ['receipt_type' => 'Gdy bez faktury']],
            'issue_invoice' => ['label' => 'Wystaw fakturę',      'ready' => true,  'params' => ['doc_type' => 'Rodzaj dokumentu']],
            'create_receipt'=> ['label' => 'Stwórz paragon',      'ready' => true,  'params' => ['doc_type' => 'Rodzaj paragonu']],
            'forward_order' => ['label' => 'Przekaż zamówienie',  'ready' => true,  'params' => ['integration' => 'Sklep docelowy']],
            'mark_paid'     => ['label' => 'Oznacz jako opłacone', 'ready' => true, 'params' => []],
            'set_virtual'   => ['label' => 'Oznacz jako wirtualne (bez wysyłki)', 'ready' => true, 'params' => ['value' => 'Znacznik']],
        ];
    }

    /** @return array<string,string> klucz pola warunku => etykieta */
    public static function conditionFields(): array
    {
        return [
            'pase_status' => 'Status CRM',
            'status' => 'Status w kanale sprzedaży',
            'payment_status' => 'Płatność',
            'is_virtual' => 'Zamówienie wirtualne',
            'wants_invoice' => 'Klient chce fakturę',
            'document' => 'Dokument sprzedaży',
            'total'  => 'Suma zamówienia',
            'items_qty' => 'Liczba sztuk',
            'source' => 'Źródło zamówienia',
            'payment_method' => 'Metoda płatności',
            'shipping_method' => 'Metoda dostawy',
            'country' => 'Kraj dostawy',
            'customer_email' => 'E-mail klienta',
            'sku'    => 'SKU pozycji',
            'product_name' => 'Nazwa produktu',
            'has_shipment' => 'Przesyłka utworzona',
            'shipment_status' => 'Status przesyłki (u kuriera)',
        ];
    }

    /** @return array<string,string> operator => etykieta */
    public static function conditionOps(): array
    {
        return [
            'eq' => '= równe', 'ne' => '≠ różne',
            'gt' => '> większe', 'gte' => '≥', 'lt' => '< mniejsze', 'lte' => '≤',
            'contains' => 'zawiera', 'ncontains' => 'nie zawiera',
        ];
    }

    /**
     * Zdarzenia z parametrem wybieranym obok zdarzenia („Ustawiono status” -> NA JAKI status).
     * Parametr zapisuje się jako warunek pole = wartość i jest zawsze wymagany, także przy „dowolny warunek”.
     * @return array<string,string> zdarzenie => pole warunku
     */
    public static function eventParams(): array
    {
        return ['shipment.status' => 'shipment_status', 'status.changed' => 'pase_status'];
    }

    /**
     * Dokłada wpisy rozszerzeń: klucz [a-z0-9_.], wymagana etykieta, bez nadpisywania rdzenia.
     * @param array<string,array<string,mixed>> $core
     * @param callable(array<string,mixed>):?array $normalize
     */
    private static function withExtensions(string $hook, array $core, callable $normalize): array
    {
        $ext = \Pase\Plugin\Hooks::applyFilters($hook, []);
        foreach ($ext as $key => $def) {
            if (!is_string($key) || isset($core[$key]) || !preg_match('/^[a-z0-9_.]+$/', $key) || !is_array($def) || !isset($def['label'])) {
                continue;
            }
            $def = $normalize($def);
            if ($def !== null) {
                $core[$key] = $def;
            }
        }
        return $core;
    }

    public static function eventLabel(string $key): string
    {
        return self::events()[$key]['label'] ?? $key;
    }

    public static function actionLabel(string $key): string
    {
        return self::actions()[$key]['label'] ?? $key;
    }

    public static function isActionReady(string $key): bool
    {
        return self::actions()[$key]['ready'] ?? false;
    }
}
