# Strona OMS Klubu Woskarzy (oms.klubwoskarzy.pl)

Strona produktu w stylu klubwoskarzy.pl (Work Sans + szeryfowe nagłówki, złoto #b08328, krem #faf8f3).
„Veless” to nazwa kodowa; na stronie i w demo widać markę Klubu Woskarzy. Stara strona veless.pl to katalog `site/`.

- `index.html` – cała strona (statyczny HTML, CSS i JS w pliku, bez PHP i bez formularzy).
- `assets/logo.png` – znak Klubu.
- `assets/screens/` – zrzuty z demo (fikcyjna pracownia świec).

Przyciski prowadzą do: demo `https://demo.oms.klubwoskarzy.pl/`, dołączenia do klubu `https://klubwoskarzy.pl/sklep/`
i Strefy Klubowicza (`https://klubwoskarzy.pl/moje-konto/strefa-klubowicza/`), gdzie członkowie pobierają paczkę instalacyjną.

Podgląd lokalnie: otwórz `oms-site/index.html` w przeglądarce.

## Wdrożenie (tak jak veless.pl i demo.veless.pl: hosting z LiteSpeed/Apache, wgrywanie przez FTP)

### 1. Strona oms.klubwoskarzy.pl

1. W panelu hostingu dodaj subdomenę `oms.klubwoskarzy.pl` (DirectAdmin: *Zarządzanie subdomenami*) i włącz dla niej SSL (Let's Encrypt).
2. Wgraj zawartość `oms-site/` (bez README.md) do katalogu subdomeny, np. `domains/klubwoskarzy.pl/public_html/oms/`
   albo `domains/oms.klubwoskarzy.pl/public_html/` – zależnie od tego, gdzie panel założył subdomenę.

### 2. Demo demo.oms.klubwoskarzy.pl

1. Dodaj subdomenę `demo.oms.klubwoskarzy.pl` z SSL i nową bazę MySQL (np. `klub_omsdemo`).
2. Wgraj cały projekt do katalogu subdomeny (jak demo.veless.pl: główny `.htaccess` blokuje `.env`, `src/` i `config/`,
   a adres główny przekierowuje do panelu).
3. Utwórz w tym katalogu `.env` na podstawie `.env.example`:
   ```
   DEMO_MODE=1
   APP_BRAND="Klub Woskarzy OMS"
   DEMO_ACCENT=#8a6519
   DB_HOST=localhost
   DB_NAME=...  DB_USER=...  DB_PASS=...
   WORKER_HTTP_SECRET=<długi losowy ciąg>
   ```
4. Raz uruchom reset (z SSH albo jako jednorazowe zadanie cron): `php cli/demo_reset.php`.
   Tworzy bazę, konto demo/demo, 140 zamówień, 12 produktów, 6 wyrobów z recepturą, 6 partii i 8 zapachów, ustawia złoty kolor panelu.
5. Dodaj zadanie cron co noc: `0 3 * * * php /ścieżka/do/demo/cli/demo_reset.php`.

### 3. Automatyczne wgrywanie z GitHuba (opcjonalnie)

Workflow `.github/workflows/deploy-oms.yml` wgrywa stronę i demo przez FTP po każdej zmianie na `main` albo ręcznie
(Actions → „Strona i demo OMS Klubu Woskarzy” → Run workflow, z opcją próby bez zmian).

W repozytorium veless (Settings → Secrets and variables → Actions):

| Sekret | Przykład |
| --- | --- |
| `OMS_FTP_SERVER` | `ftp.klubwoskarzy.pl` |
| `OMS_FTP_USERNAME`, `OMS_FTP_PASSWORD` | konto FTP z dostępem do obu katalogów |
| `OMS_SITE_DIR` | `/domains/oms.klubwoskarzy.pl/public_html/` |
| `OMS_DEMO_DIR` | `/domains/demo.oms.klubwoskarzy.pl/public_html/` |

Zmienne: `OMS_SITE_DEPLOY=1` (strona), `OMS_DEMO_DEPLOY=1` (demo), opcjonalnie `FTP_PROTOCOL=ftp`, gdy serwer nie ma FTPS.
Bez tych zmiennych workflow nic nie wgrywa. `.env`, `storage/` i obrazki demo na serwerze zostają nietknięte.

Wdrożenie produkcji AWiEN (veless-awien) i paczka instalacyjna pomijają `oms-site/`.
