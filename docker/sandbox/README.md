# Sandbox wtyczek

Wtyczka CRM to zwykły kod PHP z pełnym dostępem do bazy, kluczy API i plików. Wewnątrz działającego
CRM nie da się jej odizolować, więc sandbox sprawdza ją **na osobnej, fikcyjnej instalacji**, zanim
trafi do katalogu albo na produkcję.

```
sh docker/sandbox/run.sh wtyczka.zip                # albo katalog wtyczki
sh docker/sandbox/run.sh integrations/smsapi raport/ # raport w wybranym katalogu
```

Potrzebny jest Docker z `docker compose`. Obraz CRM (`veless:local`, ten sam co w trybie lokalnym)
zbuduje się sam, jeśli go nie ma. Przebieg trwa około minuty. Wynik: `sandbox-raport/raport.md`
(do czytania) i `raport.json`.

| Kod wyjścia | Wynik | Znaczenie |
|---|---|---|
| 1 | 🔴 ODRZUCONA | wtyczka zrobiła albo zawiera coś, czego nie wolno - nie instalować |
| 0 | 🟡 DO PRZEGLĄDU | nic blokującego, ale są rzeczy, które człowiek musi obejrzeć (sieć, zapis plików, sekrety) |
| 0 | 🟢 BEZ ZASTRZEŻEŃ | nic nie znaleziono |
| 2 | — | błąd samego sandboxu (brak Dockera, zła paczka) |

„Do przeglądu” i „bez zastrzeżeń” to **nie jest zgoda**. Wtyczkę do katalogu dopuszcza człowiek po
przeczytaniu kodu i raportu.

## Co sprawdza

1. **Skan kodu** (`scan.php`, bez uruchamiania). Blokuje: polecenia systemowe (`exec`, `` `...` ``),
   `eval`, dołączanie kodu z sieci, nazwy funkcji sklejane z tekstu, PHP ukryty w obrazku, `.htaccess`
   w paczce, zapis do katalogów z kodem CRM, a w rozszerzeniu także sięganie po klucze
   (`SecretStore`, `.env`, tabele kont integracji), bo rozszerzenie z założenia nie ma kont ani API.
   Do przeglądu: sieć, zapis plików, zaciemnianie (`base64_decode`, długie zakodowane ciągi), zapytania
   do tabel z sekretami. Lista adresów zapisanych w kodzie trafia do raportu.
2. **Uruchomienie** (`compose.yml`): CRM w Dockerze na fikcyjnych danych, w sieci bez wyjścia do internetu.
   - Tokeny Allegro, klucze wFirma/PayU/SMSAPI/WooCommerce, hasło SMTP, hasła z `.env`, klucz szyfrowania
     i dane klientów to unikalne **kanarki** (`SBXCANARY-...`). Integracja dostaje konto testowe
     z własnymi kanarkami w polach z kluczami.
   - **Fałszywy internet** (`sink.php`): DNS odpowiada na każdą nazwę adresem sinka, a sink odbiera
     każde połączenie - HTTP, HTTPS (odszyfrowane certyfikatem z CA sandboxu, wgranym do kontenera CRM),
     SMTP i inne porty - i zapisuje, co przyszło. Odpowiada „200 {}”, żeby wtyczka szła dalej.
   - **Dwa przebiegi**: najpierw z wyłączoną wtyczką, potem z włączoną. Oba robią to samo: logowanie,
     ~25 stron panelu (lista i karty zamówień, statystyki, pakowanie, ustawienia), strony dodane przez
     rozszerzenie, nowe zamówienie z automatyzacjami, zmianę statusu, przebieg workera, a dla integracji
     `testConnection()` i wywołania jej zdolności (pobranie zamówień, SMS, faktura, kurier).
3. **Raport** (`report.php`): tylko to, czego nie było bez wtyczki.
   - Sieć: kanarek-sekret wysłany gdziekolwiek → blokuje (wyjątek: token Allegro do domen allegro.pl i
     allegrosandbox.pl, czyli zwykłe zapytanie API - tylko informacja); własny klucz integracji na adres spoza jej
     kodu → blokuje; dane klientów z rozszerzenia albo na obcy adres → blokuje. Kanarki są szukane też
     w postaci zakodowanej (URL, base64, hex, gzip).
   - Pliki (SHA-256 przed i po): nowe albo zmienione pliki z kodem CRM → blokuje; kanarek zapisany
     jawnie do pliku → blokuje; zapis poza katalogiem CRM → do przeglądu.
   - Baza (dziennik zapytań MariaDB): `DROP`/`TRUNCATE`/`GRANT`/`ALTER` na tabelach rdzenia → blokuje,
     rozszerzenie czytające tabele z sekretami → blokuje.
   - Procesy zostawione w tle → blokuje. Strony, które przestają działać, i błąd workera → do przeglądu.

## Czego nie wykryje

- Kodu uśpionego: działającego dopiero w określony dzień, przy konkretnym zamówieniu, po N wywołaniach.
- Połączenia prosto na adres IP (bez DNS) - nie dojdzie nigdzie, ale też nie zostanie zapisane.
  Skan kodu oznacza adresy IP w kodzie do przeglądu.
- HTTPS z własną listą zaufanych CA albo przypiętym certyfikatem - widać tylko nazwę serwera
  (raport: „połączenie, którego sandbox nie mógł podejrzeć”).
- Wtyczki, która rozpozna sandbox i zachowa się grzecznie.

Dlatego sandbox jest sitem przed przeglądem kodu, a nie jego zamiennikiem.

## GitHub Actions

`.github/workflows/plugin-sandbox.yml` uruchamia sandbox dla każdej wtyczki zmienionej w PR
(`integrations/`, `extensions/`, `examples/extensions/`) i raz na każdy przebieg sprawdza sam sandbox:
złośliwa wtyczka testowa `tests/fixtures/sandbox/zlodziej` musi zostać odrzucona. Raporty są
w podsumowaniu przebiegu i w artefakcie `raporty-sandboxu`. Ręcznie: Actions → „Sandbox wtyczek” →
Run workflow, ścieżka do wtyczki w repozytorium.

Sandbox, rdzeń CRM i obraz są brane z gałęzi docelowej, nie z PR - PR nie może „poprawić” sandboxu
i od razu dostać czystego raportu. PR z forka ma tylko odczyt repozytorium i nie dostaje sekretów.

## Pliki

| Plik | Co robi |
|---|---|
| `run.sh` | całość: kopia CRM z wtyczką, kontenery, dwa przebiegi, raport, sprzątanie |
| `compose.yml` | sieć bez internetu, MariaDB z dziennikiem zapytań, sink, CRM |
| `scan.php` | skan kodu (działa też sam: `php docker/sandbox/scan.php <katalog>`) |
| `sink.php` | fałszywy internet: DNS, HTTP/HTTPS, SMTP, inne porty |
| `probe.php` | w kontenerze CRM: dane testowe i kanarki, przejście po panelu, wywołania wtyczki |
| `report.php` | porównanie przebiegów, werdykt, `raport.md` / `raport.json` |

Testy: `tests/plugin_sandbox_scan.php` (skaner, w zwykłym zestawie testów) i przebieg w Dockerze w workflow.
Katalog `docker/` nie jest wgrywany na serwer.
