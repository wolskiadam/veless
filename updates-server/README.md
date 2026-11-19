# Serwer aktualizacji Veless

Mały serwis PHP, z którego zainstalowane systemy Veless raz dziennie pobierają nowe wersje.
Nie jest częścią paczki Veless i nie potrzebuje bazy danych.

## Uruchomienie (stan na 2026-11-19)

Zrobione:

- [x] Klucz podpisu paczek. Para kluczy Ed25519 powstała 2026-11-19 na komputerze Klubu. Klucz publiczny jest w
  `src/Support/ReleaseSignature.php` (`PUBLIC_KEYS`), a prywatny w sekrecie `VELESS_SIGNING_KEY` repozytorium Veless
  i w menedżerze haseł Klubu.
- [x] Adres serwera `https://aktualizacje.klubwoskarzy.pl/`. Workflow „Paczka wydania” wpisuje go domyślnie do każdej
  paczki, więc zmienna `VELESS_UPDATE_URL` jest potrzebna tylko przy innym adresie.

Do zrobienia:

1. **Subdomena.** W panelu hostingu klubwoskarzy.pl dodaj subdomenę `aktualizacje` z osobnym katalogiem,
   np. `aktualizacje.klubwoskarzy.pl/`. Nie wskazuj na katalog WordPressa.
2. **SSL.** Włącz dla subdomeny darmowy certyfikat Let's Encrypt. Veless łączy się tylko przez https.
3. **PHP.** Ustaw dla subdomeny PHP 8.1 lub nowsze z rozszerzeniem zip.
4. **Pliki.** Wgraj przez FTP do katalogu subdomeny całą zawartość tego folderu (`updates-server/`):
   `index.php`, `.htaccess`, `config.example.php` oraz katalogi `data/` i `releases/` z ich plikami `.htaccess`.
   Plik `README.md` możesz pominąć.
5. **Konfiguracja.** Na serwerze skopiuj `config.example.php` jako `config.php` i wpisz w `admin_key` długie losowe
   hasło (np. 40 znaków z menedżera haseł). To hasło otwiera listę instalacji.
6. **Prawa zapisu.** PHP musi móc pisać do `data/` (zwykle 755 wystarcza; jeśli lista instalacji zostaje pusta,
   ustaw 775 lub 777 tylko dla `data/`).
7. **Sprawdzenie.** Otwórz w przeglądarce:
   - `https://aktualizacje.klubwoskarzy.pl/` musi pokazać `{"service":"veless-updates"}`,
   - `https://aktualizacje.klubwoskarzy.pl/data/` i `.../releases/` muszą dać błąd 403 (zablokowane). Przy serwerze
     nginx bez obsługi `.htaccess` zablokuj je w konfiguracji serwera,
   - `https://aktualizacje.klubwoskarzy.pl/?action=installs&key=<admin_key>` musi pokazać (na razie pustą) listę.
8. **Wysyłka paczek przez workflow (opcjonalnie).** W repozytorium Veless (Settings → Secrets and variables →
   Actions) dodaj sekrety `UPDATES_FTP_SERVER` (np. `ftp.klubwoskarzy.pl`), `UPDATES_FTP_USERNAME`,
   `UPDATES_FTP_PASSWORD` i `UPDATES_FTP_DIR` (ścieżka katalogu `releases/` na koncie FTP, z ukośnikiem na końcu,
   np. `/aktualizacje.klubwoskarzy.pl/releases/`). Najlepiej osobne konto FTP z dostępem tylko do tego katalogu.
   Bez tych sekretów paczkę pobierasz z artefaktu przebiegu i wgrywasz do `releases/` ręcznie.
9. **Pierwsze wydanie.** Actions → „Paczka wydania” → Run workflow z zaznaczonym „Opublikuj”. Po przebiegu
   sprawdź, że w `releases/` leży `veless-<wersja>.zip`, a `?action=check` dla dowolnej instalacji zwraca tę wersję.
   Instalacje zgłoszą się na liście w ciągu doby (sprawdzają raz dziennie przez crona).

Klucz podpisu zmienia się tak: `php cli/release_keygen.php` na swoim komputerze, nowy klucz publiczny dopisz do
`PUBLIC_KEYS` (stary zostaw, dopóki klienci nie dostaną wersji z nowym), nowy prywatny wpisz w `VELESS_SIGNING_KEY`.
Klucza prywatnego nie zapisuj w repozytorium ani w plikach na serwerze.

## Wydanie nowej wersji

Actions → „Paczka wydania” → Run workflow: numer wersji, opis zmian, „Opublikuj”. Paczka trafia do `releases/`
(albo pobierasz ją z artefaktu i wgrywasz tam przez FTP). Zainstalowane systemy zobaczą ją w ciągu doby:
poprawki (1.0.x) instalują się same w nocy, większe wersje czekają na kliknięcie w panelu klienta.
Żeby daną wersję wstrzymać przed samoczynną instalacją, połóż obok `veless-<wersja>.json` z treścią `{"auto": false}`.

Serwer ogłasza tylko paczki podpisane, a Veless sprawdza podpis jeszcze raz przed instalacją.

## Lista instalacji

`https://…/index.php?action=installs&key=<admin_key>`: adres panelu, wersja, PHP i data ostatniego kontaktu każdej
instalacji. Brak kontaktu od 3 dni zwykle oznacza, że u klienta nie działa cron.

## Licencje

Funkcja `upd_allowed()` w `index.php` decyduje, czy instalacja dostaje nowe wersje. Na razie zawsze tak.
Po wygaśnięciu subskrypcji system klienta działa dalej, tylko bez nowych wersji.

Test: `CRM_TEST_MYSQL=1 php tests/update_server_http.php`.
