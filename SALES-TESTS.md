# Historia i testy sprzedaży

## Historia zmian

Menu **Zamówienia → Historia zmian** otwiera historię wszystkich zamówień.
W szczegółach zamówienia przycisk **Historia zmian** otwiera wpisy dla tego
zamówienia. Link **Historia wszystkich zamówień** pokazuje również wpisy po
trwałym usunięciu zamówienia. Dostęp: administrator i edytor. Strona jest tylko
do odczytu, nie jest cache'owana, pokazuje po 50 wpisów (czas UTC).

Rejestrowane operacje: status (także zbiorczo i przez reguły automatyzacji),
lokalne pozycje i ich przywrócenie, zmiana pól kontaktowych, archiwum/kosz,
przywrócenie i trwałe usuwanie, lokalne zapisanie dokumentu, odpięcie paragonu
oraz usunięcie paragonu po potwierdzeniu usunięcia przez wFirma.
Identyfikator i nazwa użytkownika oznaczają inicjatora operacji; automatyzacja
uruchomiona w jego żądaniu zachowuje tę tożsamość. Operacje CLI bez użytkownika
oznaczone są „System / automatyzacja”. Historia nie odtwarza zmian sprzed wdrożenia.

Lokalny zapis i historia są transakcyjne. Brak możliwości zapisania historii
blokuje lokalną zmianę. Transakcja NIE cofa wykonanej operacji w zewnętrznym API.
Jeśli API wystawi dokument, ale lokalny zapis zawiedzie, komunikat zawiera ID
dokumentu i ostrzeżenie, żeby nie ponawiać wystawiania bez weryfikacji.

Nie zapisujemy haseł, sekretów, pełnych payloadów, treści wiadomości ani wartości
adresu e-mail/telefonu. Dla kontaktu zapisywane są nazwy zmienionych pól; dla
pozycji identyfikatory/SKU, ilości i ceny. `local_items: null` oznacza korzystanie
z oryginalnych pozycji sklepu. Wpisy nie mają opcji edycji/usuwania w panelu,
ale nie są kryptograficznie chronione przed administratorem bazy danych.
Nie obejmują jeszcze zmian użytkowników/konfiguracji, importów, wiadomości,
stanów magazynowych, wysyłek ani wyników synchronizacji z kanałem.

Tabela `audit_events` powstaje w migracji aplikacji; referencja w `sql/schema.sql`.
Wdrożenie wymaga uprawnień CREATE TABLE/INDEX i tabel transakcyjnych InnoDB.
Nie wykonywano migracji na produkcji. Przed wdrożeniem wykonaj backup bazy.

## Testy lokalne (bez produkcji)

```sh
php tests/sales_workflow.php
php tests/security.php
php tests/two_factor.php
php tests/two_factor_http.php
node tests/two_factor_ui.js
php tests/operational_health.php
```

Test sprzedaży używa SQLite w pamięci i atrapę interfejsu wystawiania dokumentów.
Sprawdza status, pozycje, kontakt, archiwum/kosz, wycofanie transakcji, odrzucenie
zapisu historii, dane kontrahenta Woo/Allegro, kwoty brutto/dostawę/opłaty,
płatność, ponowne wystawienie, błędy API i odpinanie dokumentów.
Test HTTP używa kopii kontrolerów w katalogu tymczasowym, testowej bazy i
serwera localhost; sprawdza także historię, XSS, paginację i role użytkowników.

## MySQL — osobny obowiązkowy etap przed wdrożeniem

Lokalnie nie ma serwera MySQL, więc **ten etap nie został wykonany**.
`tests/sales_mysql.php` bez jawnego włączenia kończy się kodem 2, nie udaje sukcesu.
Użyj wyłącznie odizolowanego lokalnego serwera testowego oraz konta mogącego
tworzyć/usuwać testowe bazy, nie konta produkcyjnego:

```sh
CRM_TEST_MYSQL=1 CRM_TEST_MYSQL_USER=tester php tests/sales_mysql.php
```

Hasło przekazuje się przez `CRM_TEST_MYSQL_PASSWORD` w środowisku procesu;
opcjonalny port przez `CRM_TEST_MYSQL_PORT`. Skrypt łączy się tylko z 127.0.0.1,
tworzy losową bazę `crm_test_<losowy identyfikator>` i usuwa wyłącznie tę bazę
w bloku finally. Nie czyta `.env`. Sprawdza rzeczywiste migracje, import Woo,
ponowny import, ochronę lokalnych zmian, kolejkę, deduplikację i retry oraz historię.
Workflow `.github/workflows/sales-tests.yml` uruchamia te testy z osobnym MySQL 8.
Workflow przygotowano, ale nie uruchamiano go z tego zadania.

## Granice i dalsze testy end-to-end

Nie przetestowano rzeczywistych usług Woo/Allegro/wFirma, nadawania/anulowania
przesyłek, sprzętu drukującego ani zwrotów/korekt. Dodano trwałe rezerwacje
dokumentów i lokalny test dwóch procesów (`tests/document_concurrency.php`);
szczegóły oraz ręczne uzgadnianie wyniku opisuje `DOCUMENT-SAFETY.md`.
Kopie i testy odtwarzania opisuje `BACKUPS.md`.
Testy pełnego importu Allegro (magazyn i starsza ścieżka dokumentów) również
pozostają do uzupełnienia. Do pełnego potwierdzenia E2E potrzebne jest środowisko
testowe integracji, testowe zamówienia oraz uzgodnione operacje zewnętrzne.
