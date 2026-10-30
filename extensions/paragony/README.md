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
- **Podgląd paragonu**: duże zdjęcie (kliknięcie otwiera je na całym ekranie, kolejne kliknięcie powiększa do pełnego
  rozmiaru, ✕ albo Esc zamyka), dane, pozycje z paragonu jako podpunkty, „Pobierz plik”, „Popraw” i „Usuń”.
- **⬇ Pobierz miesiąc (ZIP)**: zdjęcia z miesiąca nazwane `2026-10-05_Castorama_123,45.jpg` i
  `zestawienie-2026-10.csv` (średnik, UTF-8, otwiera się w Excelu) z sumą - do wysłania księgowej.

W ustawieniach wtyczki (Konfiguracja → Wtyczki → Paragony → ⚙) można wpisać NIP firmy: pokazuje się nad formularzem,
żeby mieć go pod ręką przy kasie.

## Odczyt paragonu przez ChatGPT (OpenAI)

Po wpisaniu w ustawieniach wtyczki **klucza API OpenAI** (platform.openai.com → API keys; konto API rozlicza się osobno
od abonamentu ChatGPT) formularz sam odczytuje dane ze zdjęcia: zaraz po zrobieniu zdjęcia nowego paragonu wypełnia
sprzedawcę, NIP sprzedawcy, datę, kwotę, VAT, formę płatności (gotówka / karta / BLIK / przelew), numer i pozycje
z paragonu (każda w osobnej linii, z ilością i ceną). Przycisk **✨ Odczytaj z paragonu**
odczytuje ponownie (np. przy poprawianiu). Nic nie zapisuje się samo: dane trzeba sprawdzić i kliknąć „Zapisz paragon”.
Gdy w ustawieniach jest NIP firmy, odczyt ostrzega, jeśli na paragonie nie ma NIP-u nabywcy albo jest inny.

- Do OpenAI idzie tylko zdjęcie paragonu (zmniejszone w przeglądarce, JPEG), przez `ReceiptReader` (Chat Completions,
  odpowiedź według schematu JSON). PDF i HEIC nie są odczytywane.
- Klucz jest polem `secret`: rdzeń trzyma go zaszyfrowanego, formularz go nie pokazuje.
- Model domyślnie `gpt-4o-mini` (tani, z obsługą zdjęć), do zmiany w ustawieniach.
- Bez klucza przycisku nie ma, a wtyczka nie łączy się z niczym.

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
