# Serwer aktualizacji Veless

Mały serwis PHP, z którego zainstalowane systemy Veless raz dziennie pobierają nowe wersje.
Nie jest częścią paczki Veless i nie potrzebuje bazy danych.

## Uruchomienie

1. Wgraj zawartość tego katalogu na hosting z PHP 8.1+ i rozszerzeniem zip, najlepiej pod osobną
   subdomeną z certyfikatem SSL, np. `https://aktualizacje.twoja-domena.pl/`.
2. Skopiuj `config.example.php` jako `config.php` i wpisz `admin_key` (długie losowe hasło).
3. Upewnij się, że PHP może pisać do `data/`. Katalogi `data/` i `releases/` są zablokowane z internetu
   (`.htaccess`; przy nginx zablokuj je w konfiguracji serwera).
4. W repozytorium Veless (Settings → Secrets and variables → Actions) ustaw:
   - zmienną `VELESS_UPDATE_URL` = adres z punktu 1 (trafi do każdej nowej paczki),
   - sekret `VELESS_SIGNING_KEY` = klucz prywatny podpisu (klucz publiczny jest w `src/Support/ReleaseSignature.php`),
   - opcjonalnie `UPDATES_FTP_SERVER`, `UPDATES_FTP_USERNAME`, `UPDATES_FTP_PASSWORD`, `UPDATES_FTP_DIR`
     (katalog `releases/` tego serwera), żeby workflow sam wgrywał paczki.

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
