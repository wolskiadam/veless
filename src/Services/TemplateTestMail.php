<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Testowa wysyłka szablonu e-mail (Konfiguracja → Szablony e-mail) na wskazany adres:
 * z danymi wybranego zamówienia albo przykładowymi, z tego samego konta nadawcy co automatyzacja.
 * Temat dostaje „[TEST]”, wpis w dzienniku e-maili ma rodzaj „Test szablonu”.
 */
final class TemplateTestMail
{
    /** Przykładowe dane, gdy nie wskazano zamówienia (te same co w podglądzie edytora). */
    public const SAMPLE = [
        'customer_name' => 'Jan Kowalski', 'customer_email' => 'jan@example.com',
        'order_number' => '12345', 'pase_number' => '42', 'total' => '149,00', 'currency' => 'PLN', 'status' => 'processing',
        'waybill' => '620000111222333', 'tracking_number' => '620000111222333', 'carrier' => 'InPost',
        'tracking_link' => 'https://inpost.pl/sledzenie-przesylek?number=620000111222333', 'shipment_status' => 'Doręczona',
        'order_link' => 'https://sklep.pl/zamowienie.php?token=abc123',
        'product_list' => '2 × Świeca sojowa 180 ml<br>1 × Wosk zapachowy',
        'review_link' => 'https://g.page/r/przyklad/review',
    ];

    /** @var (callable(int):array{name:string,mime:string,content:string})|null */
    private $invoicePdf;

    /** @param (callable(int):array{name:string,mime:string,content:string})|null $invoicePdf do testów */
    public function __construct(private readonly PDO $pdo, ?callable $invoicePdf = null)
    {
        $this->invoicePdf = $invoicePdf;
    }

    /**
     * @param array<string,mixed> $tpl wiersz email_templates
     * @param string $orderRef numer CRM, numer zamówienia albo pusty (dane przykładowe)
     * @return array{ok:bool,message:string}
     */
    public function send(array $tpl, string $to, string $orderRef = ''): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Podaj poprawny adres e-mail do testu.'];
        }
        $order = null;
        $orderRef = ltrim(trim($orderRef), '#');
        if ($orderRef !== '') {
            $order = $this->findOrder($orderRef);
            if ($order === null) {
                return ['ok' => false, 'message' => "Nie znaleziono zamówienia {$orderRef}."];
            }
        }
        $orderId = $order !== null ? (int) $order['woo_order_id'] : null;
        $accounts = new MailAccounts($this->pdo);
        $cfg = $accounts->mailerConfig($orderId, (int) ($tpl['mail_account_id'] ?? 0));
        $mailer = new Mailer($cfg);
        if (!$mailer->isConfigured()) {
            return ['ok' => false, 'message' => 'Brak skonfigurowanego konta e-mail (Konfiguracja → E-mail).'];
        }

        if ($order !== null) {
            $payload = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
            $vars = (new \Pase\Automation\RuleEngine($this->pdo, new \Pase\Repository\AutomationRuleRepository($this->pdo)))
                ->templateVars($orderId, $payload);
        } else {
            $vars = self::SAMPLE + ['shop_name' => '', 'shop_logo' => ''];
        }
        if (($cfg['from_name'] ?? '') !== '') {
            $vars['shop_name'] = $cfg['from_name'];
        }
        if (($cfg['review_link'] ?? '') !== '') {
            $vars['review_link'] = $cfg['review_link'];
        }
        if (($cfg['logo_url'] ?? '') !== '') {
            $vars['shop_logo'] = MailAccounts::logoHtml($cfg);
        }

        $note = '';
        $attachments = [];
        if (!empty($tpl['attach_invoice'])) {
            if ($orderId === null) {
                $note = ' Bez faktury: do testu z fakturą wpisz numer zamówienia.';
            } else {
                try {
                    $attachments[] = $this->invoicePdf !== null ? ($this->invoicePdf)($orderId) : (new InvoicePdf($this->pdo))->forOrder($orderId);
                } catch (\Throwable $e) {
                    $note = ' Bez faktury: ' . $e->getMessage();
                }
            }
        }

        $subject = '[TEST] ' . Mailer::render((string) $tpl['subject'], $vars);
        $body = Mailer::renderHtml((string) $tpl['body'], $vars);
        [$ok, $msg] = $mailer->send($to, $subject, $body,
            ['type' => 'template_test', 'template' => (string) ($tpl['tpl_key'] ?? ''), 'order_id' => $orderId], $attachments);
        if (!$ok) {
            return ['ok' => false, 'message' => "Test nie został wysłany: {$msg}"];
        }
        $from = $order !== null ? 'z danymi zamówienia ' . $orderRef : 'z przykładowymi danymi';
        return ['ok' => true, 'message' => "Wysłano test na {$to} ({$from}, z konta {$cfg['from_email']})." . $note];
    }

    /** @return array<string,mixed>|null */
    private function findOrder(string $ref): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM woo_orders WHERE CAST(pase_number AS CHAR) = ? OR order_number = ? OR CAST(woo_order_id AS CHAR) = ?
            ORDER BY CASE WHEN CAST(pase_number AS CHAR) = ? THEN 0 ELSE 1 END LIMIT 1');
        $st->execute([$ref, $ref, $ref, $ref]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
