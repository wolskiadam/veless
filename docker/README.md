# Instalacja: hosting czy własny komputer (Docker)

Kod jest jeden, a tryb wybierasz przy instalacji (`APP_MODE` w `.env`).

| | Hosting (`APP_MODE=server`) | Własny komputer (`APP_MODE=local`) |
|---|---|---|
| Gdzie są dane | baza na Twoim hostingu | w katalogu CRM na komputerze (`dane/`, `storage/`) |
| Instalacja | `php cli/install.php` + cron co minutę | jedna komenda, potrzebny Docker Desktop |
| Nowe zamówienia | od razu, webhookami (Woo, PayU) | CRM sam sprawdza kanały co minutę |
| Gdy komputer/serwer jest wyłączony | — (działa 24/7) | zamówienia czekają w Allegro/sklepie, ściągają się po włączeniu |
| Pakowanie z telefonu | z dowolnego miejsca | tylko w tej samej sieci Wi‑Fi (`CRM_BIND=0.0.0.0`) |
| Maile do klientów, automatyzacje | od razu | po włączeniu komputera |
| Kopie zapasowe | hosting + System → Kopia zapasowa | tylko Ty: kopiuj katalog `dane/` i `storage/` albo pobieraj kopie z panelu |

## Start (tryb lokalny)

1. Zainstaluj [Docker Desktop](https://www.docker.com/products/docker-desktop/) i uruchom go.
2. Pobierz kod CRM (GitHub: *Code → Download ZIP*) i rozpakuj go do stałego katalogu,
   np. `C:\CRM` albo `~/CRM` (tam będą też dane).
3. W tym katalogu:
   - Linux / macOS: `sh docker/install.sh`
   - Windows (PowerShell): `powershell -ExecutionPolicy Bypass -File docker\install.ps1`
4. Otwórz `http://localhost:8081/admin/` i zaloguj się danymi, które wypisał instalator
   (login `admin`, hasło losowe). Zmień hasło po pierwszym logowaniu.

Instalator tworzy `.env` z losowymi hasłami (tylko za pierwszym razem), buduje obraz i uruchamia
trzy kontenery: `db` (MariaDB), `app` (panel, PHP + Apache) i `worker` (zastępuje crona, co minutę).
Uruchomiony ponownie aktualizuje i restartuje CRM bez utraty danych.

Codzienna obsługa: `docker compose stop` / `docker compose start`, logi: `docker compose logs -f worker`.
Kontenery startują same razem z Dockerem (`restart: unless-stopped`).

Instrukcja krok po kroku dla użytkownika (też aktualizacja i najczęstsze problemy) jest w panelu:
Baza wiedzy → *Instalacja offline (na własnym komputerze)*, plik `public/admin/help_content.php`.

## Aktualizacja

Pobierz nowy kod i rozpakuj go na stary katalog, zostawiając `.env`, `dane/` i `storage/`, potem uruchom
instalator jeszcze raz. Przed aktualizacją zrób kopię katalogów `dane/` i `storage/`.

## Gdy coś nie działa

- *Cannot connect to the Docker daemon* / *docker: command not found*: Docker Desktop nie jest uruchomiony.
- Port 8081 zajęty: w `.env` zmień `CRM_PORT` na np. 8090 i ten sam port w `APP_URL` oraz `ALLEGRO_REDIRECT_URI`, potem uruchom instalator ponownie.
- Zamówienia nie przychodzą: `docker compose logs --tail 50 worker`.
- Zapomniane hasło admina: w `.env` jest `ADMIN_DEFAULT_PASSWORD` z instalacji (działa, dopóki go nie zmieniłeś w panelu).

## Co robi `APP_MODE=local`

- Zamówienia: przy pierwszym starcie ustawia pobieranie co minutę (Konfiguracja → Synchronizacja,
  można zmienić). Sklepy WooCommerce są odpytywane o zamówienia **zmienione** od ostatniego razu
  (`modified_after`, WooCommerce 5.8+), więc dochodzą też zmiany statusu, np. opłacenie. Allegro
  działa jak zawsze (dziennik zdarzeń). Pierwszy przebieg bierze ostatnią dobę, starsze zamówienia
  ściągnij przez Zamówienia → Importuj.
- PayU: status linków do płatności sprawdzany co minutę (na hostingu co 10 min, bo jest webhook).
- Adres panelu (`APP_BASE_URL`) z `APP_URL`, jeśli w panelu nie ustawiono innego.
- Ekran integracji Woo nie każe dodawać webhooka.

## Allegro i inne logowania OAuth

Instalator ustawia `ALLEGRO_REDIRECT_URI=http://localhost:8081/auth_allegro_callback.php`.
Ten sam adres wpisz w aplikacji na https://apps.developer.allegro.pl/ (Allegro dopuszcza `localhost`).

## Przenoszenie pracy między online a komputerem (guzik w panelu)

System → **Przeniesienie danych** (tylko administrator). Masz CRM online i CRM na komputerze, a pracuje
zawsze tylko jeden z nich - drugi jest wstrzymany (tylko podgląd, bez pobierania zamówień, maili
i synchronizacji, webhooki są ignorowane). Dzięki temu żadne zamówienie nie zostanie obsłużone dwa razy.

1. Online: *Wygeneruj kod połączenia* i skopiuj go (pokazywany raz).
2. Komputer: wklej kod i *Zapisz kod*.
3. Komputer: **⬇️ Przenieś dane z online na ten komputer** - online się wstrzymuje, pakuje bazę i pliki,
   komputer je pobiera i zastępuje nimi swoje dane.
4. Gdy chcesz wrócić: komputer → **⬆️ Wyślij dane z tego komputera na online** - komputer się wstrzymuje,
   online przyjmuje dane i znowu pracuje.

Wszystko zaczyna się na komputerze, bo online nie ma jak się do niego połączyć. Ciężką pracę robi worker
po obu stronach (online: cron co minutę), więc każdy krok trwa chwilę. Po przeniesieniu obie strony
dociągają ze sklepów zamówienia zmienione w trakcie (webhooki trafiały wtedy do wstrzymanej strony).

Przenoszone są: cała baza (zamówienia, ustawienia, konta, kolejka zadań) i pliki ze `storage/` (załączniki,
dokumenty, grafiki) oraz klucz 2FA. Każda strona zachowuje swój kod, `.env`, adres panelu i częstotliwość
pobierania zamówień. Po przeniesieniu logujesz się kontami ze strony, z której przyszły dane.
Wtyczki doinstalowane w `integrations/` i `extensions/` trzeba mieć po obu stronach.

Wysłać dane na online można tylko wtedy, gdy online jest wstrzymany - inaczej nadpisałbyś nowsze dane.
Awaryjnie (np. zepsuty komputer) online ma guzik *Wznów pracę tutaj*.

Kod: `src/Services/Handover.php`, `public/handover.php`, test: `tests/handover_mysql.php`.

## Przeniesienie na inny serwer

System → Kopia zapasowa → *Pełna kopia systemu* przenosi wszystko (bazę, pliki, `.env`) na inny
serwer. Po przeniesieniu zmień `APP_MODE` i ustaw webhooki (na hostingu) albo je usuń (lokalnie).

## Ograniczenia (świadome)

- Komputer musi być włączony, żeby zamówienia i statusy się synchronizowały.
- Panel jest dostępny tylko na tym komputerze (domyślnie `127.0.0.1`). `CRM_BIND=0.0.0.0` w `.env`
  otwiera go w sieci lokalnej (np. dla telefonu przy pakowaniu) — nie wystawiaj go do internetu
  bez HTTPS.
- Zmiany w zamówieniu Woo bez zmiany statusu (np. edycja adresu) nie są ponownie pobierane,
  tak samo jak przy webhooku „Zamówienie utworzone”.
