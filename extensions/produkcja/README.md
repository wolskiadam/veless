# Produkcja (rozszerzenie)

Produkcja świec: materiały i magazyn, wyroby z recepturą i kosztem, partie z numerem partii, testy spalania
i numer partii przy zamówieniu. Kod numerów partii pochodzi z wtyczki WordPress „Rejestr Partii”
(batch-number-generator-pro), przeniesiony do CRM. Bez kont i bez połączeń z zewnątrz.

## Co robi

- **Produkcja → Pulpit**: liczba materiałów, wyrobów i partii, co się kończy (poniżej minimum), terminy ważności
  dostaw, co da się zrobić z obecnego stanu i ostatnie partie. Na pustej wtyczce: pierwsze kroki.
- **Materiały i magazyn**: wosk, zapachy, knoty, pojemniki, opakowania i inne (jednostki g, ml, szt.). Każdy materiał
  ma dostawy (ilość, cena, dostawca, numer partii dostawcy, ważność); stan to suma tego, co zostało z dostaw,
  a cena jednostkowa to średnia ważona z pozostałego zapasu. Historia ruchów, kosz dostaw (30 dni), inwentaryzacja
  i import CSV (`nazwa;kategoria;jednostka;kod;stan;cena;minimum;dostawca;ważne do`, kg i l przeliczane na g i ml).
- **Wyroby**: receptura (materiał + ilość na sztukę), koszt materiałów i robocizny, cena, marża, próg rentowności,
  symulator ceny, ile sztuk da się zrobić i powiązanie z produktem CRM po SKU.
- **Partie**: nowa partia zdejmuje materiały z magazynu (najpierw wskazana dostawa, potem najstarsze) i zapisuje,
  z których dostaw poszły. Numer partii z wzoru w ustawieniach (domyślnie jak w Rejestrze Partii:
  `{DC}{QTY}{MC}{YC}{WC}{SC}{WK}{PC}`), podgląd na żywo, dekoder numeru, szkice do zatwierdzenia, kosz (30 dni),
  etykiety do druku. Brak materiału blokuje zapis, chyba że zaznaczy się „zapisz mimo braków”.
- **Partia bez wyrobu** (jak w Rejestrze Partii): w „Nowa partia” zakładka „Bez wyrobu” - nazwa produktu, przydatność
  w miesiącach i ręcznie wybrany wosk, zapach, knot i pojemnik. Numer partii liczy się z ich kodów. Ilość materiału jest
  opcjonalna (na całą partię): wpisana zdejmuje materiał ze stanu i liczy koszt, pusta zostawia go tylko w numerze i historii.
  Taka partia nie dokłada sztuk do produktu CRM (nie ma SKU).
- **Testy spalania**: pomiary (jeziorko, płomień, kopcenie, tunelowanie), zdjęcie, powiązanie z partią i wyrobem.
- **Numer partii przy zamówieniu**: osoba pakująca wpisuje numer partii przy każdej pozycji na ekranie pakowania
  (podpowiedzi z ostatnich partii tego SKU); to samo pole jest w karcie zamówienia. Na karcie partii widać,
  do których zamówień poszła.

## Stan produktów CRM

Zatwierdzona partia dodaje sztuki do produktu CRM o tym samym SKU. Ustawienie „Partia dokłada sztuki do”:
stan faktyczny (`actual_stock`, domyślnie; pomijany, gdy produkt nie był jeszcze liczony), stan CRM (`pase_stock`,
wysyłany do sklepu przez kolejkę `woo.stock.push`), oba albo nigdzie. Usunięcie lub poprawienie partii cofa materiały
i sztuki. Sprzedaż zdejmuje stan jak dotąd, wtyczka niczego przy sprzedaży nie zmienia.

## Import z Rejestru Partii

**Konfiguracja → Wtyczki → Produkcja → ⚙ → Import i eksport danych**: plik kopii zapasowej JSON z Rejestru Partii. Woski, zapachy, knoty i pojemniki
stają się materiałami (z kodami do numeru partii), pracownicy i zmiany słownikami, dostawy historią dostaw
(bez stanu), a rekordy partiami z wyrobami. Ponowny import tego samego pliku niczego nie dubluje.

## Eksport i import CSV

- **Partie → ⬇ Eksport CSV**: partie z widoku (z filtrami: szukanie, wyrób, daty, wyceny, kosz) do pliku CSV dla Excela
  (średnik, UTF-8): numer, data, produkt, SKU, ilość, ważne do, osoba, zmiana, zużyte materiały z numerami partii dostawców,
  koszty, zamówienia, uwagi.
- **Import partii** (⚙ ustawienia wtyczki → Import i eksport danych): stare partie z CSV - z Excela, z eksportu „Partie CSV” Rejestru Partii albo z eksportu tej wtyczki.
  Kolumny rozpoznawane po nagłówkach (numer partii/kod, data produkcji/data, produkt, SKU, ilość, ważne do/przydatność,
  osoba/pracownik, zmiana, wosk, zapach, knot, pojemnik, koszt, uwagi), separator `;` `,` albo tabulator, daty 2025-03-14 albo
  14.03.2025, kodowanie UTF-8 albo Windows-1250. Wymagana data. Partie wchodzą jako historia (bez zdejmowania materiałów i bez
  dokładania sztuk), numer, który już jest, jest pomijany; brak numeru daje `IMP-RRRRMMDD-NN`.
- **Materiały → ⬇ Eksport CSV**: materiały ze stanem w formacie importu materiałów.

## Ustawienia

Konfiguracja → Wtyczki → Produkcja → ⚙: przyciski „Import i eksport danych” i „Osoby i zmiany”, wzór numeru partii, gdzie dodać sztuki, stawka godzinowa, koszty stałe
miesięczne (do progu rentowności), rozmiar etykiety i dane producenta na etykiecie.

## Uprawnienia

Podgląd (rola „podgląd”) widzi wszystko i wpisuje numer partii przy pakowaniu. Dodawanie i zmiany wymagają roli
edytora.

## Licencja

`License::active()` zwraca na razie zawsze `true`. Docelowo wtyczka będzie wymagała kodu licencyjnego; sprawdzenie
trafi tam.

## Dane

Tabele `ext_prod_*` (MySQL i SQLite), zdjęcia testów w `storage/uploads/produkcja/`. Test: `php tests/produkcja_http.php`.
