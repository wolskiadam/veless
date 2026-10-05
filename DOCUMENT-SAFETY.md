# Ochrona przed ponownym wystawieniem dokumentu

Przed wywołaniem wFirma `WfirmaDocumentService` zapisuje i zatwierdza trwałą
rezerwację `(order_id, document_type)` w `document_issue_operations`. Unikalny
klucz i blokada wiersza zamówienia serializują równoległe próby. Różne typy
dokumentu pozostają dozwolone zgodnie z dotychczasową funkcjonalnością.

Po sukcesie dokument, historia i stan `issued` są zapisane razem. Timeout,
błąd dostawcy, brak ID lub błąd lokalnego zapisu zostawia blokadę `uncertain`.
Śmierć procesu zostawia `pending`. Nie ma automatycznego zwalniania blokad po
upływie czasu ani ponawiania przez kolejkę. Wywołanie wewnątrz transakcji
nadrzędnej jest odrzucane przed API, aby rezerwacja nie zniknęła przy rollbacku.

## Obsługa niejednoznacznego wyniku

Administrator: **Zamówienia → Dokumenty do sprawdzenia**.
Sprawdź właściwe konto/integrację wFirma i dokumenty zamówienia:

- Dokument istnieje: podaj jego ID i potwierdź sprawdzenie. System zapisuje
  powiązanie bez ponownego wywołania wystawiania.
- Dokument nie istnieje, a żądanie zakończyło się błędem (`uncertain`, bez ID):
  po upewnieniu się, że dostawca zakończył obsługę, potwierdź brak i odblokuj.
  Samo odblokowanie nie wystawia dokumentu. Wynik jest zapisany w historii.
- `pending` bez dokumentu: panel nie pozwala zgadywać, czy przerwany proces
  lub zewnętrzne żądanie nadal działa. Potrzebna jest kontrola procesu/logów
  przez administratora technicznego. Nie kasuj blokady tylko dlatego, że jest stara.

To ręczna weryfikacja przez operatora, nie automatyczne sprawdzenie API.
Błędne potwierdzenie braku może doprowadzić do duplikatu. Stare formularze nie
mogą odblokować nowej próby (token próby musi się zgadzać).

Świadome usunięcie powiązania już wystawionego paragonu zwalnia rezerwację
wyłącznie dla zakończonej operacji i zgodnego ID. Dotychczasowa opcja „Usuń
powiązanie” nadal **nie usuwa dokumentu w wFirma**; użycie jej i wystawienie
ponownie bez usunięcia starego dokumentu pozostaje świadomym działaniem operatora.

## Zakres i wdrożenie

Ochrona obejmuje ręczne i automatyczne wywołania wspólnego
`WfirmaDocumentService`. Nie obejmuje kodu omijającego tę usługę, osobnego CRM
korzystającego z innej bazy ani starszej ścieżki Allegro `WfirmaService`.
Nie zastępuje idempotencji po stronie API ani uzgadniania dokumentów po
odtworzeniu starego backupu. Nie gwarantujemy „exactly once” zewnętrznego API.

Wgraj komplet plików i zatrzymaj stare procesy workera podczas aktualizacji,
aby stare wydanie nie omijało zabezpieczenia. Migracja tworzy nową tabelę bez
usuwania danych. Istniejące powiązania nadal blokują wystawianie. Rezerwacje
pozostają również po usunięciu zamówienia i wchodzą w skład kopii bazy.

Testy: `tests/sales_workflow.php`, `tests/document_concurrency.php`,
`tests/two_factor_http.php`; etap MySQL w `tests/sales_mysql.php` i CI.
Lokalnie potwierdzono działanie na SQLite i atrapie API, nie wykonywano operacji
na prawdziwych dokumentach ani migracji produkcyjnej.
