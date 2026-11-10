<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Kartoteka kontrahentów księgowości (osobna od klientów CRM). Każdy kontrahent ma kod (0001, 0002…), który jest
 * analityką jego rozrachunków: 200-0001 (odbiorca) i 202-0001 (dostawca). Konta zakładamy w otwartych latach
 * obrotowych przy zapisie kontrahenta - o ile konto 200/202 nie ma jeszcze zapisów bez analityki.
 */
final class Contractors
{
    public const KINDS = ['both' => 'Odbiorca i dostawca', 'customer' => 'Odbiorca', 'supplier' => 'Dostawca'];
    public const RECEIVABLE = '200';
    public const PAYABLE = '202';

    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $id): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_contractors WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function list(string $q = '', bool $withInactive = false): array
    {
        $sql = 'SELECT * FROM ext_ksg_contractors WHERE 1 = 1';
        $args = [];
        if (!$withInactive) {
            $sql .= ' AND active = 1';
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $digits = preg_replace('/\D/', '', $q);
            $sql .= ' AND (name LIKE ? OR code LIKE ? OR city LIKE ?' . ($digits !== '' ? ' OR nip LIKE ?' : '') . ')';
            array_push($args, $like, $like, $like);
            if ($digits !== '') {
                $args[] = '%' . $digits . '%';
            }
        }
        return $this->db->all($sql . ' ORDER BY name', $args);
    }

    /** NIP bez kresek; polski z sumą kontrolną, zagraniczny z prefiksem kraju zostaje jak jest. */
    public static function normalizeNip(string $nip, string $country): string
    {
        $nip = strtoupper(preg_replace('/[\s\-.]/', '', $nip));
        if ($country === 'PL' && str_starts_with($nip, 'PL')) {
            $nip = substr($nip, 2);
        }
        return $nip;
    }

    public function save(?string $id, array $in, string $user): array
    {
        $old = $id !== null ? ($this->get($id) ?? throw new \RuntimeException('Nie ma takiego kontrahenta.')) : null;
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Wpisz nazwę kontrahenta.');
        }
        $country = strtoupper(trim((string) ($in['country'] ?? 'PL'))) ?: 'PL';
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \RuntimeException('Kraj to dwuliterowy kod, np. PL, DE.');
        }
        $nip = self::normalizeNip((string) ($in['nip'] ?? ''), $country);
        if ($nip !== '' && $country === 'PL' && !Pages::validNip($nip)) {
            throw new \RuntimeException('NIP ' . $nip . ' ma złą sumę kontrolną.');
        }
        if ($nip !== '' && !preg_match('/^[0-9A-Z]{4,20}$/', $nip)) {
            throw new \RuntimeException('Niepoprawny numer VAT: ' . $nip . '.');
        }
        if ($nip !== '') {
            $dup = $this->db->one('SELECT id, name FROM ext_ksg_contractors WHERE nip = ? AND country = ?', [$nip, $country]);
            if ($dup !== null && $dup['id'] !== ($old['id'] ?? null)) {
                throw new \RuntimeException('Kontrahent z tym NIP-em już jest: ' . $dup['name'] . '.');
            }
        }
        $kind = isset(self::KINDS[$in['kind'] ?? '']) ? (string) $in['kind'] : 'both';
        $row = ['name' => mb_substr($name, 0, 200), 'nip' => $nip, 'country' => $country, 'kind' => $kind];
        foreach (['street' => 200, 'postcode' => 12, 'city' => 100, 'email' => 150, 'phone' => 40, 'bank_account' => 40, 'notes' => 2000] as $k => $len) {
            $row[$k] = mb_substr(trim((string) ($in[$k] ?? '')), 0, $len);
        }
        $row['bank_account'] = preg_replace('/\s+/', '', strtoupper($row['bank_account']));
        $row['updated_at'] = Db::now();
        if ($old === null) {
            $n = (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_contractors')['n'] ?? 0);
            do {
                $code = str_pad((string) (++$n), 4, '0', STR_PAD_LEFT);
            } while ($this->db->one('SELECT id FROM ext_ksg_contractors WHERE code = ?', [$code]) !== null);
            $row += ['id' => Db::id(), 'code' => $code, 'active' => 1, 'created_at' => Db::now()];
            $this->db->insert('ext_ksg_contractors', $row);
            $this->db->log($user, 'contractor.create', 'contractor', $row['id'], $code . ' ' . $row['name'] . ($nip !== '' ? ', NIP ' . $nip : ''));
            $c = $this->get($row['id']);
        } else {
            $this->db->update('ext_ksg_contractors', $old['id'], $row);
            $this->db->log($user, 'contractor.update', 'contractor', $old['id'], $old['code'] . ' ' . $row['name']);
            $c = $this->get($old['id']);
        }
        $this->ensureAccounts($c, $user);
        return $c;
    }

    public function setActive(string $id, bool $active, string $user): void
    {
        $c = $this->get($id) ?? throw new \RuntimeException('Nie ma takiego kontrahenta.');
        $this->db->update('ext_ksg_contractors', $id, ['active' => $active ? 1 : 0, 'updated_at' => Db::now()]);
        $this->db->log($user, $active ? 'contractor.activate' : 'contractor.deactivate', 'contractor', $id, (string) $c['name']);
    }

    /** Numery kont rozrachunkowych kontrahenta według jego rodzaju. @return array<string,string> '200'|'202' => numer analityki */
    public static function accountNumbers(array $c): array
    {
        $out = [];
        if ($c['kind'] !== 'supplier') {
            $out[self::RECEIVABLE] = self::RECEIVABLE . '-' . $c['code'];
        }
        if ($c['kind'] !== 'customer') {
            $out[self::PAYABLE] = self::PAYABLE . '-' . $c['code'];
        }
        return $out;
    }

    /**
     * Zakłada analitykę rozrachunków kontrahenta w otwartych latach, gdzie konto 200/202 istnieje i nie ma zapisów
     * bezpośrednio na sobie. Zwraca listę założonych kont.
     * @return list<string>
     */
    public function ensureAccounts(array $c, string $user): array
    {
        $ledger = new Ledger($this->db);
        $made = [];
        foreach ($this->db->all("SELECT * FROM ext_ksg_years WHERE status = 'open'") as $y) {
            foreach (self::accountNumbers($c) as $parent => $number) {
                $acc = $ledger->accountByNumber((string) $y['id'], $number);
                $name = mb_substr($c['name'], 0, 200);
                if ($acc !== null) {
                    if ($acc['name'] !== $name) {
                        $this->db->update('ext_ksg_accounts', (string) $acc['id'], ['name' => $name, 'updated_at' => Db::now()]);
                    }
                    continue;
                }
                $p = $ledger->accountByNumber((string) $y['id'], (string) $parent);
                if ($p === null || (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_entries WHERE account_id = ?', [$p['id']])['n'] ?? 0) > 0) {
                    continue;
                }
                $ledger->saveAccount((string) $y['id'], null, ['number' => $number, 'name' => $name, 'kind' => 'AP',
                    'notes' => 'Rozrachunki z kontrahentem ' . $c['code']], $user);
                $made[] = $number . ' (' . $y['name'] . ')';
            }
        }
        return $made;
    }

    /**
     * Konto rozrachunkowe kontrahenta w roku: analityka, a gdy jej nie ma - konto syntetyczne bez analityki.
     */
    public function settlementAccount(array $c, string $yearId, string $parent): ?string
    {
        $ledger = new Ledger($this->db);
        $mine = $ledger->accountByNumber($yearId, $parent . '-' . $c['code']);
        if ($mine !== null) {
            return (string) $mine['number'];
        }
        $p = $ledger->accountByNumber($yearId, $parent);
        $hasChildren = $p !== null && $this->db->one('SELECT id FROM ext_ksg_accounts WHERE year_id = ? AND number LIKE ?', [$yearId, $parent . '-%']) !== null;
        return $p !== null && !$hasChildren ? (string) $p['number'] : null;
    }
}
