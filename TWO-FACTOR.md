# Google Authenticator / 2FA

## Jak włączyć na koncie

1. Zaloguj się do CRM i wybierz **Bezpieczeństwo / 2FA** obok nazwy użytkownika.
2. Potwierdź hasło i wybierz **Skonfiguruj 2FA**.
3. W Google Authenticator wybierz „+” → „Zeskanuj kod QR”. Alternatywnie wpisz
   klucz ręcznie, jako konto oparte na czasie. Działają też inne aplikacje TOTP.
4. Wpisz aktualne hasło i sześciocyfrowy kod z aplikacji. Dopiero wtedy 2FA zostaje
   aktywowane. Konfiguracja niedokończona w ciągu 10 minut wygasa.
5. Zapisz dziesięć kodów odzyskiwania w menedżerze haseł lub innym bezpiecznym
   miejscu poza telefonem. Są pokazywane **tylko raz**, każdy działa jeden raz.

Przy kolejnych logowaniach po haśle pojawi się osobny ekran kodu. Można na nim
podać kod odzyskiwania zamiast kodu z telefonu; hasło nadal jest wymagane.
Weryfikacja drugiego składnika wygasa po 5 minutach. Kodów nie wolno udostępniać.

Wyłączenie 2FA i wygenerowanie nowego zestawu kodów odzyskiwania wymaga hasła
oraz kodu z aplikacji lub niewykorzystanego kodu odzyskiwania. Zmiany unieważniają
pozostałe sesje konta. Po użyciu kodu TOTP poczekaj na następny kod w aplikacji,
zanim wykonasz kolejną operację. Stare kody odzyskiwania przestają działać po regeneracji.

## Wdrożenie

Administrator może zaznaczyć **Wymagaj 2FA** w edycji lub podczas dodawania konta
w **System → Użytkownicy**. Wymóg działa dla każdej roli. Zapis unieważnia sesje
tego użytkownika; bez skonfigurowanej aplikacji dostępna jest tylko konfiguracja 2FA.
Odznaczenie nie usuwa aktywnego 2FA i nie znosi globalnej polityki administratorów.
Migracja dodaje również `admin_users.totp_required` (domyślnie 0).

- Wgraj zmiany w `public/admin/auth.php`, `login.php`, `bootstrap_admin.php`,
  `nav.php`, `users.php`, `src/Support/AdminSession.php` oraz nowe pliki:
  `public/admin/login_mfa.php`, `public/admin/security.php`,
  `public/admin/assets/qrcodegen.js`, `public/admin/assets/totp-setup.js`,
  `src/Support/Totp.php`, `src/Support/TwoFactorCipher.php`,
  `src/Services/TwoFactorService.php`.
- Wymagana jest wcześniejsza aktualizacja zabezpieczeń, w tym `RateLimiter`
  i prywatny katalog `storage/security`. Nie nadpisuj produkcyjnego `.env`.
- PHP 8.1+ z OpenSSL; serwer i telefon muszą mieć poprawny czas (NTP).
  Korzystaj z panelu wyłącznie przez HTTPS. `storage` musi być poza publicznym
  katalogiem WWW lub całkowicie zablokowany przez serwer; na Nginx `.htaccess` nie działa.
- Bootstrap automatycznie dodaje do `admin_users` kolumny `totp_secret`,
  `totp_last_step` i `totp_recovery_hashes`. Konto bazy wymaga uprawnień ALTER.
- Zmiana formatu podpisu sesji wymusi jednorazowe ponowne logowanie po wdrożeniu.
- Domyślnie 2FA jest opcjonalne. Aby wymagać go od administratorów, dodaj
  `TOTP_REQUIRE_ADMIN=1` do `.env`. Administrator bez 2FA zostanie ograniczony
  do strony konfiguracji (oraz wylogowania); nie może wtedy wyłączyć 2FA.
  Pozostałe role nadal mogą włączać 2FA dobrowolnie.
- `TOTP_ISSUER=CRM` określa nazwę widoczną w aplikacji. Dla kilku instalacji użyj
  różnych nazw. Zmiana tej wartości nie zmienia już dodanych kont w telefonach.

### Klucz szyfrowania — ważne

Sekrety są szyfrowane AES-256-GCM i związane z ID konta. Losowy klucz powstaje
przy pierwszej konfiguracji w `storage/security/totp.key` (uprawnienia 0600).
Opcjonalnie ustaw `TOTP_KEY_FILE` na bezwzględną ścieżkę **poza katalogiem WWW**.
Katalog musi być zapisywalny przez PHP. Plik klucza nie jest wersjonowany w Git.

**Zrób bezpieczną kopię tego pliku**, przechowywaną oddzielnie od kopii bazy.
Przy migracji CRM przenieś ten sam klucz wraz z bazą. Nie usuwaj go i nie generuj
nowego, gdy konta mają już aktywne 2FA. Brak/uszkodzenie klucza blokuje weryfikację;
system nie wyłącza wtedy zabezpieczeń. Przywróć oryginalny klucz z kopii.
Utrata telefonu: użyj kodu odzyskiwania. Utrata telefonu i wszystkich kodów wymaga
osobnej, zweryfikowanej procedury odzyskania konta przez operatora serwera — nie ma
obejścia 2FA samym hasłem ani przycisku wyłączania 2FA innych osób w panelu.

## Zabezpieczenia

- TOTP zgodne z RFC 6238: SHA-1, 6 cyfr, okres 30 s, tolerancja jednego kroku.
- Zużyty krok TOTP i kod odzyskiwania są blokowane atomowym UPDATE w bazie,
  także dla równoległych żądań. Kody odzyskiwania mają po 128 bitów losowości;
  w bazie przechowywane są wyłącznie ich skróty SHA-256.
- Limity prób 2FA: 10 na konto i 40 na adres IP w 15 minut, wspólne dla sesji
  i operacji konfiguracji. Dotychczasowe limity haseł pozostają aktywne.
- Weryfikacja hasła nie tworzy pełnej sesji, jeśli konto ma aktywne 2FA.
  Zmiana hasła, roli, dezaktywacja lub konfiguracja 2FA unieważnia oczekujące logowanie.
- CSRF dla wszystkich formularzy, regeneracja sesji, brak cache/referrera na
  ekranach 2FA oraz ograniczona polityka CSP na stronie konfiguracji.
- QR powstaje w przeglądarce z lokalnego pliku JS. Sekret nie jest wysyłany
  do Google ani usługi generującej obrazki. TOTP nie chroni przed phishingiem
  przekazującym kod w czasie rzeczywistym — zawsze sprawdzaj adres panelu.

## Testy (bez produkcyjnej bazy i zewnętrznych integracji)

```
php tests/security.php
php tests/two_factor.php
php tests/two_factor_http.php
node tests/two_factor_ui.js
```

Test HTTP używa tymczasowej bazy SQLite i krótkotrwałego serwera 127.0.0.1.
Wektory RFC sprawdzają zgodność TOTP, testy SQL — szyfrowanie, jednorazowość i sesje,
a testy formularzy — konfigurację, logowanie, odzyskiwanie i wymóg administratorów.
Migrację schematu MySQL i skanowanie rzeczywistym telefonem należy sprawdzić
na środowisku testowym przed uruchomieniem wymogu 2FA na produkcji.

## Źródła i biblioteka QR

- Standard: https://www.rfc-editor.org/rfc/rfc6238
- Format URI: https://github.com/google/google-authenticator/wiki/Key-Uri-Format
- QR: Project Nayuki, licencja MIT zawarta w nagłówku lokalnego pliku.
  Źródło: https://www.nayuki.io/res/qr-code-generator-library/qrcodegen.js
  Pobrano 2026-09-23. SHA-256 lokalnej kopii:
  `2511bc17f40a3c41d4a0578995db956b38997334d3d20113a5d4dc5c49c69480`.

## Szybkie logowanie innym urządzeniem

Pozwala zalogować nowe urządzenie bez wpisywania hasła, jeśli jesteś już zalogowany
na innym. Działa w obie strony: telefon loguje komputer, a komputer loguje telefon.

1. Na nowym urządzeniu, na stronie logowania, wybierz **Zaloguj innym urządzeniem**.
   Pojawi się kod w postaci `ABCD-EFGH` (i QR), ważny 60 sekund.
2. Na zalogowanym urządzeniu otwórz **2FA → Zatwierdź logowanie urządzenia** i wpisz kod.
   Gdy kod jest na ekranie komputera, telefonem wystarczy zeskanować QR.
3. Zobaczysz przeglądarkę, system i adres IP nowego urządzenia. Wybierz **Zatwierdź logowanie**.
   Nowe urządzenie zaloguje się samo, na to samo konto.

Zabezpieczenia:

- Sesję dostaje tylko przeglądarka, która wygenerowała kod: jest z nim związana losowym
  sekretem w jej sesji. Sam kod, nawet przechwycony, nikogo nie loguje.
- Kod działa raz i wygasa po 60 sekundach; komputer ma kolejne 60 sekund na odebranie
  zatwierdzenia. W bazie (`admin_quick_logins`) zapisywane są tylko skróty kodu i sekretu.
- Zatwierdzić może tylko w pełni zalogowane urządzenie (po haśle i 2FA, jeśli jest włączone).
  Zmiana hasła, roli, 2FA lub unieważnienie sesji przed odebraniem kasuje zatwierdzenie.
- Limity prób: generowanie kodów na IP, sprawdzanie kodów na koncie i IP
  (`storage/security/quick_login.json`).
- Po zalogowaniu wysyłany jest e-mail „Nowe logowanie do panelu” (jeśli konto ma adres
  i skonfigurowaną pocztę). Historia z 30 dni jest na stronie **2FA**.
- **Nie zatwierdzaj kodu, który ktoś Ci podał lub przysłał** — to typowa próba przejęcia konta.

Wdrożenie: nowe pliki `public/admin/login_quick.php`, `public/admin/quick_approve.php`,
`public/admin/assets/quick-login.js`, `src/Support/QuickLogin.php`; zmienione `login.php`,
`security.php`, `nav.php`. Tabela `admin_quick_logins` tworzy się sama przy pierwszym użyciu.

## Zalogowane urządzenia

Na stronie **2FA** jest lista urządzeń, na których konto jest zalogowane: przeglądarka
i system, ostatnia aktywność, adres IP, data i sposób logowania (hasło, hasło i 2FA,
szybkie logowanie). Bieżące urządzenie jest oznaczone. Przy pozostałych jest przycisk
**Wyloguj**, a pod listą **Wyloguj wszystkie inne urządzenia**. Wylogowane urządzenie
traci dostęp przy następnym kliknięciu. Wylogowanie nie wymaga hasła, bo tylko ogranicza dostęp.

Każde logowanie zapisuje wiersz w `admin_devices` (w bazie tylko skrót tokenu sesji).
Sesje otwarte przed wdrożeniem są dopisywane przy pierwszym kliknięciu, więc wdrożenie
nikogo nie wylogowuje. Po zmianie hasła, roli lub 2FA stare sesje i tak wygasają
i znikają z listy. Tabela tworzy się sama.
