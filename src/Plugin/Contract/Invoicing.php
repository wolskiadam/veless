<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Zdolność: FAKTURY / KSIĘGOWOŚĆ. Wtyczka potrafi wystawiać dokumenty sprzedaży.
 */
interface Invoicing
{
    /**
     * Wystawia dokument (faktura/paragon/proforma) dla danego kontrahenta i pozycji.
     * @param array<string,mixed>            $contractor dane nabywcy
     * @param array<int,array<string,mixed>> $positions  pozycje (nazwa, ilość, cena, vat)
     * @param string                         $type       normal|proforma|receipt|receipt_fiscal
     * @param array<string,mixed>            $options    opcje dokumentu (np. waluta, identyfikator zewnętrzny)
     * @return array{ok:bool,message:string,document_id?:string,pdf_url?:string}
     */
    public function issueDocument(array $contractor, array $positions, string $type = 'normal', array $options = []): array;
}
