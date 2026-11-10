<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use PDO;

/**
 * Publiczne API księgowości dla CRM i innych wtyczek. CRM pyta tylko przez tę klasę, nigdy przez tabele ext_ksg_*.
 * Każda metoda zwraca zwykłe tablice (bez obiektów i PDO), więc po wyniesieniu modułu na osobny serwer zamieni się
 * na wywołania HTTP bez zmian po stronie CRM. Kolejne etapy dopiszą m.in. fakturę do zamówienia i status płatności.
 */
final class Api
{
    private function __construct(private readonly Db $db)
    {
    }

    public static function make(PDO $crm): self
    {
        return new self(Db::connect($crm));
    }

    /** Czy w dniu $date można jeszcze księgować (jest otwarty rok i okres nie jest zamknięty). */
    public function periodOpen(string $date): bool
    {
        $y = (new Ledger($this->db))->yearFor($date);
        return $y !== null && $y['status'] === 'open' && ($y['locked_until'] === null || $y['locked_until'] === '' || $date > $y['locked_until']);
    }

    /**
     * Saldo konta (z analityką) na dzień, w groszach: dodatnie = saldo Wn, ujemne = saldo Ma; null = brak konta.
     */
    public function balance(string $accountNumber, string $date): ?int
    {
        $l = new Ledger($this->db);
        $y = $l->yearFor($date);
        $a = $y !== null ? $l->accountByNumber((string) $y['id'], $accountNumber) : null;
        if ($a === null) {
            return null;
        }
        $r = $l->accountLedger((string) $a['id'], (string) $y['starts_on'], $date);
        return $r['opening'] + $r['debit'] - $r['credit'];
    }

    /** Ile zdarzeń zamówienia trafiło do skrzynki księgowości. */
    public function orderEvents(int $orderId): int
    {
        return (new Inbox($this->db))->countFor($orderId);
    }
}
