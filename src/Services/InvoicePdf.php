<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\IntegrationAccountRepository;
use PDO;

/**
 * PDF faktury VAT zamówienia, pobierany z wFirma w chwili wysyłki e-maila (szablon z „Dołącz fakturę”).
 * Zamówienie scalone nie ma własnej faktury - bierzemy fakturę zamówienia głównego (obejmuje całą paczkę).
 */
final class InvoicePdf
{
    /**
     * @param \Closure|null $download fn(array $integrationConfig, string $remoteId): string - zawartość PDF
     *        albo RuntimeException (testy podstawiają bez sieci); null = WfirmaClient::downloadPdf
     */
    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $download = null) {}

    /**
     * @return array{name:string,mime:string,content:string}
     * @throws \RuntimeException brak faktury albo wFirma nie oddała pliku - komunikat do pokazania
     */
    public function forOrder(int $wooOrderId): array
    {
        $doc = $this->invoice($wooOrderId);
        if ($doc === null) {
            $main = (new OrderMerge($this->pdo))->targetOf($wooOrderId);
            $doc = $main !== null ? $this->invoice((int) $main['woo_order_id']) : null;
        }
        if ($doc === null) {
            throw new \RuntimeException('Zamówienie nie ma faktury wystawionej w wFirma.');
        }
        $integration = (new IntegrationAccountRepository($this->pdo))->find((int) $doc['integration_id']);
        if ($integration === null || $integration['type'] !== 'wfirma' || empty($integration['is_active'])) {
            throw new \RuntimeException('Integracja wFirma, w której wystawiono fakturę, jest niedostępna lub wyłączona.');
        }
        $remoteId = (string) $doc['remote_id'];
        if ($this->download !== null) {
            $pdf = ($this->download)($integration['config'], $remoteId);
        } else {
            $r = (new \PasePlugin\Wfirma\WfirmaClient($integration['config']))->downloadPdf($remoteId);
            if (!$r['ok']) {
                throw new \RuntimeException('Nie udało się pobrać faktury z wFirma: ' . $r['message']);
            }
            $pdf = $r['pdf'];
        }
        if (!str_starts_with(ltrim($pdf), '%PDF')) {
            throw new \RuntimeException('wFirma nie zwróciła pliku PDF faktury.');
        }
        return ['name' => 'Faktura-' . $this->orderLabel((int) $doc['woo_order_id']) . '.pdf', 'mime' => 'application/pdf', 'content' => $pdf];
    }

    private function invoice(int $wooOrderId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM order_documents WHERE woo_order_id = ? AND provider = 'wfirma' AND document_type = 'normal' LIMIT 1");
        $st->execute([$wooOrderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function orderLabel(int $wooOrderId): string
    {
        $st = $this->pdo->prepare('SELECT pase_number, order_number FROM woo_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $label = (string) ($row['order_number'] ?? '') ?: (string) ($row['pase_number'] ?? '') ?: (string) $wooOrderId;
        return preg_replace('/[^\w-]+/', '_', $label) ?? (string) $wooOrderId;
    }
}
