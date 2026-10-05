<?php
declare(strict_types=1);

namespace PasePlugin\Wfirma;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient API wFirma (księgowość / faktury) — logika należąca do WTYCZKI
 * (samowystarczalna, w katalogu integrations/wfirma/). Rdzeń woła ją wyłącznie
 * przez kontrakt Invoicing z rejestru wtyczek.
 *
 * wFirma API v2 (https://api2.wfirma.pl):
 *  - Autoryzacja nagłówkami: accessKey, secretKey, appKey.
 *  - Operacje w kontekście firmy: parametr ?company_id=... w URL.
 *  - Format: ?inputFormat=json&outputFormat=json, body/odpowiedź w JSON.
 *  - Endpoint wystawienia dokumentu: /invoices/add
 *  - Odpowiedź: status.code === 'OK' oraz invoices[0].invoice.id.
 *
 * Korzysta wyłącznie ze wspólnych narzędzi rdzenia (Http, Logger).
 *
 * @param array $config oczekuje: base_url, access_key, secret_key, app_key, company_id
 */
final class WfirmaClient
{
    public function __construct(private readonly array $config) {}

    private function baseUrl(): string
    {
        $url = (string) ($this->config['base_url'] ?? 'https://api2.wfirma.pl');
        return rtrim($url !== '' ? $url : 'https://api2.wfirma.pl', '/');
    }

    private function companyId(): string
    {
        return (string) ($this->config['company_id'] ?? '');
    }

    /** Nagłówki uwierzytelniające wg schematu wFirma API v2. */
    private function authHeaders(): array
    {
        return [
            'accessKey'    => (string) ($this->config['access_key'] ?? ''),
            'secretKey'    => (string) ($this->config['secret_key'] ?? ''),
            'appKey'       => (string) ($this->config['app_key'] ?? ''),
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /** Buduje URL z wymuszonym JSON i (opcjonalnie) company_id. */
    private function url(string $path): string
    {
        $q = 'inputFormat=json&outputFormat=json';
        $companyId = $this->companyId();
        if ($companyId !== '') {
            $q .= '&company_id=' . rawurlencode($companyId);
        }
        return $this->baseUrl() . '/' . ltrim($path, '/') . '?' . $q;
    }

    private function post(string $path, array $payload = []): \Pase\Support\HttpResponse
    {
        $url = $this->url($path);
        $res = Http::request('POST', $url, $this->authHeaders(), $payload);
        Logger::apiResponse('wfirma', 'POST', $url, $res->status);
        return $res;
    }

    private function get(string $path): \Pase\Support\HttpResponse
    {
        $url = $this->url($path);
        $res = Http::request('GET', $url, $this->authHeaders());
        Logger::apiResponse('wfirma', 'GET', $url, $res->status);
        return $res;
    }

    /**
     * Test połączenia: pobiera dane firmy (companies/get). Weryfikuje klucze API
     * i poprawność company_id bez efektów ubocznych.
     *
     * @return array{ok:bool, message:string, status:int}
     */
    public function testConnection(): array
    {
        foreach (['access_key' => 'accessKey', 'secret_key' => 'secretKey', 'app_key' => 'appKey'] as $key => $label) {
            if (($this->config[$key] ?? '') === '') {
                return ['ok' => false, 'message' => "Brak wartości: {$label}.", 'status' => 0];
            }
        }
        if ($this->companyId() === '') {
            return ['ok' => false, 'message' => 'Brak company_id (ID firmy w wFirma).', 'status' => 0];
        }

        // Endpoint companies/get wymaga ID firmy zarówno w ścieżce,
        // jak i w parametrze company_id.
        $res = $this->get('companies/get/' . rawurlencode($this->companyId()));

        if ($res->status === 0) {
            return ['ok' => false, 'message' => 'Brak połączenia z wFirma (sprawdź sieć / base_url).', 'status' => 0];
        }
        if ($res->status === 401 || $res->status === 403) {
            return ['ok' => false, 'message' => 'Odrzucono uwierzytelnianie — sprawdź accessKey / secretKey / appKey.', 'status' => $res->status];
        }

        $json = $res->json();
        $code = $json['status']['code'] ?? null;
        if ($res->isSuccess() && $code === 'OK') {
            return ['ok' => true, 'message' => 'Połączenie OK — uwierzytelnianie wFirma działa.', 'status' => $res->status];
        }

        $msg = $this->extractError($json) ?? ('Odpowiedź API: HTTP ' . $res->status);
        return ['ok' => false, 'message' => (string) $msg, 'status' => $res->status];
    }

    /**
     * Wystawia dokument sprzedaży (faktura / proforma / paragon).
     *
     * @param array $contractor dane nabywcy (name, nip, email, street, city, zip, country...)
     * @param array $positions  pozycje: [ ['name'=>..,'count'=>..,'price'=>..,'vat'=>..], ... ]
     * @param string $type      normal | proforma | receipt | receipt_fiscal
     *
     * @return array{ok:bool, message:string, document_id?:string, pdf_url?:string, raw:array}
     */
    public function issueDocument(array $contractor, array $positions, string $type = 'normal', array $options = []): array
    {
        if ($positions === []) {
            return ['ok' => false, 'message' => 'Brak pozycji na dokumencie.', 'raw' => []];
        }

        // Typ dokumentu musi odpowiadać statusowi firmy, nie stawce VAT towaru.
        // Nie zgadujemy statusu w razie błędu odczytu, aby nie wystawić złego dokumentu.
        if ($this->companyId() === '') {
            return ['ok' => false, 'message' => 'Brak ID firmy w konfiguracji wFirma.', 'raw' => []];
        }
        $companyResponse = $this->get('companies/get/' . rawurlencode($this->companyId()));
        $companyJson = $companyResponse->json();
        $company = $companyJson['companies'][0]['company'] ?? $companyJson['companies']['company'] ?? [];
        $vatPayer = $company['vat_payer'] ?? null;
        if (!$companyResponse->isSuccess() || ($companyJson['status']['code'] ?? '') !== 'OK'
            || !in_array($vatPayer, [0, 1, '0', '1', false, true], true)) {
            return [
                'ok' => false,
                'message' => 'Nie można ustalić statusu VAT firmy w wFirma. '
                    . ($this->extractError($companyJson) ?? 'Brak poprawnego pola vat_payer w odpowiedzi API.'),
                'raw' => $companyJson,
            ];
        }
        $options['vat_payer'] = (bool) $vatPayer;
        try {
            $contractor['id'] = $this->resolveContractor($this->mapContractor($contractor));
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'raw' => []];
        }
        $body = $this->invoicePayload($contractor, $positions, $type, $options);

        $res  = $this->post('invoices/add', $body);
        $json = $res->json();
        $code = $json['status']['code'] ?? null;

        if (!$res->isSuccess() || $code !== 'OK') {
            $msg = $this->extractError($json) ?? ('wFirma: nie udało się wystawić dokumentu (HTTP ' . $res->status . ').');
            return ['ok' => false, 'message' => (string) $msg, 'raw' => $json];
        }

        // W JSON wFirma numeruje rekordy kluczami "0", "1", ... nawet wtedy,
        // gdy odpowiedź zawiera tylko jeden dokument. Wariant bez numeru
        // zostaje jako fallback dla zgodności z nietypowymi odpowiedziami.
        $invoice = $json['invoices'][0]['invoice'] ?? $json['invoices']['invoice'] ?? [];
        $id = isset($invoice['id']) ? (string) $invoice['id'] : null;

        return [
            'ok'          => $id !== null && $id !== '',
            // Numer (np. „FV 12/2026”) trafia do opisu - okienko ikon na liście zamówień go pokazuje.
            'message'     => $id ? trim('Dokument ' . (string) ($invoice['fullnumber'] ?? '')) . ' wystawiony.' : 'Dokument utworzony, ale brak ID w odpowiedzi.',
            'document_id' => $id ?? '',
            'pdf_url'     => $id ? $this->downloadUrl($id) : '',
            'raw'         => $json,
        ];
    }

    /** Buduje strukturę JSON wymaganą przez invoices/add. */
    private function invoicePayload(array $contractor, array $positions, string $type, array $options): array
    {
        $contents = [];
        foreach (array_values($positions) as $i => $position) {
            $mappedPosition = $this->mapPosition($position);
            if (($options['vat_payer'] ?? true) === false) {
                unset($mappedPosition['vat']);
            }
            $contents[(string) $i] = ['invoicecontent' => $mappedPosition];
        }

        $mappedContractor = $this->mapContractor($contractor);
        $invoice = [
            'type'            => $this->mapType($type, $options['vat_payer'] ?? true),
            // Ceny WooCommerce traktujemy jako brutto; jawne ustawienie
            // zapobiega przeliczeniu ich przez wFirma jako cen netto.
            'price_type'      => 'brutto',
            'currency'        => (string) ($options['currency'] ?? 'PLN'),
            'id_external'     => (string) ($options['id_external'] ?? ''),
            'description'     => (string) ($options['description'] ?? ''),
            // wFirma oczekuje obiektu z kluczami "0", "1", ...,
            // a nie tablicy JSON. Rzutowanie wymusza prawidłowy kształt.
            'invoicecontents' => (object) $contents,
        ];

        // Dane nabywcy zapisano w CRM przed wystawieniem dokumentu.
        foreach (['paymentmethod', 'alreadypaid_initial'] as $key) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $invoice[$key] = (string) $options[$key];
            }
        }
        $invoice['contractor'] = ['id' => (string) $contractor['id']];
        if ($type === 'receipt_fiscal' && !empty($mappedContractor['email'])) {
            $invoice['ereceipt_integration_receipt'] = [
                'email_to_auto_send' => $mappedContractor['email'],
            ];
        }

        return [
            // Także pojedynczy rekord musi być numerowany w formacie JSON wFirma.
            'invoices' => (object) ['0' => [
                'invoice' => $invoice,
            ]],
        ];
    }

    /**
     * Faktura z wFirma z pozycjami (do przygotowania korekty). GET invoices/get/{id}
     * @return array{ok:bool,message:string,invoice:array{id:string,number:string,type:string,contractor_id:string,currency:string,lines:array<int,array{id:string,name:string,count:float,price:float,vat:string,unit:string}>}|null}
     */
    public function getInvoice(string $documentId): array
    {
        if (!ctype_digit($documentId) || $this->companyId() === '') {
            return ['ok' => false, 'message' => 'Brak poprawnego ID dokumentu lub firmy w wFirma.', 'invoice' => null];
        }
        $res = $this->get('invoices/get/' . rawurlencode($documentId));
        $json = $res->json();
        if (!$res->isSuccess() || ($json['status']['code'] ?? '') !== 'OK') {
            return ['ok' => false, 'message' => $this->extractError($json) ?? ('wFirma: HTTP ' . $res->status), 'invoice' => null];
        }
        $inv = $json['invoices'][0]['invoice'] ?? $json['invoices']['invoice'] ?? null;
        if (!is_array($inv) || empty($inv['id'])) {
            return ['ok' => false, 'message' => 'wFirma nie zwróciła faktury.', 'invoice' => null];
        }
        $lines = [];
        $contents = $inv['invoicecontents'] ?? [];
        if (isset($contents['invoicecontent'])) {
            $contents = [['invoicecontent' => $contents['invoicecontent']]];
        }
        foreach ((array) $contents as $row) {
            $c = is_array($row) ? ($row['invoicecontent'] ?? null) : null;
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $lines[] = [
                'id'    => (string) $c['id'],
                'name'  => (string) ($c['name'] ?? ''),
                'count' => (float) ($c['count'] ?? 0),
                'price' => (float) ($c['price'] ?? 0),
                'vat'   => (string) ($c['vat'] ?? ''),
                'unit'  => (string) ($c['unit'] ?? ''),
            ];
        }
        return ['ok' => true, 'message' => '', 'invoice' => [
            'id'            => (string) $inv['id'],
            'number'        => (string) ($inv['fullnumber'] ?? $inv['number'] ?? ''),
            'type'          => (string) ($inv['type'] ?? ''),
            'contractor_id' => (string) ($inv['contractor']['id'] ?? ''),
            'currency'      => (string) ($inv['currency'] ?? 'PLN'),
            'lines'         => $lines,
        ]];
    }

    /** Typy wFirma sprzedaży do klienta: faktura VAT, VAT marża, rachunek (bez VAT). Paragony to receipt_*. */
    public const SALE_INVOICE_TYPES = ['normal', 'margin', 'bill'];

    /**
     * Paragony i faktury sprzedaży wystawione w wFirma w podanym okresie (także poza CRM, np. przez BaseLinker).
     * Tylko odczyt: POST invoices/find z warunkami na datę; typy filtrujemy po stronie CRM
     * (bez proform, korekt i innych dokumentów, które nie są sprzedażą do zamówienia).
     * @return array{ok:bool,message:string,receipts:list<array{id:string,number:string,type:string,date:string,total:float,buyer:string,nip:string,text:string}>,truncated:bool}
     */
    public function findSaleDocuments(string $from, string $to, int $maxPages = 50): array
    {
        if ($this->companyId() === '') {
            return ['ok' => false, 'message' => 'Brak ID firmy w wFirma.', 'receipts' => [], 'truncated' => false];
        }
        $receipts = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $res = $this->post('invoices/find', ['invoices' => ['parameters' => [
                'page' => $page, 'limit' => 100,
                'conditions' => (object) [
                    '0' => ['condition' => ['field' => 'Invoice.date', 'operator' => 'ge', 'value' => $from]],
                    '1' => ['condition' => ['field' => 'Invoice.date', 'operator' => 'le', 'value' => $to]],
                ],
            ]]]);
            $json = $res->json();
            if (!$res->isSuccess() || ($json['status']['code'] ?? '') !== 'OK') {
                return ['ok' => false, 'message' => 'Odczyt dokumentów z wFirma: ' . ($this->extractError($json) ?? 'HTTP ' . $res->status),
                    'receipts' => [], 'truncated' => false];
            }
            $rows = $json['invoices'] ?? [];
            if (isset($rows['invoice'])) {
                $rows = [['invoice' => $rows['invoice']]];
            }
            $count = 0;
            foreach ((array) $rows as $row) {
                $inv = is_array($row) ? ($row['invoice'] ?? null) : null;
                if (!is_array($inv) || empty($inv['id'])) { continue; }
                $count++;
                $type = (string) ($inv['type'] ?? '');
                if (!str_starts_with($type, 'receipt') && !in_array($type, self::SALE_INVOICE_TYPES, true)) { continue; }
                $detail = is_array($inv['contractor_detail'] ?? null) ? $inv['contractor_detail'] : [];
                $contractor = is_array($inv['contractor'] ?? null) ? $inv['contractor'] : [];
                $receipts[] = [
                    'id'     => (string) $inv['id'],
                    'number' => (string) ($inv['fullnumber'] ?? $inv['number'] ?? ''),
                    'type'   => $type,
                    'date'   => (string) ($inv['date'] ?? ''),
                    'total'  => (float) ($inv['total'] ?? $inv['brutto'] ?? 0),
                    'buyer'  => trim((string) ($detail['name'] ?? $contractor['name'] ?? '')),
                    'nip'    => trim((string) ($detail['nip'] ?? $contractor['nip'] ?? '')),
                    'text'   => trim(implode(' ', array_filter([
                        (string) ($inv['description'] ?? ''), (string) ($inv['id_external'] ?? ''),
                        (string) ($inv['fullnumber'] ?? ''), (string) ($inv['header'] ?? ''), (string) ($inv['footer'] ?? ''),
                    ]))),
                ];
            }
            if ($count < 100) {
                return ['ok' => true, 'message' => '', 'receipts' => $receipts, 'truncated' => false];
            }
        }
        return ['ok' => true, 'message' => '', 'receipts' => $receipts, 'truncated' => true];
    }

    /**
     * Faktura korygująca do faktury $parentId. invoices/add z type=correction i parent_id;
     * każda pozycja oryginału wraca z parent_id i ilością PO korekcie (0 = pozycja w całości zwrócona).
     * Status 0 w wyniku = brak odpowiedzi, wynik nieznany.
     * @param array<int,array<string,string>> $positions
     * @return array{ok:bool,status:int,message:string,document_id:string}
     */
    public function issueCorrection(string $parentId, string $contractorId, array $positions, string $reason, string $currency = 'PLN'): array
    {
        if (!ctype_digit($parentId) || $this->companyId() === '') {
            return ['ok' => false, 'status' => -1, 'message' => 'Brak poprawnego ID faktury lub firmy w wFirma.', 'document_id' => ''];
        }
        $contents = [];
        foreach (array_values($positions) as $i => $p) {
            $contents[(string) $i] = ['invoicecontent' => $p];
        }
        $invoice = [
            'type'            => 'correction',
            'parent_id'       => $parentId,
            'price_type'      => 'brutto',
            'currency'        => $currency,
            'description'     => mb_substr($reason, 0, 250),
            'invoicecontents' => (object) $contents,
        ];
        if ($contractorId !== '') {
            $invoice['contractor'] = ['id' => $contractorId];
        }
        $res = $this->post('invoices/add', ['invoices' => (object) ['0' => ['invoice' => $invoice]]]);
        $json = $res->json();
        if (!$res->isSuccess() || ($json['status']['code'] ?? '') !== 'OK') {
            return ['ok' => false, 'status' => $res->status, 'message' => $this->extractError($json) ?? ('wFirma: HTTP ' . $res->status), 'document_id' => ''];
        }
        $inv = $json['invoices'][0]['invoice'] ?? $json['invoices']['invoice'] ?? [];
        return ['ok' => !empty($inv['id']), 'status' => $res->status,
            'message' => !empty($inv['id']) ? 'Korekta wystawiona' . (!empty($inv['fullnumber']) ? ': ' . $inv['fullnumber'] : '') . '.' : 'wFirma nie zwróciła ID korekty.',
            'document_id' => (string) ($inv['id'] ?? '')];
    }

    /** Sukces wymaga potwierdzenia API; sam status HTTP nie wystarcza. */
    public function deleteDocument(string $documentId): void
    {
        if (!ctype_digit($documentId) || (int) $documentId <= 0 || $this->companyId() === '') {
            throw new \RuntimeException('Brak poprawnego ID dokumentu lub firmy w wFirma.');
        }
        $url = $this->url('invoices/delete/' . rawurlencode($documentId));
        $response = Http::request('DELETE', $url, $this->authHeaders());
        Logger::apiResponse('wfirma', 'DELETE', $url, $response->status);
        $json = $response->json();
        if (!$response->isSuccess() || ($json['status']['code'] ?? '') !== 'OK') {
            throw new \RuntimeException($this->extractError($json)
                ?? 'Brak potwierdzenia usunięcia przez wFirma (HTTP ' . $response->status . ').');
        }
    }

    /**
     * PDF dokumentu (oryginał, bez kopii i ulotki): POST invoices/download/{id}.
     * Odpowiedź to sam plik; JSON przychodzi tylko przy błędzie. Gdy wariant z outputFormat=pdf
     * nie odda pliku, próbujemy jeszcze z outputFormat=json (API przyjmuje oba zapisy).
     * @return array{ok:bool,message:string,pdf:string}
     */
    public function downloadPdf(string $documentId): array
    {
        if (!ctype_digit($documentId) || (int) $documentId <= 0 || $this->companyId() === '') {
            return ['ok' => false, 'message' => 'Brak poprawnego ID dokumentu lub firmy w wFirma.', 'pdf' => ''];
        }
        $payload = ['invoices' => ['parameters' => [
            ['parameter' => ['name' => 'page', 'value' => 'invoice']],
            ['parameter' => ['name' => 'address', 'value' => '0']],
            ['parameter' => ['name' => 'leaflet', 'value' => '0']],
            ['parameter' => ['name' => 'duplicate', 'value' => '0']],
        ]]];
        $headers = ['Accept' => 'application/pdf, application/json'] + $this->authHeaders();
        $error = null;
        foreach ([$this->downloadUrl($documentId), $this->url('invoices/download/' . rawurlencode($documentId))] as $url) {
            $res = Http::request('POST', $url, $headers, $payload, 30);
            Logger::apiResponse('wfirma', 'POST', $url, $res->status);
            if ($res->isSuccess() && str_starts_with(ltrim($res->body), '%PDF')) {
                return ['ok' => true, 'message' => '', 'pdf' => $res->body];
            }
            $error ??= $this->extractError($res->json()) ?? ('wFirma nie zwróciła pliku PDF (HTTP ' . $res->status . ').');
        }
        return ['ok' => false, 'message' => $error, 'pdf' => ''];
    }

    /** URL do pobrania PDF dokumentu (wymaga tych samych nagłówków autoryzacji). */
    public function downloadUrl(string $documentId): string
    {
        $q = 'inputFormat=json&outputFormat=pdf';
        $companyId = $this->companyId();
        if ($companyId !== '') {
            $q .= '&company_id=' . rawurlencode($companyId);
        }
        return $this->baseUrl() . '/invoices/download/' . rawurlencode($documentId) . '?' . $q;
    }

    /** Typ dokumentu PASE -> typ wFirma. */
    private function mapType(string $type, bool $vatPayer = true): string
    {
        if (!$vatPayer) {
            return match ($type) {
                'proforma' => 'proforma_bill',
                'receipt' => 'receipt_bill',
                'receipt_fiscal' => 'receipt_fiscal_bill',
                default => 'bill',
            };
        }
        return match ($type) {
            'proforma'       => 'proforma',
            'receipt'        => 'receipt_normal',
            'receipt_fiscal' => 'receipt_fiscal_normal',
            default          => 'normal',
        };
    }

    /** Normalizacja pozycji do formatu wFirma (invoicecontent). */
    private function mapPosition(array $p): array
    {
        $out = [
            'name'       => (string) ($p['name'] ?? ''),
            'count'      => (string) ($p['count'] ?? $p['quantity'] ?? 1),
            'unit_count' => '1',
            'price'      => (string) ($p['price'] ?? 0),
        ];
        // VAT: liczba (np. 23) lub 'zw'/'np'. Domyślnie 23%.
        $out['vat'] = (string) ($p['vat'] ?? 23);
        if (!empty($p['unit'])) {
            $out['unit'] = (string) $p['unit'];
        }
        return $out;
    }

    /** Ponowne użycie wyłącznie kontrahenta o zgodnych danych, także bez NIP. */
    private function resolveContractor(array $data): string
    {
        if (empty($data['name'])) {
            throw new \RuntimeException('Brak nazwy kontrahenta w zamówieniu.');
        }
        for ($page = 1; ; $page++) {
            $response = $this->post('contractors/find', ['contractors' => ['parameters' => [
                'page' => $page, 'limit' => 100,
                'conditions' => (object) ['0' => ['condition' => [
                    'field' => 'Contractor.name', 'operator' => 'eq', 'value' => $data['name'],
                ]]],
            ]]]);
            $json = $response->json();
            if (!$response->isSuccess() || ($json['status']['code'] ?? '') !== 'OK') {
                throw new \RuntimeException('Odczyt kontrahenta w wFirma: ' . ($this->extractError($json) ?? 'HTTP ' . $response->status));
            }
            $rows = $json['contractors'] ?? [];
            if (isset($rows['contractor'])) {
                $rows = [['contractor' => $rows['contractor']]];
            }
            $count = 0;
            foreach ($rows as $row) {
                if (!isset($row['contractor'])) { continue; }
                $count++;
                $candidate = $row['contractor'];
                $matches = !empty($candidate['id']);
                foreach ($data as $key => $value) {
                    if (trim((string) ($candidate[$key] ?? '')) !== trim((string) $value)) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) { return (string) $candidate['id']; }
            }
            if ($count < 100) { break; }
        }
        $response = $this->post('contractors/add', [
            'contractors' => (object) ['0' => ['contractor' => $data]],
        ]);
        $json = $response->json();
        $id = $json['contractors'][0]['contractor']['id'] ?? $json['contractors']['contractor']['id'] ?? '';
        if (!$response->isSuccess() || ($json['status']['code'] ?? '') !== 'OK' || (string) $id === '') {
            throw new \RuntimeException('Zapis kontrahenta w wFirma: ' . ($this->extractError($json) ?? 'Brak ID kontrahenta w odpowiedzi.'));
        }
        return (string) $id;
    }

    /** Normalizacja danych nabywcy do formatu wFirma (contractor). */
    private function mapContractor(array $c): array
    {
        $out = [];
        // wFirma rozróżnia osobę fizyczną i firmę; jeśli jest NIP -> nazwa firmy.
        $name = trim((string) ($c['name'] ?? ''));
        if ($name !== '') {
            $out['name'] = $name;
        }
        foreach ([
            'nip'      => ['nip', 'tax_id'],
            'street'   => ['street', 'address_1'],
            'zip'      => ['zip', 'postcode', 'zipCode'],
            'city'     => ['city'],
            'email'    => ['email'],
            'phone'    => ['phone'],
            'country'  => ['country', 'countryCode'],
        ] as $target => $sources) {
            foreach ($sources as $src) {
                if (!empty($c[$src])) {
                    $out[$target] = (string) $c[$src];
                    break;
                }
            }
        }
        foreach (['contact_person', 'different_contact_address', 'contact_name', 'contact_street', 'contact_zip', 'contact_city', 'contact_country'] as $key) {
            if (isset($c[$key]) && (string) $c[$key] !== '') { $out[$key] = (string) $c[$key]; }
        }
        $out['tax_id_type'] = empty($out['nip']) ? 'none' : (string) ($c['tax_id_type'] ?? 'nip');
        return $out;
    }

    /** Wyciąga czytelny komunikat błędu z odpowiedzi wFirma. */
    private function extractError(array $json): ?string
    {
        // wFirma dokleja `errors` nie tylko do faktury, ale też głęboko do
        // kontrahenta i każdej pozycji. Zbieramy wszystkie pola `message`
        // rekurencyjnie, zamiast zwracać mało użyteczny sam status ERROR.
        $messages = [];
        $this->collectValidationMessages($json['invoices'] ?? $json, $messages);
        $messages = array_values(array_unique(array_filter($messages)));
        if ($messages !== []) {
            return implode('; ', $messages);
        }
        $code = $json['status']['code'] ?? null;
        if ($code !== null && $code !== 'OK') {
            return 'wFirma status: ' . (string) $code;
        }
        return null;
    }

    /** @param array<int,string> $messages */
    private function collectValidationMessages(mixed $node, array &$messages): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['message']) && is_scalar($node['message'])) {
            $message = trim((string) $node['message']);
            $field = isset($node['field']) && is_scalar($node['field'])
                ? trim((string) $node['field'])
                : '';
            if ($message !== '') {
                $messages[] = $field !== '' ? $field . ': ' . $message : $message;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectValidationMessages($value, $messages);
            }
        }
    }
}
