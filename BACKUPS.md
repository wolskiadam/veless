# Szyfrowane kopie i test odtwarzania

## Kopie z panelu: osobno ustawienia, zamówienia, produkty

Panel → System → **Kopia zapasowa** (tylko administrator). Każda część to osobny plik ZIP
szyfrowany AES-256 hasłem podanym przy tworzeniu (min. 8 znaków; hasła nie da się odzyskać):

- **Ustawienia systemu** — settings, integracje i konta integracji (klucze API), konta e-mail,
  statusy, automatyzacje, szablony e-mail i wydruku (z grafikami), pliki do druku, własne pola,
  szablony opisów Allegro, układy widoków.
- **Dane zamówień** — zamówienia, rejestr importu, wiadomości (z załącznikami), dokumenty,
  rezerwacje dokumentów, przesyłki, historia zmian + `zamowienia.csv` do otwarcia w Excelu.
  Można wybrać **okres** (data złożenia zamówienia) — wtedy kopia zawiera tylko zamówienia
  z zakresu i powiązane z nimi wiersze/załączniki, a jej przywrócenie zastępuje wyłącznie
  te zamówienia (pozostałe zostają bez zmian).
- **Katalog produktów** — produkty, mapowania SKU, szablony ofert, szczegóły ofert Allegro.

Poza tymi kopiami: konta użytkowników i 2FA, kolejka zadań/wydruków, cache, kod, `.env`.
Znaczniki pracy systemu (kursor zdarzeń Allegro, czasy synchronizacji, stan powiadomień) nie są
eksportowane ani nadpisywane.

Kopie leżą w `storage/backups/sections/` (blokada WWW przez `.htaccess`), można je pobrać,
wgrać z komputera (np. przy przenosinach), sprawdzić hasłem (sumy SHA-256 wszystkich plików)
i przywrócić. Przywrócenie zastępuje tabele TYLKO wybranej części w jednej transakcji; przed
nim system sam zapisuje kopię bieżącego stanu tej części. Aktualne tokeny API (Allegro rotuje
refresh token) zostają, jeśli integracja już istnieje. Kopia danych nie cofa operacji
w Allegro / WooCommerce / wFirma. Kod: `src/Services/SectionBackup.php`,
test: `php tests/section_backup.php`.

Kopie trzymane tylko na serwerze nie chronią przed awarią hostingu — pobieraj je na komputer.

## Pełna kopia systemu z instalatorem (przeniesienie na inny serwer/domenę)

Panel → System → **Kopia zapasowa** → *Pełna kopia systemu* (tylko administrator, hasło min. 12 znaków).
Pobiera się jeden ZIP: `install.php`, `crm-pakiet-<losowe>.zip` (kod, `.env`, cała baza, pliki
`storage` z załącznikami, klucz 2FA — wszystko szyfrowane AES-256 hasłem kopii), `PRZENIESIENIE.txt`
(instrukcja z danymi do ręcznych kroków: obecne crony, adres przekierowania Allegro, webhooki Woo;
bez haseł i kluczy, adresy nowej domeny jako `{NOWY_ADRES}`; do pobrania też osobno na stronie kopii)
i `.htaccess` blokujący pobranie pakietu z WWW. Na serwerze plik powstaje w `storage/backups/full/`
(blokada WWW) i jest usuwany po pobraniu w całości, a nieodebrany — po 24 godzinach.
Pominięte: sesje, logi, inne kopie, kopie wtyczek, cache, `.git`, `tests/`, dowiązania symboliczne.

Na nowym serwerze: pusta baza MySQL/MariaDB, zawartość ZIP-a do pustego katalogu domeny,
`https://domena/install.php` w przeglądarce → hasło kopii → dane bazy i adres (wykrywany sam).
Instalator (`src/Installer/install.php`, samodzielny plik) pracuje porcjami po ~15 s, więc limit
czasu hostingu mu nie przeszkadza, a przerwaną instalację można wznowić. Odmawia pracy na niepustej
bazie i w katalogu z istniejącym CRM. Importuje bazę w UTC (kolumny TIMESTAMP bez przesunięcia),
zamienia kolacje MySQL 8 na zgodne z MariaDB, zapisuje nowy `.env` (nowa baza, nowy redirect Allegro,
wyłączone ścieżki ze starego serwera: `TOTP_KEY_FILE`, `BACKUP_*`), podmienia stary adres panelu
w ustawieniach i integracjach, kładzie klucz 2FA w `storage/security/`, po czym usuwa pakiet i siebie.

Ręcznie (instalator pokazuje gotowe wartości): cron workera na nowym serwerze **i usunięcie go ze
starego** (inaczej dwa CRM-y pobierają te same zamówienia i wystawiają dokumenty), adres przekierowania
w aplikacji Allegro, adresy webhooków WooCommerce, domena/DNS/SSL. Użytkownicy logują się ponownie
(sesje nie są przenoszone). Test: `php tests/full_system_package.php` (lokalny MySQL, jak `sales_mysql.php`).

Szyfrowana kopia z kluczem w pliku (CLI) — niżej.

## Status wdrożenia

Kod i testy są lokalne. Nie wykonano kopii produkcyjnej, nie skonfigurowano crona
ani magazynu zewnętrznego. Test SQLite z fikcyjnymi danymi przeszedł, łącznie
z odszyfrowaniem sekretu 2FA po odtworzeniu. Test MySQL jest przygotowany w CI,
ale nie był uruchamiany lokalnie — brak serwera MySQL.

## Co obejmuje kopia

- Schemat tabel MySQL i wszystkie wiersze, w tym historia i rezerwacje dokumentów.
- Pliki aplikacji, integracje, konfiguracja `.env`, pliki storage (w tym załączniki)
  i klucz 2FA — również gdy `TOTP_KEY_FILE` wskazuje poza aplikację.
- Manifest z rozmiarami i SHA-256 każdej zawartości. Każdy plik oraz manifest
  są szyfrowane ZIP AES-256. Nazwy plików w ZIP nie są ukryte.

Klucz backupu jest **osobny**, nie jest dodawany do archiwum. Bez niego nie da
się odzyskać kopii. Przechowuj drugą kopię klucza offline, oddzielnie od backupów.
Archiwum zawiera hasła/API/dane klientów — również odtworzony katalog jest poufny.
Nie przechowuj go ani klucza pod katalogiem serwowanym przez WWW.

Pominięte są repozytorium Git, pliki narzędzi developerskich, logi/locki storage
i pliki JSON limitera logowania. Symlinki powodują odmowę, a nie ciche pominięcie.
Niestandardowe widoki, wyzwalacze, procedury, eventy, generowane kolumny i tabele
inne niż InnoDB wymagają natywnego backupu MySQL; skrypt odmawia wykonania
niepełnej kopii. Limit weryfikacji: 20 GiB rozpakowanych danych. Nie obejmuje
ustawień hostingu, kont MySQL, crona, DNS ani dokumentów przechowywanych wyłącznie
w zewnętrznych usługach. Snapshot JSONL nie jest zwykłym plikiem `.sql`.

## Konfiguracja na serwerze

Wymagania: PHP 8.2+, PDO MySQL, ext-zip z AES-256; miejsce na snapshot bazy,
archiwum i osobno odtworzone pliki. Użyj prywatnych katalogów z prawami 0700.
W `.env` ustaw (ścieżki są przykładem, nie poleceniem do wykonania bez dostosowania):

```dotenv
BACKUP_DIR=/home/KONTO/private/crm-backups
BACKUP_WEB_ROOT=/home/KONTO/public_html
BACKUP_KEY_FILE=/home/KONTO/private-keys/crm-backup.key
```

`BACKUP_WEB_ROOT` musi wskazywać **cały katalog WWW hostingu**, nie tylko
podkatalog CRM. Katalog kopii i klucz muszą znajdować się poza nim i poza
aplikacją; klucz dodatkowo poza katalogiem kopii. Rodzice ścieżek muszą istnieć.
Skrypt nie zmienia automatycznie uprawnień cudzych katalogów.

W katalogu aplikacji uruchom jednorazowo:

```sh
php cli/backup.php init-key
```

Klucz 0600 jest tworzony tylko wtedy, gdy nie istnieje. Nie usuwaj go ani nie
generuj nowego zamiast starego: stare kopie pozostaną zaszyfrowane starym kluczem.

## Wykonanie spójnej kopii

Na czas kopii wstrzymaj zapisy: worker/cron, zmiany operatorów i przyjmowanie
webhooków. Zrób to na poziomie hostingu w kontrolowanym oknie, bez odrzucania
zdarzeń bezpowrotnie. Nie instaluj wtedy migracji ani nowych plików.
Skrypt **nie włącza sam trybu konserwacji**; flaga jest potwierdzeniem operatora.
Snapshot bazy jest transakcyjny, lecz pliki i baza nie mają wspólnej transakcji.

```sh
php cli/backup.php create --writes-paused
```

Skrypt nie wywołuje migracji bazy źródłowej. Weryfikuje każdą zawartość przed
publikacją finalnego archiwum. Istniejących kopii nie nadpisuje, automatycznie
niczego nie usuwa. Po zakończeniu (także po błędzie!) przywróć działanie CRM,
workera i webhooków; sprawdź zaległe zdarzenia. Zatrzymanie procesu przez SIGKILL
może zostawić prywatny katalog `stage-*` z nieszyfrowanym snapshotem: usuń go
ręcznie po upewnieniu się, że żaden backup nie działa.

Kopia na tym samym serwerze nie zabezpiecza awarii hostingu. Przenieś **zaszyfrowane
archiwum**, nie katalog stage ani klucz, na uzgodniony zewnętrzny magazyn.
Nie wdrożono jeszcze automatycznej rotacji, transferu off-site ani harmonogramu.

## Weryfikacja i próbne odtworzenie

```sh
php cli/backup.php check /pelna/sciezka/do/kopii.zip
```

Rozpakowuje i sprawdza wszystkie pliki w NOWYM prywatnym katalogu
`BACKUP_DIR/restore-check-<losowy identyfikator>`. Niczego nie nadpisuje i nie
uruchamia odtworzonego kodu. Sukces tego polecenia bez `--mysql-test` oznacza
weryfikację archiwum/plików, **nie odtworzenie bazy MySQL**.

Pełniejszą próbę bazy wykonaj wyłącznie na dedykowanym lokalnym serwerze TESTOWYM
MySQL, bez dostępu do usług produkcyjnych, z kontem testowym mogącym tworzyć
i usuwać bazy. Nie podawaj tutaj konta produkcyjnego. Ustaw w środowisku procesu:
`CRM_TEST_MYSQL=1`, `CRM_TEST_MYSQL_USER`, `CRM_TEST_MYSQL_PASSWORD`, opcjonalnie
`CRM_TEST_MYSQL_PORT` (domyślnie 3306). Następnie:

```sh
php cli/backup.php check /pelna/sciezka/do/kopii.zip --mysql-test
```

Połączenie testowe jest tylko do 127.0.0.1, nie korzysta z `DB_*` aplikacji.
Powstaje losowa baza `crm_restore_<24 znaki hex>`. Skrypt odtwarza schemat
i dane, porównuje liczby wierszy i usuwa WYŁĄCZNIE tę utworzoną bazę. Katalog
odtworzonych plików pozostaje do kontroli; po teście usuń go bezpiecznie.
Nie uruchamiaj w nim workera ani strony — `.env` nadal zawiera prawdziwe dane
połączeń. Test bazy nie wysyła maili, nie wystawia dokumentów i nie wywołuje API.
Używaj wyłącznie zaufanych archiwów utworzonych przez ten system.

## Odzyskiwanie po awarii

Nie ma automatycznego nadpisywania produkcji. Najpierw odtwórz i sprawdź kopię
w izolacji. Przed uruchomieniem odzyskanego CRM popraw konfigurację ścieżek,
przywróć klucz 2FA, sprawdź uprawnienia i uzgodnij stan zewnętrznych usług.
**Dokumenty wystawione po dacie kopii mogą istnieć w wFirma, ale nie w odtworzonej
bazie.** Nie włączaj automatyzacji wystawiania, dopóki te dokumenty i płatności
nie zostaną uzgodnione. Backup nie cofa operacji w wFirma/Woo/Allegro.

## Testy developerskie

`php tests/backup_restore.php` — fikcyjna baza SQLite, szyfrowanie, błędny klucz,
sumy kontrolne, blokada nadpisania, odtworzenie binariów/NULL/Unicode/kwot,
indeksów, rezerwacji dokumentów i klucza 2FA.
`php tests/sales_mysql.php` — opt-in, lokalny MySQL, prawdziwe migracje i
odtworzenie snapshotu do osobnej bazy. CI dodaje ten etap do testów sprzedaży.

## Szyfrowanie tokenów i kluczy API w bazie

Tokeny OAuth (Allegro, TikTok), klucze API kont integracji (wFirma, PayU, SMSAPI, BLPaczka, GS1),
sekrety webhooków, hasła SMTP i sekretne ustawienia (`*_SECRET`, `*_KEY`, `*_PASS`…) są w bazie
szyfrowane AES-256-GCM (`Pase\Support\SecretStore`, wartości z przedrostkiem `enc:v1:`). Klucz to
podklucz **pliku klucza 2FA** (`storage/security/totp.key` albo `TOTP_KEY_FILE`), więc:

- **Bez tego pliku zapisane tokeny i klucze API są nieczytelne.** Trzymaj jego kopię offline
  (jak klucz backupu). Kopia CLI, pełna kopia systemu i przeniesienie online ⇄ komputer zabierają go same.
- Kopia sekcji z panelu zapisuje sekrety jawnie wewnątrz archiwum chronionego hasłem; po przywróceniu
  są szyfrowane kluczem tej instalacji, więc działa też na innym serwerze.
- Istniejące jawne wartości szyfruje worker (cron) przy pierwszym przebiegu po aktualizacji. Jeśli pliku
  klucza nie ma, a w bazie nie ma danych 2FA ani zaszyfrowanych sekretów, worker tworzy klucz sam.
- Bez klucza zapis zostaje jawny (jak przed zmianą) i nic nie przestaje działać.

Ochrona dotyczy wycieku samej bazy (zrzut, phpMyAdmin, SQL injection, kopia bez pliku klucza).
Nie chroni przed kimś, kto wykona kod PHP na serwerze (odczyta też plik klucza).
Test: `php tests/secret_store.php`.
