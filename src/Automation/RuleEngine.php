<?php
declare(strict_types=1);

namespace Pase\Automation;

use PDO;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\EmailTemplateRepository;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\Mailer;
use Pase\Support\Logger;

/**
 * Silnik automatyzacji (model BaseLinker: ZDARZENIE + warunki TO lista akcji).
 *
 * Dla danego ZDARZENIA (np. 'order.imported') przepuszcza zamówienie przez aktywne
 * reguły tego zdarzenia (wg priorytetu) i wykonuje po kolei listę akcji tych reguł,
 * których warunki są spełnione.
 *
 * Warunki - każdy: ['field' => ..., 'op' => ..., 'value' => ...]; łączone wg condition_match reguły:
 * 'all' = wszystkie (AND), 'any' = dowolny (OR). Parametr zdarzenia (Catalog::eventParams) wymagany zawsze.
 *   field: pase_status (status CRM) | status (kanału) | total | source | sku | shipment_status (etap u kuriera)
 *          | payment_status | is_virtual | wants_invoice | document | has_shipment (stan z bazy, OrderEvents)
 *          | items_qty | payment_method | shipping_method | country | customer_email | product_name
 *   op: eq|ne|gt|gte|lt|lte|contains|ncontains
 *
 * Akcje - lista [{type, params}]. Realnie wykonywane (Catalog::isActionReady):
 *   set_status -> zmienia status przez OrderStatusService (jak ręczna zmiana): zapis,
 *                 reguły 'Ustawiono status' i wysyłka statusu do Allegro/Woo.
 *
 * Zabezpieczenie przed pętlą (reguła A->B + reguła B->A itp.):
 *   - status taki sam jak obecny => nic nie robimy (nie ma zmiany, nie ma zdarzenia),
 *   - w jednym łańcuchu reguł dane zamówienie może wejść w dany status tylko raz,
 *   - łańcuch ma maksymalną głębokość MAX_DEPTH zagnieżdżonych zdarzeń.
 *   send_email / send_sms / issue_invoice / create_receipt / issue_sales_document (wFirma) / forward_order (do sklepu Woo)
 *   mark_paid / set_virtual. Automatyzacja nigdy nie wystawia drugiego dokumentu sprzedaży (faktura albo paragon).
 */
final class RuleEngine
{
    /** Maksymalna liczba zagnieżdżonych zdarzeń w jednym łańcuchu reguł. */
    private const MAX_DEPTH = 10;

    /** Bieżąca głębokość zagnieżdżenia dispatch() (wspólna dla wszystkich instancji). */
    private static int $depth = 0;

    /** @var array<string,true> "zamówienie:status" ustawione już w bieżącym łańcuchu */
    private static array $chainStatuses = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AutomationRuleRepository $rules,
        private readonly array $allegroConfig = [],
        /** Fabryka WfirmaDocumentService (testy podstawiają wtyczkę bez sieci); null = domyślna. */
        private readonly ?\Closure $documentService = null,
        /** Fabryka InvoicePdf (PDF faktury do e-maila; testy bez sieci); null = domyślna. */
        private readonly ?\Closure $invoicePdf = null
    ) {}

    /**
     * Uruchamia reguły danego ZDARZENIA dla jednego zamówienia.
     * @param string $event klucz zdarzenia (Catalog::events())
     * @param array  $payload zdekodowany payload zamówienia Woo
     * @return int liczba zastosowanych reguł
     */
    public function dispatch(string $event, int $wooOrderId, array $payload): int
    {
        // Reguły działają na WSZYSTKIE zamówienia, niezależnie od źródła - payload
        // z Allegro sprowadzamy do pól, które czytają warunki i szablony (format Woo).
        $payload = self::normalizePayload($payload);

        if (self::$depth >= self::MAX_DEPTH) {
            Logger::warn("Automatyzacja [{$event}]: przerwano łańcuch reguł dla zamówienia {$wooOrderId} - "
                . 'przekroczona głębokość ' . self::MAX_DEPTH . ' (możliwa pętla reguł).');
            return 0;
        }

        self::$depth++;
        try {
            // Rozszerzenia dostają każde zdarzenie zamówienia, także bez reguł (hak automation.event).
            \Pase\Plugin\Hooks::doAction('automation.event', $event, $wooOrderId, $payload);
            $applied = 0;
            foreach ($this->rules->activeForEvent($event) as $rule) {
                if (!$this->matches($rule, $event, $payload, $wooOrderId)) {
                    continue;
                }
                foreach ($rule['actions'] as $action) {
                    $this->executeAction($wooOrderId, $rule, $action, $payload);
                }
                $applied++;
                Logger::info("Automatyzacja [{$event}]: reguła #{$rule['id']} '{$rule['name']}' zastosowana do zamówienia {$wooOrderId}");
            }
            return $applied;
        } finally {
            self::$depth--;
            if (self::$depth === 0) {
                self::$chainStatuses = []; // koniec łańcucha - następne zdarzenie zaczyna od zera
            }
        }
    }

    /**
     * Zgodność wstecz: stare wywołanie traktujemy jako zdarzenie 'order.imported'.
     * @deprecated używaj dispatch()
     */
    public function runForOrder(int $wooOrderId, array $payload): int
    {
        return $this->dispatch('order.imported', $wooOrderId, $payload);
    }

    /**
     * Surowy checkout-form z Allegro -> pola w formacie Woo (status, total, created_via,
     * line_items[].sku, billing.*, number, currency). Payload Woo (albo już przełożony)
     * wraca bez zmian.
     */
    public static function normalizePayload(array $payload): array
    {
        $isAllegro = isset($payload['lineItems']) || isset($payload['buyer']) || isset($payload['checkoutForm']);
        if (!$isAllegro) {
            return $payload;
        }

        $buyer = $payload['buyer'] ?? [];
        $items = $payload['lineItems'] ?? [];

        $total = $payload['summary']['totalToPay']['amount'] ?? null;
        if ($total === null) {
            $total = 0.0;
            foreach ($items as $i) {
                $total += (float) ($i['price']['amount'] ?? 0) * (int) ($i['quantity'] ?? 1);
            }
        }

        return array_merge($payload, [
            'number'      => $payload['number'] ?? (string) ($payload['id'] ?? ''),
            // checkout-form ma też własne 'status' (READY_FOR_PROCESSING) - dla reguł liczy się status realizacji.
            'status'      => (string) ($payload['fulfillment']['status'] ?? $payload['status'] ?? ''),
            'total'       => $payload['total'] ?? (float) $total,
            'currency'    => $payload['currency'] ?? ($payload['summary']['totalToPay']['currency'] ?? 'PLN'),
            'created_via' => 'allegro',
            'line_items'  => $payload['line_items'] ?? array_map(
                static fn($i) => [
                    'sku'      => $i['sku'] ?? $i['offer']['external']['id'] ?? null,
                    'name'     => $i['offer']['name'] ?? '',
                    'quantity' => (int) ($i['quantity'] ?? 1),
                ],
                $items
            ),
            'billing'     => $payload['billing'] ?? [
                'first_name' => $buyer['firstName'] ?? '',
                'last_name'  => $buyer['lastName'] ?? '',
                'email'      => $buyer['email'] ?? '',
            ],
        ]);
    }

    /**
     * Czy reguła pasuje. „Wszystkie warunki” (AND, domyślnie) albo „dowolny warunek” (OR).
     * Parametr zdarzenia (np. „Ustawiono status: Do wysłania”) jest wymagany zawsze.
     * Brak warunków => pasuje zawsze.
     */
    private function matches(array $rule, string $event, array $payload, int $wooOrderId): bool
    {
        $any = ($rule['condition_match'] ?? 'all') === 'any';
        $paramField = Catalog::eventParams()[$event] ?? null;
        $rest = [];
        $paramDone = false;
        foreach ((array) ($rule['conditions'] ?? []) as $cond) {
            if (!$paramDone && $paramField !== null && ($cond['field'] ?? '') === $paramField && ($cond['op'] ?? '') === 'eq') {
                $paramDone = true;
                if (!$this->evalCondition($cond, $payload, $wooOrderId)) {
                    return false;
                }
                continue;
            }
            $rest[] = $cond;
        }
        if ($rest === []) {
            return true;
        }
        foreach ($rest as $cond) {
            $ok = $this->evalCondition($cond, $payload, $wooOrderId);
            if ($any && $ok) {
                return true;
            }
            if (!$any && !$ok) {
                return false;
            }
        }
        return !$any;
    }

    private function evalCondition(array $cond, array $payload, int $wooOrderId): bool
    {
        $field = $cond['field'] ?? '';
        $op    = $cond['op'] ?? 'eq';
        $value = $cond['value'] ?? '';

        // Pola „tak/nie” i zbiory (dokumenty) liczone ze stanu zamówienia w bazie.
        if (in_array($field, ['payment_status', 'is_virtual', 'wants_invoice', 'document', 'has_shipment'], true)) {
            $state = $this->orderState($field, $wooOrderId, $payload);
            $hit = is_array($state) ? in_array((string) $value, $state, true) : $state === (string) $value;
            return match ($op) {
                'eq'    => $hit,
                'ne'    => !$hit,
                default => false,
            };
        }

        $actual = match (true) {
            $field === 'pase_status'     => $this->currentPaseStatus($wooOrderId),
            // Przy zdarzeniu 'shipment.status' etap jest w payloadzie; przy innych zdarzeniach
            // bierzemy etap najnowszej nadanej przesyłki zamówienia.
            $field === 'shipment_status' => (string) ($payload['shipment_status'] ?? $this->latestShipmentStatus($wooOrderId)),
            // Źródło wybrane z listy kont (np. „integration:3" = konkretny sklep / konto Allegro).
            $field === 'source' && str_starts_with((string) $value, 'integration:')
                                         => 'integration:' . $this->orderIntegrationId($wooOrderId),
            $field === 'items_qty'       => array_sum(array_map(static fn($i) => max(0, (int) ($i['quantity'] ?? 1)),
                                                array_filter($this->orderItems($wooOrderId, $payload), 'is_array'))),
            $field === 'product_name'    => implode("\n", array_map(static fn($i) => (string) ($i['name'] ?? ''),
                                                array_filter($this->orderItems($wooOrderId, $payload), 'is_array'))),
            $field === 'sku'             => array_values(array_filter(array_map(static fn($i) => $i['sku'] ?? null,
                                                array_filter($this->orderItems($wooOrderId, $payload), 'is_array')))),
            $field === 'customer_email'  => $this->customerEmail($wooOrderId, $payload),
            default                      => $this->extractField($field, $payload),
        };

        if ($field === 'sku') {
            $skus = is_array($actual) ? $actual : [];
            return match ($op) {
                'contains', 'eq' => in_array((string)$value, $skus, true),
                'ne'             => !in_array((string)$value, $skus, true),
                default          => false,
            };
        }

        if ($field === 'total' || $field === 'items_qty') {
            $a = (float) $actual;
            $b = (float) $value;
            return match ($op) {
                'eq'  => $a == $b,
                'ne'  => $a != $b,
                'gt'  => $a >  $b,
                'gte' => $a >= $b,
                'lt'  => $a <  $b,
                'lte' => $a <= $b,
                default => false,
            };
        }

        $a = (string) $actual;
        $b = (string) $value;
        // Pola wpisywane ręcznie (kraj, e-mail, metoda) porównujemy bez wielkości liter.
        if (in_array($field, ['payment_method', 'shipping_method', 'country', 'customer_email', 'product_name'], true)) {
            $a = mb_strtolower(trim($a));
            $b = mb_strtolower(trim($b));
        }
        $has = $b !== '' && str_contains(mb_strtolower($a), mb_strtolower($b));
        return match ($op) {
            'eq'        => $a === $b,
            'ne'        => $a !== $b,
            'contains'  => $has,
            'ncontains' => $b !== '' && !$has,
            default     => false,
        };
    }

    /** Świeży wiersz zamówienia (poprzednia akcja mogła coś zmienić). */
    private function orderRow(int $wooOrderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Stan zamówienia dla warunków „tak/nie” i list wyboru:
     * payment_status -> klucz OrderEvents::PAYMENT_STATES, is_virtual / wants_invoice / has_shipment -> '1'|'0',
     * document -> lista: 'invoice', 'receipt', 'none' (brak faktury i paragonu).
     * @return string|list<string>
     */
    private function orderState(string $field, int $wooOrderId, array $payload): string|array
    {
        $row = $this->orderRow($wooOrderId);
        // Payload z bazy ma pola Woo dla każdego źródła; payload zdarzenia (np. etap przesyłki) go uzupełnia.
        $stored = json_decode((string) ($row['payload'] ?? ''), true);
        $data = is_array($stored) ? $payload + $stored : $payload;
        switch ($field) {
            case 'payment_status':
                return OrderEvents::paymentState($row, $data);
            case 'is_virtual':
                return OrderEvents::isVirtual($row, $data) ? '1' : '0';
            case 'wants_invoice':
                return OrderEvents::wantsInvoice($row, $data) ? '1' : '0';
            case 'has_shipment':
                try {
                    $st = $this->pdo->prepare("SELECT COUNT(*) FROM shipments WHERE woo_order_id = ? AND status = 'created'");
                    $st->execute([$wooOrderId]);
                    return (int) $st->fetchColumn() > 0 ? '1' : '0';
                } catch (\Throwable) {
                    return '0';
                }
            case 'document':
                $types = OrderEvents::documentTypes($this->pdo, $wooOrderId);
                $out = [];
                if (in_array('normal', $types, true)) {
                    $out[] = 'invoice';
                }
                if (array_intersect(['receipt', 'receipt_fiscal'], $types) !== []) {
                    $out[] = 'receipt';
                }
                return $out === [] ? ['none'] : $out;
        }
        return '';
    }

    /** Pozycje zamówienia: poprawione w CRM (local_items) albo ze sklepu. */
    private function orderItems(int $wooOrderId, array $payload): array
    {
        try {
            $st = $this->pdo->prepare('SELECT local_items FROM woo_orders WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $local = $st->fetchColumn();
            $items = $local ? json_decode((string) $local, true) : null;
        } catch (\Throwable) {
            $items = null;
        }
        return is_array($items) ? $items : (array) ($payload['line_items'] ?? []);
    }

    /** Aktualny status CRM z bazy (świeży - poprzednia reguła mogła go właśnie zmienić). */
    private function currentPaseStatus(int $wooOrderId): string
    {
        $stmt = $this->pdo->prepare('SELECT pase_status FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function orderIntegrationId(int $wooOrderId): string
    {
        $stmt = $this->pdo->prepare('SELECT integration_id FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** Link do strony zamówienia dla klienta (w domenie sklepu, jeśli ustawiono). */
    private function orderLink(int $wooOrderId): string
    {
        try {
            $st = $this->pdo->prepare('SELECT woo_order_id, integration_id, client_token FROM woo_orders WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return $row ? (string) ((new \Pase\Services\ClientLinks($this->pdo))->forOrder($row) ?? '') : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Najnowsza nadana przesyłka (także wpisana ręcznie) jako dane do szablonu: numer, przewoźnik, link śledzenia.
     * Numer z bieżącego zdarzenia (shipment.created) ma pierwszeństwo.
     * @return array{waybill:string,carrier:string,link:string}
     */
    private function shipmentVars(int $wooOrderId, array $payload): array
    {
        $sh = [];
        try {
            $st = $this->pdo->prepare("SELECT * FROM shipments WHERE woo_order_id = ? AND status = 'created' ORDER BY id DESC LIMIT 1");
            $st->execute([$wooOrderId]);
            $sh = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // brak tabeli przesyłek
        }
        $event = trim((string) ($payload['shipment_waybill'] ?? ''));
        if ($event !== '' && $event !== (string) ($sh['waybill_no'] ?? '')) {
            $sh = ['waybill_no' => $event, 'courier_code' => (string) ($payload['shipment_carrier'] ?? '')];
        }
        return [
            'waybill' => (string) ($sh['waybill_no'] ?? ''),
            'carrier' => (string) ($sh['courier_code'] ?? ''),
            'link'    => $sh !== [] ? (string) (\Pase\Services\ShipmentTracking::trackingUrl($sh) ?? '') : '',
        ];
    }

    /** Etap śledzenia najnowszej nadanej przesyłki zamówienia ('' gdy brak). */
    private function latestShipmentStatus(int $wooOrderId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT tracking_status FROM shipments WHERE woo_order_id = ? AND status = 'created'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$wooOrderId]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return ''; // starsza baza bez kolumn śledzenia
        }
    }

    /** @return mixed string|float|array (dla sku) */
    private function extractField(string $field, array $payload): mixed
    {
        return match ($field) {
            'status' => $payload['status'] ?? '',
            'total'  => $payload['total'] ?? 0,
            'source' => $payload['created_via'] ?? '',
            // Klucz metody (bacs, cod, payu...) i jej nazwa - „zawiera pobranie” działa dla Woo i Allegro.
            'payment_method' => trim(($payload['payment_method'] ?? '') . ' ' . ($payload['payment_method_title'] ?? '')
                . ' ' . ($payload['payment']['type'] ?? '')),
            'shipping_method' => trim(implode(' ', array_map(
                static fn($l) => (string) ($l['method_title'] ?? '') . ' ' . (string) ($l['method_id'] ?? ''),
                (array) ($payload['shipping_lines'] ?? [])
            )) . ' ' . ($payload['delivery']['method']['name'] ?? '')),
            'country' => (string) ($payload['shipping']['country'] ?? '') !== '' ? (string) $payload['shipping']['country']
                : (string) ($payload['delivery']['address']['countryCode'] ?? $payload['billing']['country'] ?? ''),
            'sku'    => array_values(array_filter(array_map(
                static fn($i) => $i['sku'] ?? null,
                $payload['line_items'] ?? []
            ))),
            default  => '',
        };
    }

    /**
     * Wykonuje pojedynczą akcję reguły. $action = ['type' => ..., 'params' => [...]].
     */
    private function executeAction(int $wooOrderId, array $rule, array $action, array $payload): void
    {
        $type   = $action['type'] ?? '';
        $params = $action['params'] ?? [];

        // Akcje niezaimplementowane: logujemy i pomijamy (placeholder w UI).
        if (!Catalog::isActionReady($type)) {
            Logger::info("Automatyzacja: akcja '{$type}' (reguła #{$rule['id']}) jeszcze nieobsługiwana - pomijam.");
            return;
        }

        switch ($type) {
            case 'set_status':
                $this->setStatus($wooOrderId, $rule, (string) ($params['status'] ?? ''));
                break;

            case 'send_email':
                $this->sendEmail($wooOrderId, $rule, (string) ($params['template'] ?? ''), $payload);
                break;

            case 'send_sms':
                $this->sendSms($wooOrderId, $rule, (string) ($params['template'] ?? ''), $payload);
                break;

            case 'issue_invoice':
            case 'create_receipt':
                $this->issueDocument($wooOrderId, $rule, $type, (string) ($params['doc_type'] ?? $params['integration'] ?? ''));
                break;

            case 'issue_sales_document':
                $this->issueSalesDocument($wooOrderId, $rule, (string) ($params['receipt_type'] ?? ''), $payload);
                break;

            case 'mark_paid':
                $this->markPaid($wooOrderId, $rule);
                break;

            case 'set_virtual':
                $this->setVirtual($wooOrderId, $rule, ($params['value'] ?? '1') !== '0');
                break;

            case 'forward_order':
                $r = (new \Pase\Services\OrderForwarder($this->pdo))->toWoo($wooOrderId, (int) ($params['integration'] ?? 0));
                $r['ok'] ? Logger::info("Automatyzacja (reguła #{$rule['id']}): {$r['message']}", ['order' => $wooOrderId])
                         : Logger::warn("Automatyzacja (reguła #{$rule['id']}): przekazanie zamówienia {$wooOrderId} - {$r['message']}");
                break;

            default:
                // Akcja rozszerzenia (Catalog::actions() z filtra automation.actions).
                $run = Catalog::actions()[$type]['run'] ?? null;
                if (is_callable($run)) {
                    try {
                        $run($wooOrderId, $params, $payload, $this->pdo);
                    } catch (\Throwable $e) {
                        Logger::warn("Automatyzacja (reguła #{$rule['id']}): akcja rozszerzenia '{$type}' - " . $e->getMessage(), ['order' => $wooOrderId]);
                    }
                    break;
                }
                Logger::warn("Automatyzacja: nieznany typ akcji '{$type}' w regule #{$rule['id']}");
        }
    }

    /**
     * Zmiana statusu z reguły = pełna zmiana statusu (jak ręczna): zapis, zdarzenie
     * 'Ustawiono status' i synchronizacja do Allegro/Woo. Z zabezpieczeniem przed pętlą.
     */
    private function setStatus(int $wooOrderId, array $rule, string $newStatus): void
    {
        if ($newStatus === '') {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT pase_status FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        if ((string) $stmt->fetchColumn() === $newStatus) {
            return; // już ma ten status - brak zmiany, brak zdarzenia
        }

        $key = $wooOrderId . ':' . $newStatus;
        if (isset(self::$chainStatuses[$key])) {
            Logger::warn("Automatyzacja: reguła #{$rule['id']} chciała znów ustawić '{$newStatus}' dla zamówienia "
                . "{$wooOrderId} w tym samym łańcuchu - pomijam (pętla reguł).");
            return;
        }
        self::$chainStatuses[$key] = true;

        $msg = (new \Pase\Services\OrderStatusService(
            $this->pdo,
            new IntegrationAccountRepository($this->pdo),
            new OrderStatusRepository($this->pdo),
            $this->allegroConfig
        ))->change($wooOrderId, $newStatus);

        Logger::info("Automatyzacja: reguła #{$rule['id']} ustawiła status '{$newStatus}' dla zamówienia {$wooOrderId}"
            . ($msg !== '' ? " - {$msg}" : ''));
    }

    /** Wysyła e-mail wg szablonu do klienta zamówienia (adres z payloadu / woo_orders). */
    private function sendEmail(int $wooOrderId, array $rule, string $tplKey, array $payload): void
    {
        if ($tplKey === '') {
            Logger::warn("Automatyzacja: akcja send_email bez szablonu (reguła #{$rule['id']})");
            return;
        }
        $tpl = (new EmailTemplateRepository($this->pdo))->findByKey($tplKey);
        if ($tpl === null || (int) $tpl['is_active'] !== 1) {
            Logger::warn("Automatyzacja: szablon '{$tplKey}' nie istnieje lub nieaktywny (reguła #{$rule['id']})");
            return;
        }

        $to = $this->customerEmail($wooOrderId, $payload);
        if ($to === '') {
            Logger::warn("Automatyzacja: brak adresu klienta dla zamówienia #{$wooOrderId} - pomijam e-mail");
            return;
        }

        // Konto nadawcy: wybrane w szablonie albo przypisane do sklepu, z którego jest zamówienie.
        $mailCfg = (new \Pase\Services\MailAccounts($this->pdo))->mailerConfig($wooOrderId, (int) ($tpl['mail_account_id'] ?? 0));
        $vars    = $this->emailVars($wooOrderId, $payload);
        if ($mailCfg['from_name'] !== '') {
            $vars['shop_name'] = $mailCfg['from_name'];
        }
        if (($mailCfg['review_link'] ?? '') !== '') {
            $vars['review_link'] = $mailCfg['review_link'];
        }
        if (($mailCfg['logo_url'] ?? '') !== '') {
            $vars['shop_logo'] = \Pase\Services\MailAccounts::logoHtml($mailCfg);
        }
        $subject = Mailer::render($tpl['subject'], $vars);
        $body    = Mailer::renderHtml($tpl['body'], $vars);

        // Szablon z fakturą: bez PDF z wFirma nie wysyłamy („faktura wystawiona” bez faktury tylko myli klienta).
        // Wpis w dzienniku e-maili pokazuje powód, czemu klient go nie dostał.
        $meta = ['type' => 'automation', 'template' => $tplKey, 'order_id' => $wooOrderId];
        $attachments = [];
        if (!empty($tpl['attach_invoice'])) {
            try {
                $pdf = $this->invoicePdf !== null ? ($this->invoicePdf)() : new \Pase\Services\InvoicePdf($this->pdo);
                $attachments[] = $pdf->forOrder($wooOrderId);
            } catch (\Throwable $e) {
                Logger::warn("Automatyzacja: e-mail '{$tplKey}' (reguła #{$rule['id']}) dla zamówienia {$wooOrderId} nie wysłany - " . $e->getMessage());
                \Pase\Services\EmailLog::recordAttempt($meta, $to, $subject, (string) ($mailCfg['from_email'] ?? ''), false, $e->getMessage());
                return;
            }
        }

        $mailer = new Mailer($mailCfg);
        [$ok, $msg] = $mailer->send($to, $subject, $body, $meta, $attachments);
        if (!$ok) {
            Logger::error("Automatyzacja: e-mail (reguła #{$rule['id']}) do {$to} nie wysłany: {$msg}");
        }
    }

    /**
     * Faktura / paragon w wFirma (WfirmaDocumentService - to samo co przycisk na stronie zamówienia).
     * Drugi dokument tego samego typu dla zamówienia nie powstaje (serwis to blokuje).
     */
    private function issueDocument(int $wooOrderId, array $rule, string $action, string $docType): void
    {
        $allowed = $action === 'issue_invoice' ? ['normal', 'proforma'] : ['receipt', 'receipt_fiscal'];
        if (!in_array($docType, $allowed, true)) {
            $docType = $allowed[0];
        }
        // Faktura VAT i paragon wykluczają się: automatyzacja nie wystawi drugiego dokumentu sprzedaży
        // (ani tego samego typu, ani faktury do zamówienia z paragonem - to zostaje decyzją człowieka).
        if (in_array($docType, OrderEvents::SALES_DOCUMENTS, true)) {
            $existing = array_values(array_intersect(OrderEvents::documentTypes($this->pdo, $wooOrderId), OrderEvents::SALES_DOCUMENTS));
            if ($existing !== []) {
                Logger::info("Automatyzacja (reguła #{$rule['id']}): zamówienie {$wooOrderId} ma już dokument sprzedaży ("
                    . implode(', ', $existing) . ") - {$docType} nie wystawiony.");
                return;
            }
        }
        try {
            $service = $this->documentService !== null ? ($this->documentService)() : new \Pase\Services\WfirmaDocumentService($this->pdo);
            $r = $service->issue($wooOrderId, $docType);
            Logger::info("Automatyzacja (reguła #{$rule['id']}): wystawiono dokument {$docType} dla zamówienia {$wooOrderId} (wFirma #{$r['document_id']})");
        } catch (\Throwable $e) {
            Logger::warn("Automatyzacja (reguła #{$rule['id']}): dokument {$docType} dla zamówienia {$wooOrderId} nie wystawiony - " . $e->getMessage());
        }
    }

    /**
     * „Wystaw fakturę lub paragon”: faktura VAT, gdy klient o nią prosi (albo podał NIP), inaczej paragon
     * wybranego rodzaju. Nic nie robi, gdy zamówienie ma już fakturę albo paragon (także w trakcie wystawiania).
     */
    private function issueSalesDocument(int $wooOrderId, array $rule, string $receiptType, array $payload): void
    {
        $row = $this->orderRow($wooOrderId);
        if ($row === []) {
            return;
        }
        $stored = json_decode((string) ($row['payload'] ?? ''), true);
        $wants = OrderEvents::wantsInvoice($row, is_array($stored) ? $stored : $payload);
        $this->issueDocument($wooOrderId, $rule, $wants ? 'issue_invoice' : 'create_receipt',
            $wants ? 'normal' : ($receiptType === 'receipt_fiscal' ? 'receipt_fiscal' : 'receipt'));
    }

    /** Wpłata równa kwocie zamówienia (jak „Zapłacono” na karcie zamówienia). Opłaconych nie rusza. */
    private function markPaid(int $wooOrderId, array $rule): void
    {
        $row = $this->orderRow($wooOrderId);
        if ($row === [] || OrderEvents::paymentState($row, json_decode((string) ($row['payload'] ?? ''), true) ?: []) === 'paid') {
            return;
        }
        try {
            $payment = new \Pase\Services\OrderPayment($this->pdo);
            $payment->set($wooOrderId, $payment->dueTotal($row), 'Automatyzacja (reguła #' . $rule['id'] . ')');
            Logger::info("Automatyzacja (reguła #{$rule['id']}): zamówienie {$wooOrderId} oznaczone jako opłacone");
        } catch (\Throwable $e) {
            Logger::warn("Automatyzacja (reguła #{$rule['id']}): nie oznaczono zamówienia {$wooOrderId} jako opłacone - " . $e->getMessage());
        }
    }

    /** Znacznik „Zamówienie wirtualne — bez wysyłki” (jak przełącznik na karcie zamówienia). */
    private function setVirtual(int $wooOrderId, array $rule, bool $virtual): void
    {
        try {
            $st = $this->pdo->prepare('UPDATE woo_orders SET no_shipping = ? WHERE woo_order_id = ? AND (no_shipping IS NULL OR no_shipping <> ?)');
            $st->execute([$virtual ? 1 : 0, $wooOrderId, $virtual ? 1 : 0]);
            if ($st->rowCount() === 1) {
                (new \Pase\Services\AuditTrail($this->pdo))->record($wooOrderId, 'order.no_shipping_changed', [], ['no_shipping' => $virtual ? 1 : 0]);
                Logger::info("Automatyzacja (reguła #{$rule['id']}): zamówienie {$wooOrderId} " . ($virtual ? 'oznaczone' : 'odznaczone') . ' jako wirtualne');
            }
        } catch (\Throwable $e) {
            Logger::warn("Automatyzacja (reguła #{$rule['id']}): znacznik wirtualne dla {$wooOrderId} nie zmieniony - " . $e->getMessage());
        }
    }

    /** SMS do klienta przez aktywną integrację SMS (np. SMSAPI). Treść z tagami {{...}} jak w e-mailach. */
    private function sendSms(int $wooOrderId, array $rule, string $text, array $payload): void
    {
        if (trim($text) === '') {
            Logger::warn("Automatyzacja: akcja send_sms bez treści (reguła #{$rule['id']})");
            return;
        }
        $plugin = null;
        foreach ((new IntegrationAccountRepository($this->pdo))->all() as $acc) {
            if (empty($acc['is_active'])) {
                continue;
            }
            $p = \Pase\Plugin\PluginRegistry::forAccount((string) $acc['type'], $acc['config'] ?? []);
            if ($p instanceof \Pase\Plugin\Contract\Sms) {
                $plugin = $p;
                break;
            }
        }
        if ($plugin === null) {
            Logger::warn("Automatyzacja: brak aktywnej integracji SMS (np. SMSAPI) - SMS dla zamówienia {$wooOrderId} nie wysłany");
            return;
        }
        $phone = self::normalizePhone($this->customerPhone($wooOrderId, $payload));
        if ($phone === '') {
            Logger::warn("Automatyzacja: brak numeru telefonu klienta w zamówieniu {$wooOrderId} - pomijam SMS");
            return;
        }
        $body = trim(Mailer::render($text, $this->emailVars($wooOrderId, $payload)));
        $r = $plugin->sendSms($phone, $body);
        $r['ok'] ? Logger::info("Automatyzacja (reguła #{$rule['id']}): SMS do zamówienia {$wooOrderId} - {$r['message']}")
                 : Logger::error("Automatyzacja (reguła #{$rule['id']}): SMS do zamówienia {$wooOrderId} nie wysłany - {$r['message']}");
    }

    /** Telefon klienta: edytowany w CRM, z billing/shipping, a dla Allegro z buyer/delivery. */
    private function customerPhone(int $wooOrderId, array $payload): string
    {
        $stmt = $this->pdo->prepare('SELECT local_billing_phone FROM woo_orders WHERE woo_order_id = ?');
        try {
            $stmt->execute([$wooOrderId]);
            $local = (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            $local = '';
        }
        foreach ([$local, $payload['billing']['phone'] ?? '', $payload['shipping']['phone'] ?? '',
                  $payload['delivery']['address']['phoneNumber'] ?? '', $payload['buyer']['phoneNumber'] ?? ''] as $p) {
            if (trim((string) $p) !== '') {
                return (string) $p;
            }
        }
        return '';
    }

    /** „+48 600-100-200" / „600100200" -> „48600100200". Pusty, gdy to nie wygląda na numer. */
    public static function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 9) {
            $d = '48' . $d;
        }
        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
    }

    /** Adres klienta: z payloadu billing.email, w razie braku z woo_orders.customer_email. */
    private function customerEmail(int $wooOrderId, array $payload): string
    {
        $email = (string) ($payload['billing']['email'] ?? '');
        if ($email === '') {
            $stmt = $this->pdo->prepare('SELECT customer_email FROM woo_orders WHERE woo_order_id = ?');
            $stmt->execute([$wooOrderId]);
            $email = (string) ($stmt->fetchColumn() ?: '');
        }
        return $email;
    }

    /**
     * Zmienne {{...}} szablonów e-mail dla zamówienia - także dla wyboru szablonu
     * odpowiedzi na stronie zamówienia (Wiadomości z klientem).
     * @return array<string,string>
     */
    public function templateVars(int $wooOrderId, array $payload): array
    {
        return $this->emailVars($wooOrderId, $payload);
    }

    /** @return array<string,string> zmienne {{...}} dostępne w szablonie */
    private function emailVars(int $wooOrderId, array $payload): array
    {
        $billing = $payload['billing'] ?? [];
        $name = trim((string) (($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')));

        // Numer PASE i nazwa sklepu z bazy (gdy dostępne).
        $stmt = $this->pdo->prepare('SELECT pase_number FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        $paseNo = (string) ($stmt->fetchColumn() ?: '');

        $shopName = '';
        $reviewLink = '';
        $shopLogo = '';
        try {
            $accounts = new \Pase\Services\MailAccounts($this->pdo);
            $shopName = $accounts->shopName($wooOrderId, '');
            $cfg = $accounts->mailerConfig($wooOrderId);
            $reviewLink = (string) ($cfg['review_link'] ?? '');
            $shopLogo = \Pase\Services\MailAccounts::logoHtml($cfg);
        } catch (\Throwable $e) {
            // brak ustawień - zostaw puste
        }

        $ship = $this->shipmentVars($wooOrderId, $payload);

        return [
            'customer_name'  => $name !== '' ? $name : 'Kliencie',
            'customer_email' => (string) ($billing['email'] ?? ''),
            'order_number'   => (string) ($payload['number'] ?? $wooOrderId),
            'pase_number'    => $paseNo,
            'total'          => (string) ($payload['total'] ?? ''),
            'currency'       => (string) ($payload['currency'] ?? ''),
            'status'         => (string) ($payload['status'] ?? ''),
            'shop_name'      => $shopName,
            'waybill'        => $ship['waybill'],
            'tracking_number'=> $ship['waybill'],
            'carrier'        => $ship['carrier'],
            'tracking_link'  => $ship['link'],
            'order_link'     => $this->orderLink($wooOrderId),
            'shipment_status'=> \Pase\Services\ShipmentTracking::label((string) ($payload['shipment_status'] ?? $this->latestShipmentStatus($wooOrderId))),
            'product_list'   => $this->productList($wooOrderId, $payload),
            'review_link'    => $reviewLink,
            'shop_logo'      => $shopLogo,
        ];
    }

    /**
     * Lista produktów zamówienia („2 × Nazwa", linia pod linią, HTML): pozycje poprawione w CRM,
     * a bez nich z Woo (line_items) albo Allegro (lineItems).
     */
    private function productList(int $wooOrderId, array $payload): string
    {
        $items = null;
        try {
            $st = $this->pdo->prepare('SELECT local_items FROM woo_orders WHERE woo_order_id = ?');
            $st->execute([$wooOrderId]);
            $local = $st->fetchColumn();
            $items = $local ? json_decode((string) $local, true) : null;
        } catch (\Throwable $e) {
            // starsza baza bez kolumny local_items
        }
        $items = is_array($items) ? $items : ($payload['line_items'] ?? ($payload['lineItems'] ?? []));
        $lines = [];
        foreach ((array) $items as $it) {
            $name = trim((string) ($it['name'] ?? ($it['offer']['name'] ?? '')));
            if ($name !== '') {
                $lines[] = max(1, (int) ($it['quantity'] ?? 1)) . ' × ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            }
        }
        return implode("<br>\n", $lines);
    }

}
