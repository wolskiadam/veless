# Jak pomóc w rozwoju Veless

Dziękujemy, że chcesz ulepszyć Veless! Każdy może zgłosić błąd, zaproponować funkcję
albo przesłać poprawkę kodu. Zmiany trafiają do projektu przez **pull request** –
opiekun projektu je przegląda i scala.

## Zgłoszenie błędu lub pomysłu

Otwórz [Issue](../../issues/new/choose) i wybierz szablon „Błąd” albo „Pomysł”.
Opisz, co robisz, czego się spodziewasz i co się dzieje. Nie wklejaj danych klientów,
kluczy API ani haseł – zamień je na przykładowe.

Luki bezpieczeństwa zgłaszaj prywatnie, nie w Issues – patrz [SECURITY.md](SECURITY.md).

## Przesłanie zmiany w kodzie

1. Zrób fork repozytorium i utwórz w nim gałąź, np. `poprawka-faktur`.
2. Uruchom system lokalnie – najprościej w Dockerze ([docker/README.md](docker/README.md)) albo
   w wersji demo z fikcyjnymi danymi (`DEMO_MODE=1` i `php cli/demo_reset.php`, patrz README).
3. Wprowadź zmianę i dopisz test w `tests/` (wzoruj się na istniejących plikach).
   Nowy test dopisz też do `.github/workflows/sales-tests.yml`.
4. Uruchom testy, które dotyczą zmiany, np. `php tests/security.php`.
   Testy z bazą wymagają lokalnego MySQL/MariaDB:
   `CRM_TEST_MYSQL=1 CRM_TEST_MYSQL_USER=… CRM_TEST_MYSQL_PASSWORD=… php tests/sales_mysql.php`.
5. Otwórz pull request do gałęzi `main` i wypełnij szablon. Testy uruchomią się automatycznie.

## Zasady w kodzie

- PHP 8.1+, bez Composera – autoloader jest w `config/config.php`, przestrzeń nazw `Pase\`.
- Baza zmienia się tylko przez `src/Support/Migrator.php` (idempotentnie, bez kasowania danych).
- Nowe integracje (kurierzy, marketplace'y, płatności) twórz jako **wtyczki** w `integrations/`.
  Wtyczki przechodzą automatyczny test bezpieczeństwa w piaskownicy (`plugin-sandbox.yml`).
- Interfejs i komunikaty są po polsku; teksty do tłumaczenia trafiają do `lang/`.
- Nie dodawaj zewnętrznych usług ani wysyłania danych bez wyraźnej potrzeby i opisu w PR.
- Jedna zmiana = jeden pull request. Mniejsze PR-y szybciej trafiają do projektu.

## Licencja

Przesyłając zmianę, zgadzasz się, że zostanie udostępniona na licencji projektu
[AGPL-3.0](LICENSE).
