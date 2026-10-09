<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Treść maili z automatyzacji w wątku zamówienia (panel i strona klienta).
 *
 * Od wersji z treścią w dzienniku e-maili (EmailLog.body) pokazujemy zapisany tekst. Starsze wpisy
 * mają sam temat - wtedy odtwarzamy treść z obecnego szablonu i danych zamówienia (tak jak wybór
 * szablonu w odpowiedzi do klienta). Taka treść jest oznaczona jako odtworzona: jeśli szablon albo
 * dane zamówienia zmieniły się od wysyłki, mogła być nieco inna.
 */
final class AutoMailText
{
    /** @var array<string,?array<string,string>> */
    private array $vars = [];
    /** @var array<string,?array<string,mixed>> */
    private array $templates = [];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string,mixed> $logRow wiersz email_log
     * @return array{text:string,reconstructed:bool} pusty tekst, gdy nie ma ani zapisu, ani szablonu
     */
    public function forEntry(array $logRow): array
    {
        $stored = trim((string) ($logRow['body'] ?? ''));
        if ($stored !== '') {
            return ['text' => $stored, 'reconstructed' => false];
        }
        try {
            $text = $this->reconstruct((int) ($logRow['woo_order_id'] ?? 0), (string) ($logRow['template_key'] ?? ''));
        } catch (\Throwable) {
            $text = '';   // brak tabel / danych - zostaje sam temat
        }
        return ['text' => $text, 'reconstructed' => $text !== ''];
    }

    private function reconstruct(int $wooOrderId, string $tplKey): string
    {
        if ($wooOrderId <= 0 || $tplKey === '') {
            return '';
        }
        $tpl = $this->templates[$tplKey] ??= (new \Pase\Repository\EmailTemplateRepository($this->pdo))->findByKey($tplKey);
        if ($tpl === null || trim((string) ($tpl['body'] ?? '')) === '') {
            return '';
        }
        $vars = $this->orderVars($wooOrderId);
        if ($vars === null) {
            return '';
        }
        // Konto nadawcy szablonu: nazwa sklepu, link do opinii i logo jak przy wysyłce (RuleEngine).
        try {
            $cfg = (new MailAccounts($this->pdo))->mailerConfig($wooOrderId, (int) ($tpl['mail_account_id'] ?? 0));
            if (($cfg['from_name'] ?? '') !== '') {
                $vars['shop_name'] = $cfg['from_name'];
            }
            if (($cfg['review_link'] ?? '') !== '') {
                $vars['review_link'] = $cfg['review_link'];
            }
        } catch (\Throwable) {
            // brak kont pocztowych - nazwa sklepu z ustawień
        }
        return EmailLog::bodyText(Mailer::renderHtml((string) $tpl['body'], $vars));
    }

    /** @return ?array<string,string> */
    private function orderVars(int $wooOrderId): ?array
    {
        $key = (string) $wooOrderId;
        if (!array_key_exists($key, $this->vars)) {
            $stmt = $this->pdo->prepare('SELECT payload FROM woo_orders WHERE woo_order_id = ?');
            $stmt->execute([$wooOrderId]);
            $payload = $stmt->fetchColumn();
            $this->vars[$key] = $payload === false ? null
                : (new \Pase\Automation\RuleEngine($this->pdo, new \Pase\Repository\AutomationRuleRepository($this->pdo)))
                    ->templateVars($wooOrderId, json_decode((string) $payload, true) ?: []);
        }
        return $this->vars[$key];
    }
}
