# Zapachy (rozszerzenie)

Olejki zapachowe z numerem miejsca w magazynie i nutami zapachowymi, przypisane do produktów. Kod pochodzi z wtyczki
WordPress „Aroma Helper” (aroma-category) ze sklepu, przeniesiony do CRM. Bez kont i bez połączeń z zewnątrz
(poza jednorazowym pobraniem strony katalogu przy imporcie, jeśli ktoś poda jej adres).

## Co robi

- **Magazyn → Zapachy**: lista olejków z numerem miejsca (np. Sweet Caramel = 18), nazwą i innymi nazwami, nutami głowy,
  serca i bazy. Szukanie po nazwie (bez polskich znaków), numerze (dokładnie: „18” nie znajdzie 118), nucie i producencie,
  filtr po nucie (kliknięcie nuty) i kategorii nut, najczęstsze nuty, archiwum. Na telefonie lista kart.
- **Karta zapachu**: nuty z kolorami kategorii, charakter zapachu (procent kategorii), opis, producent i kod, cena za kg,
  maks. stężenie, UFI, uwagi, produkty z tym zapachem (odłączanie i dodawanie z wyszukiwarki produktów).
- **Numer miejsca jest unikalny** wśród aktywnych zapachów: drugi zapach z numerem 18 nie zapisze się.
- **Nuty** (słownik jak „Menadżer Nut”): nuta z kategorią (słodka, cytrusowa…), masowe dodawanie `nuta, kategoria`.
  Nowe nuty z zapachów same trafiają do słownika (bez kategorii).
- **Przypisz do produktów**: propozycje dla produktów bez zapachu, rozpoznane po atrybucie „Zapach” produktu albo po nazwie
  (najdłuższa pasująca nazwa zapachu; dwie różne o tej samej długości = brak propozycji). Zaznacz i przypisz.

## Zapach przy produkcie

- **Karta produktu w Magazynie**: sekcja „🌸 Zapach” z numerem i nutami, wybór zapachów (kilka = zestaw) i propozycja
  „Pasuje po nazwie/atrybucie” jednym kliknięciem.
- **Lista Magazynu**: plakietka `#18 Sweet Caramel` pod nazwą, a szukajka znajduje produkty po numerze zapachu, nazwie
  zapachu i nucie (np. „tonka”).
- **Pakowanie**: plakietka `#18 Sweet Caramel` przy pozycji (obok SKU), żeby od razu wiedzieć, gdzie stoi olejek.
- **Karta zamówienia**: sekcja „🌸 Zapachy do zrobienia” - pozycje z numerami.
- **Lista produkcji** (lista zamówień → „Lista produkcji”): `Nr zapachu: 18 · Sweet Caramel` przy produkcie.

Skąd zapach pozycji zamówienia (po kolei): przypisany do produktu o tym SKU → wariant zamówienia („Zapach: Sweet Caramel”
albo sam numer) → olejek z receptury wyrobu Produkcji o tym SKU (gdy olejek jest połączony z zapachem) → nazwa produktu.
Ustawienie „Zapach bez przypisania: pokazuj tylko przypisane do produktu” wyłącza zgadywanie z wariantu i nazwy.

## Wtyczka Produkcja

Zapach można połączyć z olejkiem z Produkcji (materiał z kategorii Zapach) w edycji zapachu albo przyciskiem
„Połącz po nazwie” na stronie importu (nazwa olejku musi zawierać nazwę zapachu, np. „Olejek Sweet Caramel”).
Wtedy lista i karta zapachu pokazują stan olejku (na czerwono poniżej minimum), a na liście produkcji przy recepturze
wyrobu pojawia się numer miejsca. Stan, dostawy i zużycie olejku prowadzi Produkcja - Zapachy go nie liczą (stan
z Aroma Helper nie jest importowany, żeby nie było dwóch stanów tego samego olejku).

## Import z Aroma Helper

**Konfiguracja → Wtyczki → Zapachy → ⚙ → Import z Aroma Helper** (albo przycisk „Import” na liście):

- **Kopia bazy (SQL)** z phpMyAdmin: tabele `wp_aroma_fragrances` i `wp_aroma_notes` (dowolny prefiks). Przenosi numer
  miejsca, nazwy handlowe (pierwsza = nazwa, reszta = inne nazwy), producenta i kod, nuty, opis handlowy, uwagi, cenę za gram,
  maks. stężenie i UFI (6/8/10%) oraz słownik nut z kategoriami.
- **CSV** (eksport tych tabel albo własny plik z Excela): kolumny po nagłówkach, po polsku (`numer;nazwa;nuty głowy;nuty serca;
  nuty bazy;opis;producent;kod producenta;cena za gram;uwagi`) albo jak w bazie. Separator `;` `,` albo tabulator,
  UTF-8 albo Windows-1250. Plik z kolumnami `nuta;kategoria` uzupełnia słownik nut.
- **Strona katalogu** (shortcode `[aroma_zapachy]`): adres strony albo zapisany plik .html - numer, nazwa, nuty i opis.
  Adres musi być publiczny (nie sieć lokalna serwera).

Ponowny import niczego nie dubluje: zapach z bazy WordPress rozpoznajemy po jego id, inne po nazwie. Puste pole w pliku nie
kasuje danych w CRM. Numer zajęty przez inny zapach - zapach wchodzi bez numeru, z uwagą w komunikacie.

## Dane

Tabele `ext_zap_fragrances` (zapachy), `ext_zap_notes` (słownik nut), `ext_zap_links` (zapach ↔ produkt, `products.id`).

## Uprawnienia

Podgląd (rola „podgląd”) widzi wszystko. Dodawanie, zmiany, przypisywanie i import wymagają roli edytora.
