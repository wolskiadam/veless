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

## Pliki

| Plik | Co robi |
|---|---|
| `KsiegowoscExtension.php` | manifest, strony (`ext.php?page=ksiegowosc.*`), grupa menu, hak zdarzeń |
| `Db.php` | połączenie (baza CRM albo osobna), tabele, pomocnicze zapytania, historia zmian |
| `Ledger.php` | lata, plan kont, dokumenty, księgowanie, storno, zestawienia |
| `Access.php` | poziomy dostępu |
| `Inbox.php`, `Api.php` | jedyne wejście z CRM i jedyne wyjście do CRM |
| `ChartTemplate.php`, `Money.php` | wzorcowy plan kont, kwoty w groszach |
| `Pages.php`, `Ui.php` | strony panelu |

## Następne etapy

Plan: kontrahenci i rejestry VAT z rozrachunkami i wyciągami bankowymi → faktury i przeniesienie wtyczki KSeF →
paragony (przeniesienie wtyczki Paragony) → deklaracje (JPK_V7, CIT-8, e-Sprawozdanie) → kadry i płace → zamknięcie roku.

## Testy

`php tests/ksiegowosc.php` (logika ksiąg; z `CRM_TEST_MYSQL=1` ten sam scenariusz na MySQL) i
`php tests/ksiegowosc_http.php` (strony panelu, uprawnienia, menu).
