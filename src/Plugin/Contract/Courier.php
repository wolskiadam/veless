<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Zdolność: KURIER / WYSYŁKA. Wtyczka potrafi nadawać przesyłki, pobierać etykiety,
 * anulować, wyceniać i podawać formy płatności. Struktury wejścia/wyjścia trzymamy
 * ogólne (tablice), bo różni kurierzy mają różne pola - normalizację robi wtyczka.
 */
interface Courier
{
    /**
     * Lista dostępnych usług/kurierów dla danej paczki (surowa odpowiedź API).
     * @param array<string,mixed> $parcel waga/wymiary
     * @return array<string,mixed>
     */
    public function listServices(array $parcel): array;

    /** Dostępne formy płatności za nadanie (surowa odpowiedź API). @return array<string,mixed> */
    public function listPaymentOptions(): array;

    /** Wycena (opcjonalna). @return array{ok:bool,price:?float,message:string,raw?:mixed} */
    public function quote(array $params): array;

    /**
     * Nadaje przesyłkę.
     * @return array{ok:bool,message:string,waybill_no:?string,external_id:?string,price:?float,label_link:?string,raw?:mixed}
     */
    public function createShipment(array $shipment): array;

    /**
     * Pobiera etykietę (zdekodowany plik gotowy do wysłania w przeglądarce).
     * @return array{ok:bool,content:?string,filename:?string,mime:?string,message:string}
     */
    public function getLabel(string $externalId, string $format = 'A4'): array;

    /** Anuluje przesyłkę. @return array{ok:bool,message:string} */
    public function cancelShipment(string $externalId): array;
}
