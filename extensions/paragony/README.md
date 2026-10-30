# Paragony (rozszerzenie)

Zdjęcia paragonów z NIP-em (faktur uproszczonych) z zakupów firmy, żeby było widać, co, gdzie i kiedy kupiono.
Bez kont i bez połączeń z zewnątrz.

## Co robi

- **Zamówienia → 🧾 Paragony**: paragony z wybranego roku pogrupowane po miesiącach (od najnowszego), przy każdym
  miesiącu liczba paragonów i suma (oraz suma VAT, jeśli był wpisany). Wyszukiwanie po sklepie, NIP-ie (z kreskami
  albo bez), numerze paragonu i opisie.
- **+ Dodaj paragon**: zdjęcie (na telefonie przycisk **📷 Zrób zdjęcie** od razu otwiera aparat, obok „Z galerii”;
  na komputerze zdjęcie albo PDF), data
  zakupu, sprzedawca, NIP sprzedawcy, kwota brutto, opcjonalnie VAT, forma płatności, numer paragonu i „co kupiono”.
  Sprzedawca podpowiada się z poprzednich paragonów i uzupełnia jego NIP.
- **Podgląd paragonu**: duże zdjęcie (kliknięcie otwiera pełny rozmiar), dane, „Pobierz plik”, „Popraw” i „Usuń”.
- **⬇ Pobierz miesiąc (ZIP)**: zdjęcia z miesiąca nazwane `2026-10-05_Castorama_123,45.jpg` i
  `zestawienie-2026-10.csv` (średnik, UTF-8, otwiera się w Excelu) z sumą - do wysłania księgowej.

W ustawieniach wtyczki (Konfiguracja → Wtyczki → Paragony → ⚙) można wpisać NIP firmy: pokazuje się nad formularzem,
żeby mieć go pod ręką przy kasie.

## Uprawnienia

Podgląd (rola „podgląd”) widzi listę, zdjęcia i pobiera ZIP. Dodawanie, poprawianie i usuwanie wymaga roli
z prawem edycji.

## Pliki i dane

- Tabela `ext_paragony_receipts` (tworzy się sama przy pierwszym otwarciu).
- Zdjęcia w `storage/uploads/paragony/` pod losowymi nazwami. Katalog `storage` jest zablokowany dla WWW, zdjęcie
  oddaje tylko strona `paragony.plik` po zalogowaniu. Typ pliku jest sprawdzany po zawartości (JPG, PNG, WEBP, HEIC, PDF),
  najwyżej 15 MB.
- Duże zdjęcie z aparatu przeglądarka zmniejsza przed wysłaniem (najdłuższy bok 2000 px, JPEG), więc paragon zajmuje
  zwykle kilkaset kB i szybko wysyła się z telefonu. Bez JavaScriptu idzie oryginał.
- Pełna kopia systemu (pakiet przeniesienia) zabiera `storage/uploads`, więc także zdjęcia paragonów.

Usunięcie katalogu wtyczki ukrywa funkcję; tabela i zdjęcia zostają.

Test: `php tests/paragony_http.php`.
