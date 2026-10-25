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

**Rejestr faktur KSeF** (Zamówienia → 🧾 Rejestr faktur KSeF): jedna pozycja menu, faktury sprzedażowe i zakupowe
to zakładki na stronie listy.

**Faktury sprzedażowe** (domyślnie włączone): zakładka **Faktury sprzedażowe KSeF** działa jak lista zakupowa
(opis niżej), ale pokazuje faktury, w których firma jest sprzedawcą (`subjectType = Subject1`), także wystawione poza tym
systemem (np. w wFirma albo w Aplikacji Podatnika). Kontrahentem w tabeli jest nabywca; osobna tabela `ext_ksef_sales`
i osobny punkt „Pobierz nowe”. Wyłączysz ją w ustawieniach wtyczki.

**Automatyczne pobieranie**: ustawienie „pobieraj nowe z KSeF automatycznie” (wyłączone, co godzinę, co 3/6/12 godzin,
raz dziennie; domyślnie raz dziennie) robi w tle to samo, co przycisk „Pobierz nowe z KSeF”, dla obu włączonych list.
Działa z crona (`cli/worker.php`, hak `cron.tick`), gdy jest NIP i token. Nad listą widać, kiedy pobrało ostatnio, ile
było nowych faktur i ewentualny błąd. Gdy limit zapytań KSeF przerwie pobieranie, wtyczka dokończy je za 2 minuty.
Wstrzymasz je w System → Obciążenie serwera („Wtyczki rozszerzające”).

**Faktury zakupowe** (domyślnie wyłączone):

- **Zamówienia → Faktury zakupowe KSeF**: faktury, w których firma jest nabywcą. Przycisk „Pobierz nowe z KSeF” dociąga
  faktury od ostatniego pobrania (za pierwszym razem z okresu z ustawień: 30 dni do roku), a „Pobierz z okresu” - faktury
  z datą wystawienia między dwiema wybranymi datami (np. starsze miesiące). Lista pokazuje faktury
  miesiącami (domyślnie najnowszy miesiąc z fakturami, przyciski poprzedni/następny, wybór miesiąca albo „Wszystkie
  miesiące”), po 50 na stronę z paginacją. Sumy netto, VAT i brutto liczone są dla całego miesiąca, nie tylko strony.
  Wyszukiwanie po numerze, sprzedawcy i NIP (bez wybranego miesiąca szuka we wszystkich).
- **Podgląd faktury** w układzie wizualizacji KSeF: numer faktury i numer KSeF, sprzedawca i nabywca, szczegóły, pozycje,
  kwota należności, podsumowanie stawek, płatność, adnotacje (np. MPP) i kod QR z linkiem weryfikacyjnym (KOD I).
  Przyciski: **Pobierz PDF** / **Otwórz PDF** (ten sam układ, generowany na serwerze bez dodatkowych bibliotek:
  `Pdf.php` ze standardowymi czcionkami PDF i polskimi znakami, `QrCode.php` - port qrcodegen), **Pobierz XML**
  i **Faktura w KSeF**. PDF jest tylko wizualizacją; dokumentem jest XML w KSeF.

Na stronie **Zamówienia → Faktury KSeF** jest też stan połączenia i przycisk „Sprawdź połączenie” (bez wystawiania
e-faktur strona nie ma pozycji w menu; link do niej jest na stronie faktur zakupowych).

## Uruchomienie

1. **Konfiguracja → Wtyczki → KSeF → ⚙ Ustawienia**: środowisko (zostaw **Testowe**), co wtyczka ma robić, NIP firmy, token KSeF.
   Do wystawiania także nazwa i adres firmy oraz wzór numeru (same faktury zakupowe potrzebują tylko NIP i tokenu).
2. **Token KSeF**: wygeneruj go w Aplikacji Podatnika KSeF wybranego środowiska (albo przez API `POST /tokens`)
   z uprawnieniem do wystawiania faktur (`InvoiceWrite`) i - dla faktur zakupowych - do ich przeglądania (`InvoiceRead`).
   Wklej go w ustawieniach wtyczki w polu swojego środowiska, zapisz, a potem na stronie **Zamówienia → Faktury KSeF**
   kliknij **Sprawdź połączenie**.
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

**Bezpieczeństwo**: token KSeF to pole `secret` w ustawieniach wtyczki (`token_test`, `token_demo`, `token_prod`), więc rdzeń
zapisuje je zaszyfrowane (SecretStore), nigdy w kodzie. Ustawienia zmienia tylko administrator; panel nie pokazuje zapisanego
tokena. Token wpisany w wersji 1.0 na stronie Faktury KSeF (klucz `EXT_KSEF_<ŚRODOWISKO>_TOKEN`) nadal działa, dopóki pole
w ustawieniach jest puste.

## Czego jeszcze nie ma

- faktury korygujące, zaliczkowe, w walucie obcej (tylko PLN), z mechanizmem podzielonej płatności (MPP),
- tryb offline (awaria KSeF) i wysyłka wsadowa,
- księgowanie faktur zakupowych (oznaczanie jako zapłacone, przypisanie do kosztów),
- własna wizualizacja PDF z kodem QR (klient dostaje link weryfikacyjny KSeF, który pokazuje fakturę).

## Testy

`php tests/ksef.php` (szyfrowanie, XML ze schematem MF z `tests/fixtures/ksef`, mapowanie zamówień, numeracja, cała wysyłka
i pobieranie faktur zakupowych na atrapie KSeF) i `php tests/ksef_http.php` (prawdziwe strony panelu).
