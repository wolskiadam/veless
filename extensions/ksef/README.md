# KSeF - e-faktury (rozszerzenie)

Łączy CRM wprost z **Krajowym Systemem e-Faktur** przez **KSeF API 2.0**, bez programu księgowego po drodze:
wystawia faktury VAT w strukturze **FA(3)** z zamówień i pokazuje faktury zakupowe, które firma dostaje w KSeF. Dokumentacja MF:
https://ksef.podatki.gov.pl/ksef-na-okres-obligatoryjny/wsparcie-dla-integratorow/ i https://github.com/CIRFMF/ksef-docs.

## Co robi

W **Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia** wybierasz, co wtyczka ma robić (można jedno, drugie albo oba):

**Wystawianie e-faktur z zamówień** (domyślnie włączone):

- **Karta zamówienia → sekcja „e-Faktura KSeF”**: podgląd faktury (nabywca, pozycje, stawki, sumy), wysyłka do KSeF,
  status, numer KSeF, pobranie XML i UPO, link weryfikacyjny KSeF dla klienta (KOD I), ponowna wysyłka po odrzuceniu.
- **Lista zamówień**: ikona stanu e-faktury przy statusie (przyjęta / w KSeF / odrzucona).
- **Zamówienia → Faktury KSeF**: lista wystawionych e-faktur i sprawdzenie oczekujących.
- **Automatyzacje**: akcja „Wyślij e-fakturę do KSeF” (np. po opłaceniu zamówienia). Zamówienie z e-fakturą jest pomijane.

**Faktury zakupowe** (domyślnie wyłączone):

- **Zamówienia → Faktury zakupowe KSeF**: faktury, w których firma jest nabywcą. Przycisk „Pobierz nowe z KSeF” dociąga
  faktury od ostatniego pobrania (za pierwszym razem z okresu z ustawień: 30 dni do roku). Wyszukiwanie po numerze,
  sprzedawcy i NIP, filtr miesiąca, suma brutto.
- **Podgląd faktury**: sprzedawca, nabywca, pozycje, stawki VAT, kwota do zapłaty, termin i rachunek do przelewu,
  pobranie XML i link do faktury w KSeF.

Zawsze dostępne: **Zamówienia → Faktury KSeF** z połączeniem (token KSeF, test połączenia).

## Uruchomienie

1. **Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia**: środowisko (zostaw **Testowe**), co wtyczka ma robić, NIP firmy.
   Do wystawiania także nazwa i adres firmy oraz wzór numeru (same faktury zakupowe potrzebują tylko NIP i tokenu).
2. **Token KSeF**: wygeneruj go w Aplikacji Podatnika KSeF wybranego środowiska (albo przez API `POST /tokens`)
   z uprawnieniem do wystawiania faktur (`InvoiceWrite`) i - dla faktur zakupowych - do ich przeglądania (`InvoiceRead`).
   Wklej go na stronie **Zamówienia → Faktury KSeF** (tylko administrator) i kliknij **Sprawdź połączenie**.
3. Wyślij kilka faktur testowych z kart zamówień. Na środowisku testowym używaj danych testowych - MF zastrzega,
   że dane w TE nie są odizolowane od innych integratorów.
4. Gdy wszystko się zgadza: zmień środowisko na **Produkcyjne** i wpisz token produkcyjny (każde środowisko ma swój token
   i swoją numerację).

> Faktury z produkcyjnego KSeF mają moc prawną: nie da się ich zmienić ani usunąć, tylko skorygować.

## Jak liczona jest faktura

- Pozycje, dostawa i opłaty jak przy fakturach wFirma: pozycje poprawione w CRM mają pierwszeństwo, stawka VAT wynika
  z netto i VAT pozycji ze sklepu (23/8/5/0%), a gdy sklep jej nie podał (np. Allegro) - ze stawki domyślnej w ustawieniach.
- Ceny brutto (P_9B, P_11A); VAT w każdej stawce liczony od sumy brutto (art. 106e ust. 7 i 8 ustawy o VAT).
- Nabywca z danych do faktury (poprawka w CRM albo sklep): polski NIP, numer VAT UE (kraj UE) albo osoba bez identyfikatora.
- Płatność: opłacone zamówienie dostaje „Zapłacono” z datą; nieopłacone - numer rachunku z ustawień. Numer zamówienia w „Zamówienia”.
- Zamówienia scalone: e-fakturę wystawia zamówienie główne, obejmuje pozycje dołączonych.

**Numeracja**: wzór z `{nr}` (np. `KSEF/{nr}/{mm}/{rrrr}`), licznik od 1 co miesiąc (z `{mm}`) albo co rok (z samym `{rrrr}`).
KSeF odrzuca drugi raz ten sam numer od tego samego sprzedawcy, więc wzór nie może pokrywać się z numeracją
faktur wystawianych gdzie indziej (np. w wFirma).

## Statusy

| Status | Znaczenie |
|---|---|
| KSeF sprawdza fakturę | Wysłana, czeka na wynik weryfikacji. „Sprawdź status w KSeF” albo „Sprawdź oczekujące” na liście. |
| przyjęta w KSeF | Ma numer KSeF i UPO. |
| odrzucona przez KSeF | Powód jest w karcie. Popraw dane i wyślij ponownie - z tym samym numerem faktury. |
| wysyłka nieudana | Błąd połączenia albo logowania. Ponowienie używa tego samego numeru (KSeF nie przyjmie duplikatu). |

## Jak to działa technicznie

`KsefApi` (API 2.0, `https://api-test.ksef.mf.gov.pl/v2`, `api-demo`, `api`):
logowanie tokenem (`auth/challenge` → `auth/ksef-token` z tokenem zaszyfrowanym RSA-OAEP SHA-256 kluczem MF → `auth/{ref}` →
`auth/token/redeem`), sesja interaktywna FA(3) (`sessions/online`, klucz AES-256 zaszyfrowany kluczem MF, `publicKeyId`
z obsługą rotacji kluczy), wysyłka faktury zaszyfrowanej AES-256-CBC ze skrótami SHA-256, zamknięcie sesji, status
faktury i UPO. Faktury zakupowe: `POST invoices/query/metadata` z `subjectType = Subject2` (firma jako nabywca), w oknach do 90 dni
po dacie trwałego zapisu w KSeF z mechanizmem HWM (`restrictToPermanentStorageHwmDate`), ze stronicowaniem i wznowieniem
po obciętym wyniku; XML faktury (`GET invoices/ksef/{numer KSeF}`) pobierany przy pierwszym podglądzie i zapamiętany.
`Fa3Xml` buduje XML zgodny ze schematem `schemat_FA(3)_v1-0E.xsd`, `OrderInvoice` przekłada zamówienie
na fakturę, `Invoices` trzyma tabele `ext_ksef_invoices` i `ext_ksef_counters`, `Purchases` - `ext_ksef_purchases`
i `ext_ksef_sync`, `InvoiceView` pokazuje fakturę z XML (FA(2) i FA(3)).

**Bezpieczeństwo**: token KSeF jest zapisywany w ustawieniach pod kluczem `EXT_KSEF_<ŚRODOWISKO>_TOKEN`, który rdzeń
szyfruje (SecretStore), nigdy w kodzie ani w ustawieniach z formularza wtyczki. Zmienia go tylko administrator; panel go nie pokazuje.

## Czego jeszcze nie ma

- faktury korygujące, zaliczkowe, w walucie obcej (tylko PLN), z mechanizmem podzielonej płatności (MPP),
- tryb offline (awaria KSeF) i wysyłka wsadowa,
- automatyczne pobieranie faktur zakupowych w tle (na razie przyciskiem; rdzeń nie ma jeszcze harmonogramu dla rozszerzeń),
- księgowanie faktur zakupowych (oznaczanie jako zapłacone, przypisanie do kosztów),
- własna wizualizacja PDF z kodem QR (klient dostaje link weryfikacyjny KSeF, który pokazuje fakturę).

## Testy

`php tests/ksef.php` (szyfrowanie, XML ze schematem MF z `tests/fixtures/ksef`, mapowanie zamówień, numeracja, cała wysyłka
i pobieranie faktur zakupowych na atrapie KSeF) i `php tests/ksef_http.php` (prawdziwe strony panelu).
