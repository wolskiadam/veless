# Księgowość (rozszerzenie)

Księgi rachunkowe spółki prowadzone w CRM, docelowo zamiast wFirma: plan kont, dokumenty z dekretacją Wn/Ma, dziennik,
obroty i salda. Kolejne etapy dołożą faktury do KSeF, paragony, deklaracje (JPK_V7, CIT) i kadry z płacami.

Moduł jest **zintegrowany z CRM, ale jego dane są odrębne**: wspólne jest tylko logowanie, menu i wygląd panelu. Dzięki
temu da się go kiedyś wynieść na osobny serwer bez przepisywania.

## Zasady rozdziału od CRM

| Co | Jak |
|---|---|
| Dane | Tylko tabele `ext_ksg_*`. Wersja schematu w `ext_ksg_meta`, dane firmy i ustawienia też tam - nic w `settings` CRM. |
| Osobna baza | Opcjonalnie od razu: w `.env` wpisz `EXT_KSIEGOWOSC_DB_DSN` (np. `mysql:host=localhost;dbname=ksiegowosc;charset=utf8mb4`), `EXT_KSIEGOWOSC_DB_USER` i `EXT_KSIEGOWOSC_DB_PASS`. Połączenie daje rdzeń (`Pase\Plugin\ExtensionDatabase`), tabele zakładają się same. Przeniesienie istniejących danych = skopiowanie tabel `ext_ksg_*`. |
| Dane z CRM | Tylko przez zdarzenia: hak `automation.event` zapisuje kopię zamówienia (nowe zamówienie, zmiana statusu) do skrzynki `ext_ksg_inbox`, gdy w zakładce Firma jest włączone zbieranie. Moduł nie czyta `woo_orders` ani innych tabel CRM. |
| Dane dla CRM | Tylko przez `Api` (`periodOpen`, `balance`, `orderEvents`; kolejne etapy dopiszą np. fakturę do zamówienia). Zwraca zwykłe tablice, więc po wyniesieniu zamieni się na wywołania HTTP. CRM nie czyta tabel `ext_ksg_*`. |
| Użytkownicy | Lista kont do nadania dostępu z rdzenia (`Pase\Support\AdminDirectory`, bez haseł i kluczy). |

## Uprawnienia

Księgowość ma **własne poziomy dostępu**, niezależne od ról CRM (będą tu płace i dane pracowników). Ustawia je
administrator w **Księgowość → Dostęp**:

| Poziom | Może |
|---|---|
| Brak dostępu (domyślnie) | nic - nie widzi nawet grupy w menu |
| Podgląd ksiąg | pulpit, dokumenty, dziennik, plan kont, obroty i salda, zapisy na kontach, eksport CSV |
| Księgowanie dokumentów | do tego: dodawanie, poprawianie i usuwanie szkiców, księgowanie, storno |
| Zarządzanie | do tego: plan kont, lata obrotowe i zamykanie miesięcy, dane firmy, dostęp, historia zmian, skrzynka zdarzeń |

Administrator CRM ma zawsze pełny dostęp. Zapisywanie wymaga też roli CRM z edycją (rola „podgląd” w CRM zostaje przy podglądzie).

## Co jest w etapie 1

- **Lata obrotowe**: rok (do 24 miesięcy przy pierwszym roku spółki), bez nakładania się okresów. Plan kont przy
  zakładaniu: wzorcowy dla spółki z o.o. (`ChartTemplate`, wariant porównawczy), pusty albo kopia z poprzedniego roku.
  **Zamknięcie miesięcy** („zamknięte do”) blokuje księgowanie z wcześniejszą datą, np. po wysłaniu JPK.
- **Plan kont**: konta syntetyczne (3 cyfry) i analityczne po kresce (`130-01`, `202-ALLEGRO`). Zapisy tylko na konta
  bez kont podrzędnych; konto z analityką pokazuje sumę. Konta z zapisami nie da się usunąć ani zmienić mu numeru
  (można je wyłączyć), a pod kontem z zapisami nie zakłada się analityki.
- **Dokumenty** (PK, FS, FZ, WB, RK, LP, BO): numer własny albo nadany sam (`PK/3/11/2026`), daty księgowania,
  dokumentu i operacji, kontrahent, dekretacja z podpowiedzią kont i sumą Wn/Ma na żywo. Szkic może być niezbilansowany;
  lista problemów pokazuje, co poprawić przed księgowaniem.
- **Księgowanie**: Wn = Ma, konta aktywne i bez analityki, rok otwarty, okres niezamknięty. Dokument dostaje kolejny
  numer pozycji w dzienniku roku i od tej chwili nie da się go zmienić ani usunąć.
- **Storno** (czerwone): nowy dokument ST z tymi samymi kontami i kwotami z minusem, od razu zaksięgowany; w innym roku
  konta szukane po numerze. Oryginał zostaje, oznaczony jako wystornowany.
- **Zestawienia**: obroty i salda (okres, narastająco, saldo; z CSV dla Excela), zapisy na koncie z saldem początkowym
  i bieżącym, dziennik w kolejności pozycji. Pulpit: środki pieniężne, należności, zobowiązania, wynik narastająco
  i kontrola Wn = Ma.
- **Historia zmian**: każda operacja (kto, kiedy, co) w `ext_ksg_log`.

Kwoty są trzymane w groszach (`BIGINT`), bez liczb zmiennoprzecinkowych.

## Co jest w etapie 2a

- **Kontrahenci** (`ext_ksg_contractors`, osobno od klientów CRM): nazwa, NIP (polski z sumą kontrolną, zagraniczny
  z prefiksem kraju), adres, rachunek, rodzaj (odbiorca / dostawca / oba). Każdy dostaje kod `0001`, `0002`… i własne
  konta rozrachunkowe `200-kod` (odbiorca) i `202-kod` (dostawca), zakładane w otwartych latach i w każdym nowym roku
  z planem wzorcowym. Konto nie powstaje, jeśli 200/202 ma już zapisy bez analityki. Zmiana nazwy przechodzi na konta.
  Duplikat NIP-u jest odrzucany; kontrahenta można wyłączyć. Karta pokazuje salda rozrachunków i dokumenty.
- **VAT w dokumencie** (`ext_ksg_vat`): pozycje z rejestrem (sprzedaż / zakup), okresem RRRR-MM, stawką (23, 8, 5, 0,
  zw, np, oo), netto i VAT (liczonym sam), przy zakupie rodzaj (środki trwałe / pozostałe) i odliczenie 100/50/0%.
  Domyślny okres: sprzedaż - miesiąc operacji, zakup - miesiąc księgowania.
- **Podpowiedź dekretu** z pozycji VAT: sprzedaż Wn 200-kod brutto / Ma przychód netto / Ma 222 VAT; zakup Wn koszt
  netto (+ VAT nieodliczony) / Wn 221 VAT do odliczenia / Ma 202-kod brutto. Nic nie zapisuje, tylko wypełnia formularz.
- **Ostrzeżenia** przy dokumencie (nie blokują księgowania): VAT z rejestru różny od zapisu na 222/221, faktura bez
  pozycji VAT albo bez kontrahenta.
- **Rejestry VAT** za miesiąc (tylko dokumenty zaksięgowane): sprzedaż i zakup z sumami według stawek, rozliczenie
  miesiąca (należny minus naliczony do odliczenia) i CSV. Storno kopiuje pozycje VAT z minusem do tego samego okresu.

## Import z innego programu (np. wFirma)

Zakładka Import (poziom zarządzanie) wczytuje pliki JPK - bez kluczy API do innego programu:

- **JPK_KR**: plan kont z zestawienia obrotów i sald (brakujące konta nadrzędne zakładane same), bilans otwarcia
  (z kolumn BO zestawienia, chyba że dziennik ma własny zapis BO) i każda pozycja dziennika jako zaksięgowany
  dokument w tej samej kolejności. Rok obrotowy powstaje sam; ponowny import tego roku zastępuje poprzedni. Import
  nie działa na rok z dokumentami wprowadzonymi w CRM. Zestawienie z pliku zostaje w `ext_ksg_imports`.
- **Porównanie**: obroty narastająco, bilans otwarcia i saldo z pliku obok naszych - do sprawdzenia, czy system
  liczy to samo co program źródłowy (też po dopisaniu własnych dokumentów).
- **JPK_V7 (M/K)**: wiersze sprzedaży i zakupu przypinane do zaimportowanych dokumentów po numerze dowodu
  (porównanie samych liter i cyfr): kontrahent po NIP-ie (nowy - bez zakładania kont rozrachunkowych) i pozycje VAT
  według pól K_10…K_43. Wiersze bez dokumentu są wypisywane. Ponowny import miesiąca nie dubluje VAT.

## Pliki

| Plik | Co robi |
|---|---|
| `KsiegowoscExtension.php` | manifest, strony (`ext.php?page=ksiegowosc.*`), grupa menu, hak zdarzeń |
| `Db.php` | połączenie (baza CRM albo osobna), tabele, pomocnicze zapytania, historia zmian |
| `Ledger.php` | lata, plan kont, dokumenty, księgowanie, storno, zestawienia, podpowiedź dekretu |
| `Contractors.php`, `Vat.php` | kartoteka kontrahentów z kontami rozrachunkowymi, pozycje i rejestry VAT |
| `Access.php` | poziomy dostępu |
| `Inbox.php`, `Api.php` | jedyne wejście z CRM i jedyne wyjście do CRM |
| `ChartTemplate.php`, `Money.php` | wzorcowy plan kont, kwoty w groszach |
| `Pages.php`, `Ui.php` | strony panelu |

## Następne etapy

Plan: rozrachunki (rozliczanie należności i zobowiązań) i import wyciągów bankowych → faktury i przeniesienie wtyczki KSeF →
paragony (przeniesienie wtyczki Paragony) → deklaracje (JPK_V7, CIT-8, e-Sprawozdanie) → kadry i płace → zamknięcie roku.

## Testy

`php tests/ksiegowosc.php` (logika ksiąg; z `CRM_TEST_MYSQL=1` ten sam scenariusz na MySQL) i
`php tests/ksiegowosc_http.php` (strony panelu, uprawnienia, menu).
