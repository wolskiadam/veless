<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use Pase\Support\Http;

/**
 * Pobieranie dokumentów z wFirma po API (tylko odczyt): faktury sprzedaży (invoices/find) i wydatki (expenses/find)
 * z kontrahentami, rozbiciem VAT, terminem i stanem zapłaty.
 *
 * API wFirma nie wydaje dziennika księgowań ani obrotów kont, więc księgi przychodzą dalej z pliku JPK_KR.
 * Po API przychodzą dokumenty: zapisujemy je w `ext_ksg_wf_docs`, a potem przypinamy do dokumentów z ksiąg
 * po numerze (kontrahent, termin, pozycje VAT, gdy dokument ich nie ma). Stan zapłaty z wFirma porównujemy
 * z naszymi rozrachunkami na stronie Porównanie.
 *
 * Klucze API leżą w ustawieniach wtyczki (pola secret - rdzeń trzyma je zaszyfrowane); rozszerzenie nie czyta
 * kluczy integracji wFirma z CRM.
 */
final class Wfirma
{
    public const BASE_URL = 'https://api2.wfirma.pl';
    public const AUTO_EVERY = ['0' => 'Nie, tylko przyciskiem', '60' => 'Co godzinę', '360' => 'Co 6 godzin', '1440' => 'Raz na dobę'];
    /** Typy faktur, które są w rejestrze sprzedaży; proformy, szkice i paragony pomijamy (paragony idą do JPK zbiorczo). */
    private const SALE_TYPES = ['normal', 'margin', 'bill', 'correction'];
    private const PAGE = 100;
    private const MAX_PAGES = 100;

    /** @var \Closure(string,string,array<string,string>,?array):array{status:int,json:array} */
    private \Closure $http;

    /**
     * @param array{access_key?:string,secret_key?:string,app_key?:string,company_id?:string} $cfg
     * @param ?\Closure $http testy podstawiają odpowiedzi bez sieci
     */
    public function __construct(private readonly Db $db, private readonly array $cfg, ?\Closure $http = null)
    {
        $this->http = $http ?? static function (string $method, string $url, array $headers, ?array $body): array {
            $r = Http::request($method, $url, $headers, $body, 60);
            return ['status' => $r->status, 'json' => $r->json()];
        };
    }

    public function configured(): bool
    {
        foreach (['access_key', 'secret_key', 'app_key', 'company_id'] as $k) {
            if (trim((string) ($this->cfg[$k] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** @return array{status:int,json:array} */
    private function call(string $method, string $path, ?array $body = null): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Wpisz klucze API wFirma (accessKey, secretKey, appKey) i ID firmy w ustawieniach wtyczki Księgowość.');
        }
        $url = self::BASE_URL . '/' . $path . '?inputFormat=json&outputFormat=json&company_id=' . rawurlencode(trim((string) $this->cfg['company_id']));
        $r = ($this->http)($method, $url, [
            'accessKey' => trim((string) $this->cfg['access_key']), 'secretKey' => trim((string) $this->cfg['secret_key']),
            'appKey' => trim((string) $this->cfg['app_key']), 'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ], $body);
        if ($r['status'] === 0) {
            throw new \RuntimeException('Brak połączenia z wFirma.');
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            throw new \RuntimeException('wFirma odrzuciła klucze API - sprawdź accessKey, secretKey i appKey.');
        }
        $code = (string) ($r['json']['status']['code'] ?? '');
        if ($code !== 'OK' && $code !== 'NOT FOUND') {
            throw new \RuntimeException('wFirma: ' . ($this->error($r['json']) ?? ($code !== '' ? $code : 'HTTP ' . $r['status'])));
        }
        return $r;
    }

    private function error(array $json): ?string
    {
        $m = $json['status']['message'] ?? null;
        if (is_string($m) && $m !== '') {
            return $m;
        }
        $msgs = [];
        array_walk_recursive($json, static function ($v, $k) use (&$msgs): void {
            if ($k === 'message' && is_string($v) && $v !== '') {
                $msgs[] = $v;
            }
        });
        return $msgs !== [] ? implode('; ', array_unique($msgs)) : null;
    }

    /** Sprawdzenie kluczy: dane firmy. */
    public function test(): string
    {
        $r = $this->call('GET', 'companies/get/' . rawurlencode(trim((string) ($this->cfg['company_id'] ?? ''))));
        $c = $r['json']['companies'][0]['company'] ?? $r['json']['companies']['company'] ?? [];
        return 'Połączenie z wFirma działa' . (is_array($c) && ($c['name'] ?? '') !== '' ? ': ' . $c['name'] : '') . '.';
    }

    /**
     * Wszystkie rekordy modułu z okresu (po dacie wystawienia), strona po stronie.
     * @return list<array<string,mixed>>
     */
    private function find(string $module, string $item, string $field, string $from, string $to): array
    {
        $out = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $r = $this->call('POST', $module . '/find', [$module => ['parameters' => [
                'page' => $page, 'limit' => self::PAGE,
                'conditions' => (object) [
                    '0' => ['condition' => ['field' => $field, 'operator' => 'ge', 'value' => $from]],
                    '1' => ['condition' => ['field' => $field, 'operator' => 'le', 'value' => $to]],
                ],
            ]]]);
            $rows = self::list($r['json'][$module] ?? [], $item);
            foreach ($rows as $row) {
                $out[] = $row;
            }
            if (count($rows) < self::PAGE) {
                return $out;
            }
        }
        throw new \RuntimeException('Za dużo dokumentów w jednym okresie (ponad ' . self::PAGE * self::MAX_PAGES . ') - pobierz krótszy okres.');
    }

    /**
     * wFirma zwraca listy jako {"0":{"invoice":{...}},"1":{...},"parameters":{...}} albo pojedynczy {"invoice":{...}}.
     * @return list<array<string,mixed>>
     */
    private static function list(mixed $node, string $item, bool $needId = true): array
    {
        if (!is_array($node)) {
            return [];
        }
        if (isset($node[$item]) && is_array($node[$item])) {
            $node = [[$item => $node[$item]]];
        }
        $out = [];
        foreach ($node as $k => $row) {
            if ($k === 'parameters' || !is_array($row)) {
                continue;
            }
            $v = $row[$item] ?? null;
            if (is_array($v) && (!$needId || ($v['id'] ?? '') !== '')) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function pick(array $a, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $a;
            foreach (explode('.', $k) as $part) {
                $v = is_array($v) ? ($v[$part] ?? null) : null;
            }
            if (is_scalar($v) && trim((string) $v) !== '' && (string) $v !== '0000-00-00') {
                return trim((string) $v);
            }
        }
        return '';
    }

    private static function date(string $v): string
    {
        $v = substr($v, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    /** Stawka z kodu VAT wFirma (23, 8, 5, 0, ZW, NP, OO) albo z proporcji VAT do netto. */
    private static function rate(array $vc, int $net, int $vat): string
    {
        $code = strtoupper(self::pick($vc, ['vat_code.code', 'vat_code.name', 'code', 'vat']));
        $code = rtrim($code, '%');
        if (in_array($code, ['23', '22', '8', '7', '5', '0'], true)) {
            return $code;
        }
        foreach (['ZW' => 'zw', 'NP' => 'np', 'OO' => 'oo'] as $k => $v) {
            if (str_starts_with($code, $k)) {
                return $v;
            }
        }
        return $vat === 0 ? '0' : JpkImport::rateOf($net, $vat);
    }

    /**
     * Wspólna postać dokumentu z wFirma (kwoty w groszach).
     * @return array<string,mixed>
     */
    public static function normalize(string $kind, array $d): array
    {
        $sale = $kind === 'sale';
        $date = self::date(self::pick($d, ['date']));
        $taxDate = self::date(self::pick($d, $sale ? ['disposaldate', 'date'] : ['taxregister_date', 'date']));
        $period = substr($taxDate !== '' ? $taxDate : $date, 0, 7);
        $lines = [];
        foreach (self::list($d['vat_contents'] ?? [], 'vat_content', false) as $vc) {
            $net = JpkImport::gr(self::pick($vc, ['netto', 'net']));
            $vat = JpkImport::gr(self::pick($vc, ['tax', 'vat_value']));
            if ($net === 0 && $vat === 0) {
                continue;
            }
            $lines[] = ['register' => $sale ? 'S' : 'Z', 'period' => $period, 'rate' => self::rate($vc, $net, $vat), 'net_gr' => $net, 'vat_gr' => $vat,
                'kind' => 'other', 'deduct' => '100'];
        }
        $net = JpkImport::gr(self::pick($d, ['netto', 'net']));
        $vat = JpkImport::gr(self::pick($d, ['tax']));
        $gross = JpkImport::gr(self::pick($d, ['total', 'brutto']));
        if ($gross === 0 && ($net !== 0 || $vat !== 0)) {
            $gross = $net + $vat;
        }
        if ($lines === [] && ($net !== 0 || $vat !== 0)) {
            $lines[] = ['register' => $sale ? 'S' : 'Z', 'period' => $period, 'rate' => $vat === 0 ? '0' : JpkImport::rateOf($net, $vat), 'net_gr' => $net, 'vat_gr' => $vat,
                'kind' => 'other', 'deduct' => '100'];
        }
        $paidRaw = self::pick($d, ['alreadypaid', 'already_paid', 'paid_amount']);
        $remRaw = self::pick($d, ['remaining']);
        $paidFlag = self::pick($d, ['paymentstate', 'paid']);
        if ($paidRaw !== '') {
            $paid = JpkImport::gr($paidRaw);
        } elseif ($remRaw !== '') {
            $paid = $gross - JpkImport::gr($remRaw);
        } else {
            $paid = in_array(strtolower($paidFlag), ['1', 'paid', 'true'], true) ? $gross : 0;
        }
        return [
            'kind' => $kind,
            'remote_id' => (string) $d['id'],
            'type' => self::pick($d, ['type']),
            'number' => self::pick($d, ['fullnumber', 'number']),
            'doc_date' => $date,
            'period' => $period,
            'due_date' => self::date(self::pick($d, ['paymentdate', 'payment_date'])),
            'name' => self::pick($d, ['contractor_detail.name', 'contractor.name', 'contractor_detail.altname', 'contractor.altname']),
            'nip' => self::pick($d, ['contractor_detail.nip', 'contractor.nip']),
            'country' => self::pick($d, ['contractor_detail.country', 'contractor.country']),
            'currency' => self::pick($d, ['currency']) ?: 'PLN',
            'net_gr' => $net, 'vat_gr' => $vat, 'gross_gr' => $gross, 'paid_gr' => $paid,
            'lines' => $lines,
        ];
    }

    /** Czy fakturę z wFirma bierzemy do ksiąg (sprzedaż: faktury i korekty; wydatki: wszystkie). */
    public static function wanted(string $kind, string $type): bool
    {
        return $kind === 'purchase' || in_array($type, self::SALE_TYPES, true);
    }

    /**
     * Pobiera faktury sprzedaży i wydatki z okresu, zapisuje je i przypina do dokumentów z ksiąg.
     * @return array{from:string,to:string,sales:int,purchases:int,skipped:int,matched:int,contractors:int,unmatched:list<string>}
     */
    public function sync(string $from, string $to, string $user): array
    {
        if (!Ledger::validDate($from) || !Ledger::validDate($to) || $from > $to) {
            throw new \RuntimeException('Zły okres pobierania.');
        }
        $docs = [];
        $skipped = 0;
        foreach ($this->find('invoices', 'invoice', 'Invoice.date', $from, $to) as $d) {
            $n = self::normalize('sale', $d);
            if (!self::wanted('sale', $n['type'])) {
                ++$skipped;
                continue;
            }
            $docs[] = $n;
        }
        $sales = count($docs);
        foreach ($this->find('expenses', 'expense', 'Expense.date', $from, $to) as $d) {
            $docs[] = self::normalize('purchase', $d);
        }
        $purchases = count($docs) - $sales;
        $r = $this->store($docs, $user);
        $this->db->setMeta('wf_last_sync', Db::now());
        $this->db->setMeta('wf_last_result', (string) json_encode(['ok' => true, 'from' => $from, 'to' => $to, 'sales' => $sales, 'purchases' => $purchases,
            'matched' => $r['matched'], 'unmatched' => count($r['unmatched'])], JSON_UNESCAPED_UNICODE));
        $this->db->log($user, 'import.wfirma', 'wfirma', '', $from . ' - ' . $to . ': faktur ' . $sales . ', wydatków ' . $purchases . ', przypiętych ' . $r['matched']
            . ($r['unmatched'] !== [] ? ', bez dokumentu w księgach ' . count($r['unmatched']) : ''));
        return ['from' => $from, 'to' => $to, 'sales' => $sales, 'purchases' => $purchases, 'skipped' => $skipped] + $r;
    }

    /**
     * Zapisuje dokumenty (po kind + remote_id, nowsze zastępują) i przypina je do ksiąg.
     * @param list<array<string,mixed>> $docs
     * @return array{matched:int,contractors:int,unmatched:list<string>}
     */
    public function store(array $docs, string $user): array
    {
        return $this->db->tx(function () use ($docs, $user): array {
            $now = Db::now();
            foreach ($docs as $n) {
                $row = ['kind' => $n['kind'], 'remote_id' => $n['remote_id'], 'type' => mb_substr($n['type'], 0, 30), 'number' => mb_substr($n['number'], 0, 60),
                    'doc_date' => $n['doc_date'], 'period' => $n['period'], 'due_date' => $n['due_date'] !== '' ? $n['due_date'] : null,
                    'name' => mb_substr($n['name'], 0, 200), 'nip' => mb_substr($n['nip'], 0, 30), 'currency' => mb_substr($n['currency'], 0, 3),
                    'net_gr' => $n['net_gr'], 'vat_gr' => $n['vat_gr'], 'gross_gr' => $n['gross_gr'], 'paid_gr' => $n['paid_gr'],
                    'data' => (string) json_encode($n, JSON_UNESCAPED_UNICODE), 'fetched_at' => $now];
                $old = $this->db->one('SELECT id FROM ext_ksg_wf_docs WHERE kind = ? AND remote_id = ?', [$n['kind'], $n['remote_id']]);
                if ($old !== null) {
                    $this->db->update('ext_ksg_wf_docs', (string) $old['id'], $row);
                } else {
                    $this->db->insert('ext_ksg_wf_docs', ['id' => Db::id(), 'doc_id' => null] + $row);
                }
            }
            return $this->attach(array_map(static fn(array $n): string => $n['kind'] . ':' . $n['remote_id'], $docs), $user);
        });
    }

    /**
     * Przypina zapisane dokumenty z wFirma do zaksięgowanych dokumentów po numerze (FS dla sprzedaży, FZ dla zakupów).
     * Uzupełnia tylko to, czego dokument nie ma: kontrahenta, termin płatności i pozycje VAT (te z JPK_V7 mają pierwszeństwo).
     * @param list<string> $keys kind:remote_id; puste = wszystkie
     * @return array{matched:int,contractors:int,unmatched:list<string>}
     */
    public function attach(array $keys, string $user): array
    {
        $rows = $this->db->all('SELECT * FROM ext_ksg_wf_docs ORDER BY doc_date, number');
        if ($keys !== []) {
            $want = array_flip($keys);
            $rows = array_values(array_filter($rows, static fn(array $r): bool => isset($want[$r['kind'] . ':' . $r['remote_id']])));
        }
        $index = [];
        foreach ($this->db->all("SELECT id, year_id, number, type, contractor_id, due_date FROM ext_ksg_docs WHERE status = 'posted' AND reversal_of IS NULL") as $d) {
            $index[Settlements::norm((string) $d['number'])][] = $d;
        }
        $vat = new Vat($this->db);
        $imp = new JpkImport($this->db);
        $matched = 0;
        $made = 0;
        $unmatched = [];
        foreach ($rows as $r) {
            $key = Settlements::norm((string) $r['number']);
            $want = $r['kind'] === 'sale' ? 'FS' : 'FZ';
            $pick = null;
            foreach ($index[$key] ?? [] as $d) {
                if ($d['type'] === $want) {
                    $pick = $d;
                    break;
                }
            }
            $pick ??= ($index[$key] ?? [])[0] ?? null;
            if ($key === '' || $pick === null) {
                if ($r['doc_id'] !== null) {
                    $this->db->update('ext_ksg_wf_docs', (string) $r['id'], ['doc_id' => null]);
                }
                $unmatched[] = ($r['kind'] === 'sale' ? 'sprzedaż ' : 'zakup ') . $r['number'] . ($r['name'] !== '' ? ' (' . $r['name'] . ')' : '');
                continue;
            }
            ++$matched;
            $this->db->update('ext_ksg_wf_docs', (string) $r['id'], ['doc_id' => $pick['id']]);
            $data = (array) json_decode((string) $r['data'], true);
            $set = [];
            if (($pick['contractor_id'] ?? null) === null && $r['name'] !== '') {
                $cid = $imp->contractorFor(['nip' => $r['nip'], 'country' => (string) ($data['country'] ?? ''), 'name' => $r['name']],
                    $r['kind'] === 'sale' ? 'S' : 'Z', $user, $made, 'Z wFirma (API)');
                if ($cid !== null) {
                    $set['contractor_id'] = $cid;
                    $set['counterparty'] = mb_substr((string) $r['name'], 0, 200);
                }
            }
            if ((string) ($pick['due_date'] ?? '') === '' && $r['due_date'] !== null) {
                $set['due_date'] = $r['due_date'];
            }
            if ($set !== []) {
                $this->db->update('ext_ksg_docs', (string) $pick['id'], $set + ['updated_at' => Db::now()]);
            }
            $lines = (array) ($data['lines'] ?? []);
            if ($lines !== [] && $vat->forDoc((string) $pick['id']) === []) {
                $vat->replace((string) $pick['id'], $lines);
            }
        }
        return ['matched' => $matched, 'contractors' => $made, 'unmatched' => $unmatched];
    }

    /**
     * Automatyczne pobieranie z cron.tick: co tyle minut, ile w ustawieniach, okres od początku poprzedniego miesiąca
     * do dziś (żeby złapać zapłaty i korekty starszych faktur). Błąd zapisuje do pokazania na stronie.
     */
    public function autoSync(int $now, string $everyMinutes): void
    {
        $every = (int) $everyMinutes;
        if ($every <= 0 || !$this->configured()) {
            return;
        }
        $last = $this->db->meta('wf_last_try', '');
        if ($last !== '' && strtotime($last) !== false && $now - (int) strtotime($last) < $every * 60) {
            return;
        }
        $this->db->setMeta('wf_last_try', date('Y-m-d H:i:s', $now));
        $from = date('Y-m-01', strtotime(date('Y-m-01', $now) . ' -1 month'));
        try {
            $this->sync($from, date('Y-m-d', $now), 'automat');
        } catch (\Throwable $e) {
            $this->db->setMeta('wf_last_result', (string) json_encode(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'at' => date('Y-m-d H:i:s', $now)],
                JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Porównanie stanu zapłaty: wFirma vs nasze rozrachunki (pozostało do zapłaty).
     * @return list<array<string,mixed>>
     */
    public function compare(string $from, string $to): array
    {
        $st = new Settlements($this->db);
        $out = [];
        foreach ($this->db->all('SELECT * FROM ext_ksg_wf_docs WHERE doc_date >= ? AND doc_date <= ? ORDER BY kind DESC, doc_date, number', [$from, $to]) as $r) {
            $wfLeft = (int) $r['gross_gr'] - (int) $r['paid_gr'];
            $ours = null;
            if ($r['doc_id'] !== null) {
                $ours = 0;
                foreach ($st->items(['doc' => (string) $r['doc_id']]) as $i) {
                    if ($i['kind'] === 'debt') {
                        $ours += (int) $i['remaining'];
                    }
                }
            }
            // Korekta in minus u nas jest zapłatą na koncie rozrachunkowym, więc nie zostawia nic do zapłaty.
            $out[] = $r + ['wf_left' => $wfLeft, 'ours_left' => $ours, 'ok' => $ours !== null && $r['currency'] === 'PLN' && $ours === max(0, $wfLeft)];
        }
        return $out;
    }

    public function lastResult(): array
    {
        return (array) json_decode($this->db->meta('wf_last_result', '{}'), true) + ['at' => $this->db->meta('wf_last_sync', '')];
    }
}
