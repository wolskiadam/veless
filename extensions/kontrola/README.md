# Kontrola stanu

Wewnętrzny audyt CRM: sam sprawdza, czy wszystko działa, i pokazuje wynik w **System → Kontrola stanu**
(zielone, żółte, czerwone światło). Przy każdym problemie jest lista zamówień albo produktów, których dotyczy,
link do właściwej strony i, tam gdzie się da, przycisk naprawy.

## Co sprawdza

| Grupa | Sprawdzenie | Kiedy żółte / czerwone |
|---|---|---|
| Połączenia | Allegro (token + `GET /me`) | czerwone: brak połączenia, wygasły token, błąd API |
| | każde aktywne konto integracji (ten sam test co „Testuj połączenie”) | czerwone: test nie przechodzi |
| | KSeF (z rozszerzenia `ksef`, hak `health.checks`) | żółte: brak NIP/tokenu, czerwone: błąd pobierania lub połączenia |
| Zadania w tle | cron (`WORKER_LAST_RUN_AT`) | żółte > 3 min, czerwone > 10 min |
| | zadania cykliczne (zamówienia, śledzenie, stany, ceny, produkty, tokeny) | żółte: spóźnione ponad 3 interwały, czerwone: zamówienia/śledzenie > 6 interwałów (min. 2 h), tokeny > 30 min |
| | wstrzymane procesy (Obciążenie serwera) | żółte, gdy coś jest wstrzymane |
| | zadania z błędem z 7 dni | żółte, czerwone od 20 · naprawa: „Ponów zadania z błędem” |
| | zadania utknięte / czekające > 30 min | czerwone: utknięte, > 200 czekających albo > 3 h · naprawa: „Odblokuj” |
| Zgodność danych | stany CRM a Allegro (aktywne oferty, SKU z sygnatury albo z Powiązań) | żółte: różnica widziana w dwóch przebiegach · naprawa: „Wyślij stany z CRM” |
| | stany CRM a każdy sklep WooCommerce (po 300 produktów na przebieg, po kolei) | jak wyżej |
| | zamówienia z pozycją spoza magazynu CRM | żółte |
| | wysłane bez numeru przesyłki (bez odbioru osobistego i wirtualnych) | żółte |
| | opłacone bez paragonu i faktury po N dniach (gdy jest wFirma albo KSeF) | żółte |
| Przesyłki | paczki bez żadnego statusu od przewoźnika po N dniach | żółte, czerwone od 20 · naprawa: „Sprawdź te paczki teraz” |

Zamówienia: aktywne z ostatnich 30 dni (ustawienie), bez anulowanych, zwróconych i scalonych.
Różnica stanu musi się utrzymać przez dwa przebiegi (albo 20 minut), bo zamówienie z Allegro zdejmuje stan tam od razu,
a w CRM dopiero po imporcie. Produkty, których stan czeka w kolejce na wysłanie, są pomijane.

## Kiedy

- W tle co godzinę (ustawienie „Sprawdzaj w tle”), z haka `cron.tick`, porcjami po ~25 s na minutę.
  Cron, kolejka i zadania cykliczne są też liczone na żywo przy każdym otwarciu strony.
- Przycisk „Sprawdź teraz” (edytor, administrator) robi wszystko od razu.

## Mail

Po przebiegu, gdy zmienił się zestaw czerwonych sprawdzeń: mail z listą problemów i linkiem do strony, a gdy wszystko
ustąpi - mail „wszystko znów działa”. Ten sam zestaw przypomina się najwyżej raz na dobę. Adres: ustawienie wtyczki,
a puste = adres alarmów z Konfiguracja → E-mail. Link w mailu wymaga `APP_BASE_URL`.

## Sprawdzenia z innych rozszerzeń

```php
Hooks::addFilter('health.checks', function (array $checks): array {
    $checks['conn.moja_usluga'] = ['group' => 'conn', 'title' => 'Moja usługa', 'network' => true,
        'run' => fn(): array => ['state' => 'ok', 'summary' => 'Działa.']];
    return $checks;
});
```

`group`: `conn`, `tasks`, `data`, `ship`. Wynik: `state` (`ok|warn|bad|skip`), `summary`, opcjonalnie `items`
(`[['text' => ..., 'href' => ..., 'note' => ...]]`), `count`, `link` (`['label' => ..., 'href' => ...]`).
`network => true` = sprawdzenie łączy się z zewnątrz (nie powtarza się po naprawie). Wyjątek w `run` to żółte światło.

## Testy

`php tests/kontrola.php` (sprawdzenia, przebieg, mail, naprawy) i `php tests/kontrola_http.php` (strona w panelu,
role, udawany sklep WooCommerce; z `SHOTS=katalog NODE_PATH=...` zrzuty przez `tests/kontrola_ui.js`).
