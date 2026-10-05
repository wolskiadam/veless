# Strona Veless (open source)

Prosta strona promocyjna z pobieraniem kodu po podaniu adresu e-mail. Nie jest częścią CRM
i nie jest potrzebna do działania CRM.

## Pliki

- `index.html` – strona: cechy, funkcje, integracje, screeny, wymagania, formularz, FAQ.
- `prywatnosc.html` – polityka prywatności (uzupełnij dane administratora w nawiasach kwadratowych).
- `download.php` – zapis adresu (e-mail, zgoda, pole-pułapka na boty, limit prób z IP) i link do pobrania
  ważny `link_hours` godzin, podpisany kluczem `secret`.
- `unsubscribe.php` – wypis z informacji o aktualizacjach (podpisany link).
- `export.php?key=…` – lista adresów do CSV (jeden wiersz na adres, bez wypisanych, z linkiem do wypisu).
- `data/` – zapisy (`subscribers.csv`, `unsubscribed.csv`, `rate.json`), zablokowane w `.htaccess`.
- `assets/screens/` – zrzuty z wersji demonstracyjnej z fikcyjnymi danymi.

## Uruchomienie

1. Wgraj zawartość katalogu `site/` na hosting z PHP 8.1+ (Apache; przy nginx zablokuj dostęp do `data/`, `config.php` i `lib.php`).
2. Skopiuj `config.example.php` jako `config.php` i ustaw `secret`, `export_key` oraz `download_url`
   (np. archiwum ZIP z GitHuba) albo `download_file` (ścieżka do ZIP-a na serwerze).
3. Upewnij się, że PHP może pisać do `data/`.
4. Uzupełnij `prywatnosc.html`.

Lokalnie: `php -S 127.0.0.1:8080 -t site`.

Test: `php tests/site_download_http.php`.
