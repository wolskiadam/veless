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
  z których dostaw poszły. Numer partii z wzoru w ustawieniach, podgląd na żywo, dekoder numeru, szkice do zatwierdzenia, kosz (30 dni),
  etykiety do druku. Brak materiału blokuje zapis, chyba że zaznaczy się „zapisz mimo braków”.
- **Partia bez wyrobu** (jak w Rejestrze Partii): w „Nowa partia” zakładka „Bez wyrobu” - nazwa produktu, przydatność
  w miesiącach i ręcznie wybrany wosk, zapach, knot i pojemnik. Numer partii liczy się z ich kodów. Ilość materiału jest
  opcjonalna (na całą partię): wpisana zdejmuje materiał ze stanu i liczy koszt, pusta zostawia go tylko w numerze i historii.
  Taka partia nie dokłada sztuk do produktu CRM (nie ma SKU).
- **Testy spalania**: pomiary (jeziorko, płomień, kopcenie, tunelowanie), zdjęcie, powiązanie z partią i wyrobem.
- **Numer partii przy zamówieniu**: osoba pakująca wpisuje numer partii przy każdej pozycji na ekranie pakowania
  (podpowiedzi z ostatnich partii tego SKU); to samo pole jest w karcie zamówienia. Na karcie partii widać,
  do których zamówień poszła.
- **Lista produkcji**: na liście zamówień zaznaczone → „Lista produkcji” (wydruk A4 z kratkami). Przy produkcie,
  którego SKU ma wyrób, wtyczka dopisuje recepturę (knot, zapach, pojemnik, wosk, barwnik, dodatki) z ilością
  na wszystkie sztuki, a pod listą „Materiały razem”. Zapach z wariantu zamówienia wygrywa z recepturą.
  Pole „Waga (g)” wyrobu pokazuje się w kolumnie „Waga”.

## Etykiety CLP

Etykietę CLP projektuje się poza CRM (Canva, od dostawcy olejku) i wgrywa gotową do produktu:

- **Karta produktu w Magazynie → „Etykieta CLP” → Wgraj etykietę**: plik PNG, JPG, WEBP albo PDF (pierwsza strona zamienia się
  w przeglądarce na PNG 600 dpi przez pdf.js z jsdelivr; rozmiar w mm bierze się z PDF). Szerokość w mm (wysokość z proporcji),
  potem na podglądzie przeciąga się prostokąt na **numer partii** i opcjonalnie na **datę produkcji** i **„najlepiej do”**.
  Ostrzeżenie, gdy plik ma mniej niż 200 dpi.
- **Druk**: z karty partii (przycisk „Etykiety”: produkt po SKU wyrobu albo wpisanym SKU; bez pliku zostaje prosta etykieta partii)
  albo z karty produktu (numer partii z podpowiedzi). Arkusz A4 w prawdziwym rozmiarze, „Pomiń” zostawia miejsca po zużytych
  etykietach. Tekst wpisuje się w prostokąt (czcionka 80% wysokości, zmniejszana, gdy numer się nie mieści).
- **Warianty**: wariant bez własnej etykiety używa etykiety innego wariantu tego samego produktu z Woo (wspólny `woo_product_id`);
  własna etykieta wariantu ma pierwszeństwo. Wystarczy wgrać jedną do dowolnego wariantu.
- **EAN**: pola „Kod kreskowy EAN” (EAN-13/EAN-8 rysowany jako SVG) i „EAN (tekst)” wypełnia EAN produktu albo wariantu,
  który się drukuje - przy wspólnej etykiecie każdy wariant ma swój kod (wtedy EAN nie może być w samym pliku).
- **Wiele partii na jednym arkuszu**: na liście Partie zaznacza się kratki i „Drukuj etykiety zaznaczonych” - jeden arkusz A4
  z etykietą i numerem każdej partii (liczba sztuk do poprawienia przy każdej, domyślnie ilość z partii; partie bez etykiety są wypisane).
- **Produkcja → Etykiety CLP**: lista produktów z etykietą i wybór produktu do dodania.
- Treść CLP (piktogramy, zwroty, UFI) jest w pliku - CRM jej nie układa ani nie sprawdza. Pliki: `storage/uploads/produkcja/etykiety`,
  tabela `ext_prod_labels` (miejsca jako ułamki obrazka w `boxes`). Wersja 5 bazy usuwa wzory z wersji 4 (`ext_prod_clp`).

## Numer partii

Domyślny wzór: `{DC}{MC}{YC}{PR}{FC}{WC}{SC}{WK}{QTY}{PC}`, np. `AJLXPKS102ABB1500A` = 9 listopada 2026, wyrób PK, forma/pojemnik S1,
wosk 02, zapach AB, knot bawełniany, 1500 szt., kolejna litera A.

- `{PR}` kod wyrobu (pole „Kod do numeru partii” na wyrobie, inny dla każdego wyrobu; puste = pierwsze litery nazwy).
  W partii bez wyrobu: pole „Kod do numeru” albo pierwsze litery nazwy produktu.
- `{FC}` kod formy / pojemnika (kod materiału z kategorii Pojemnik z receptury albo wybrany w partii bez wyrobu).
- `{QTY}` ilość: 3 cyfry, od 1000 sztuk więcej (dekoder sam rozpoznaje długość).
- Numer jest zawsze unikalny (sprawdzany także z koszem): gdy wyszedłby taki sam (np. ta sama zmiana dwa razy jednego dnia),
  dostaje końcówkę `-2`, `-3`.
- Stary wzór Rejestru Partii `{DC}{QTY}{MC}{YC}{WC}{SC}{WK}{PC}` (ilość do 999, bez wyrobu i formy) przy aktualizacji
  zmienia się na nowy domyślny; własny wzór zostaje. Stare numery dekoder dalej odczytuje.

## Stan produktów CRM

Zatwierdzona partia dodaje sztuki do produktu CRM o tym samym SKU. Ustawienie „Partia dokłada sztuki do”:
stan faktyczny (`actual_stock`, domyślnie; pomijany, gdy produkt nie był jeszcze liczony), stan CRM (`pase_stock`,
wysyłany do sklepu przez kolejkę `woo.stock.push`), oba albo nigdzie. Usunięcie lub poprawienie partii cofa materiały
i sztuki. Sprzedaż zdejmuje stan jak dotąd, wtyczka niczego przy sprzedaży nie zmienia.

## Import z Rejestru Partii

**Konfiguracja → Wtyczki → Produkcja → ⚙ → Import i eksport danych**: plik kopii zapasowej JSON z Rejestru Partii. Woski, zapachy, knoty i pojemniki
stają się materiałami (z kodami do numeru partii), pracownicy i zmiany słownikami, dostawy historią dostaw
(bez stanu), a rekordy partiami z wyrobami. Ponowny import tego samego pliku niczego nie dubluje.
Rejestr pozwalał na ten sam numer kilka razy: kolejne partie o tym samym numerze dostają końcówkę -2, -3 (w kolejności
z kopii, stary numer zostaje w uwagach). Partia bez nazwy wyrobu dostaje nazwę z zapachu i pojemnika (np. „Wanilia · Szklany 100 g”)
i wchodzi jako partia bez wyrobu.

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

## Lista produkcji

Lista produkcji (zamówienia → „Lista produkcji”) dopisuje do pozycji recepturę wyrobu o tym samym SKU (knot, zapach, pojemnik, wosk
z ilościami). Gdy takiego wyrobu nie ma, podpowiada z historii: knot, pojemnik, wosk i zapach z ostatniej partii tego samego
zapachu (z „Nr zapachu” wtyczki Zapachy, wariantu albo nazwy produktu) albo o tej samej nazwie wyrobu, z numerem tej partii.
Gdy pozycja i partia mają rozmiar („100 g”), musi się zgadzać.
Rodzaj knota z nazwy lub wariantu („Drewniany Knot”, „knot: bawełniany”) też musi się zgadzać, a partia z zapisanym knotem
wygrywa z nowszą partią bez knota.
