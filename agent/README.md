# Agent druku Veless (skrypt)

Mały program instalowany na komputerze z drukarką Zebra (USB, Windows). Odpytuje
panel CRM co kilka sekund i drukuje etykiety automatycznie — bez ręcznego
pobierania pliku i bez okna dialogowego druku (jak w BaseLinkerze/PrintNode).

Drukuje dwa rodzaje zadań:

- **ZPL/EPL** — surowe komendy Zebry, idą na drukarkę bez zmian.
- **PDF, JPG, PNG** — agent renderuje stronę do bitmapy, zamienia ją na czerń/biel
  i wysyła jako grafikę ZPL (`^GFA`). Dzięki temu drukujesz etykiety kurierskie
  w PDF i zwykłe obrazki, nie mając pliku ZPL. Wielostronicowy PDF = kolejne etykiety.
- **HTML** (dokumenty A4: spis produktów, karta zamówienia) — agent renderuje stronę
  do PDF-a silnikiem Microsoft Edge w trybie headless, a potem drukuje ją na
  **zwykłej drukarce** przez sterownik Windows. Edge jest na każdym Windows 10/11,
  więc nic nie trzeba doinstalowywać.

Każde zadanie niesie informację, na którą drukarkę ma trafić (`zebra` albo `a4`),
więc jedna instancja agenta obsługuje obie drukarki naraz.

## Instalacja — sposób najprostszy (zalecany)

1. Skopiuj folder `agent/` na komputer z drukarką (np. do `C:\PaseAgent`).
2. Wygeneruj klucz API w panelu CRM: **Konfiguracja → Drukowanie** → sekcja
   „Własny agent druku" → „Wygeneruj klucz".
3. Kliknij dwa razy w **`install.bat`** i odpowiedz na pytania (adres panelu, klucz
   API, wybór drukarki z listy).

`install.bat` robi całą resztę sam: sprawdza Pythona i w razie potrzeby instaluje go
przez `winget`, dociąga biblioteki `requests` i `pywin32`, uruchamia konfigurację,
dodaje agenta do autostartu Windows i na koniec wysyła etykietę testową.

> Jeśli Pythona nie było na komputerze, skrypt zainstaluje go i poprosi o **ponowne
> uruchomienie `install.bat`** — to normalne, Windows musi odświeżyć PATH.

Drukarka Zebra musi być wcześniej zainstalowana w Windows ze sterownikiem producenta
(Zebra Setup Utilities) — agent tylko wysyła do niej dane, sam sterownika nie instaluje.

## Instalacja jako jeden plik `.exe` (bez Pythona u klienta)

Przydatne, gdy agent ma trafić na wiele komputerów albo do kogoś, komu nie chcemy
instalować Pythona. Plik `.exe` **trzeba zbudować na Windowsie** — PyInstaller nie
potrafi kompilować z macOS/Linuksa. Dwie drogi:

**A. GitHub Actions (bez własnego Windowsa).** W repo jest gotowy workflow
`.github/workflows/build-agent.yml`. Po wypchnięciu repozytorium na GitHub:
zakładka **Actions** → „Build Veless print agent (script)" → **Run workflow**. Po ~2 minutach
`PaseAgent.exe` czeka do pobrania w artefaktach builda. Tag `agent-v1.0` dodatkowo
dokleja plik do Releases.

**B. Lokalnie na dowolnym Windowsie.** Raz, w folderze `agent/`:
```
pip install requests pywin32 pyinstaller
pyinstaller --onefile --console --name PaseAgent --hidden-import win32print print_agent.py
```
Gotowy plik pojawi się w `dist\PaseAgent.exe`.

Użycie `.exe` jest identyczne jak skryptu, tylko bez słowa `python`:
```
PaseAgent.exe                  (normalna praca)
PaseAgent.exe --test-print     (etykieta testowa)
PaseAgent.exe --reconfigure    (zmiana ustawień)
```
Konfiguracja (`config.json`) zapisuje się obok `.exe`, więc trzymaj go w stałym
folderze, a nie w Pobranych.

## Instalacja ręczna (krok po kroku)

Gdyby `install.bat` z jakiegoś powodu nie zadziałał:

1. Zainstaluj [Python 3.9+](https://www.python.org/downloads/) — przy instalacji
   zaznacz „Add Python to PATH".
2. W folderze `agent/` otwórz wiersz poleceń i wykonaj:
   ```
   pip install requests pywin32
   python print_agent.py
   ```
3. Sprawdź wydruk: `python print_agent.py --test-print`
4. Sprawdź rasteryzację na własnym pliku: `python print_agent.py --test-pdf etykieta.pdf`

## Codzienne działanie

Po instalacji agent startuje razem z Windows. Ręcznie uruchamia się go dwuklikiem
w `start_agent.bat` (albo `PaseAgent.exe`) — okno musi zostać otwarte.

W panelu CRM, przy przesyłce, wybierz format **ZPL** lub **EPL**, kliknij **🖨➜**
(„wyślij do agenta") zamiast zwykłego „Drukuj" — etykieta wydrukuje się sama.

W panelu (**Konfiguracja → Drukowanie**) widać status agenta („aktywny" / „nie
odpytuje od X minut") oraz historię ostatnich zadań druku.

## Parametry etykiety (druk PDF/obrazków)

Pytane przy instalacji, zapisane w `config.json` obok skryptu:

| Pole | Co oznacza | Domyślnie |
|---|---|---|
| `dpi` | rozdzielczość drukarki — 203 (większość Zebr) albo 300 | `203` |
| `label_width_mm` | szerokość etykiety; obraz jest skalowany do niej z zachowaniem proporcji | `101.6` (4 cale) |
| `threshold` | próg zamiany szarości na czerń (0–255): niżej = jaśniejszy wydruk | `160` |
| `printer_a4` | zwykła drukarka do dokumentów A4; puste = wszystko idzie na Zebrę | (pyta przy instalacji) |
| `browser_path` | ścieżka do `msedge.exe`/`chrome.exe`, gdy agent sam jej nie znajdzie | (auto) |

Te trzy wartości dotyczą **wyłącznie** druku PDF/obrazków — ZPL idzie na drukarkę
bez zmian, bo o rozmiarze decydują komendy w samym pliku.

Dlaczego próg, a nie dithering: drukarka termiczna nie zna odcieni szarości — punkt
jest albo wypalony, albo nie. Dithering rozsypałby kod kreskowy w siatkę punktów,
której skaner nie odczyta, więc lepiej wypada twarde progowanie.

## Dokumenty A4 (spis produktów, karta zamówienia)

W menu **Drukuj / Eksportuj** przy każdym dokumencie są dwa przyciski: **🖨➜ A4**
i **🖨➜ Zebra**. Pierwszy drukuje na zwykłej drukarce wskazanej w `printer_a4`,
drugi wpycha tę samą stronę na taśmę etykiet (zmniejszoną do jej szerokości —
rzadko ma to sens przy tabelach, ale bywa przydatne).

Skąd bierze się wydruk: serwer wysyła agentowi **ten sam HTML**, który widzisz
w podglądzie. Agent renderuje go lokalnie Edge'em do PDF-a i drukuje. Dlatego
wydruk automatyczny wygląda identycznie jak ten z podglądu, a serwer nie
potrzebuje żadnej biblioteki PDF (ważne przy shared hostingu).

## Rozwiązywanie problemów

- **„nie znaleziono Python; uruchom bez argumentów, aby zainstalować ze sklepu"** —
  to zaślepka Microsoft Store podstawiona przez Windows pod komendę `python`
  (istnieje, ale niczego nie uruchamia). Zainstaluj prawdziwego Pythona z
  python.org z zaznaczonym „Add python.exe to PATH", ewentualnie wyłącz zaślepkę:
  Ustawienia → Aplikacje → Zaawansowane ustawienia aplikacji → Aliasy wykonywania
  aplikacji → wyłącz `python.exe` i `python3.exe`. Skrypty wolą launcher `py`,
  który instaluje się do System32 i działa niezależnie od PATH.
- **„Nie znaleziono żadnej zainstalowanej drukarki"** — zainstaluj sterownik Zebry
  (Zebra Setup Utilities) i sprawdź, czy drukarka widnieje w Windows → Drukarki i skanery.
- **„Serwer odmówił: unauthorized"** — klucz API się nie zgadza; wygeneruj nowy
  w panelu i uruchom `python print_agent.py --reconfigure` (lub `PaseAgent.exe --reconfigure`).
- **Agent pyta o konfigurację przy każdym starcie** — `config.json` nie ma gdzie się
  zapisać; przenieś folder/`.exe` w miejsce, gdzie użytkownik ma prawo zapisu
  (np. `C:\PaseAgent`, nie `C:\Program Files`).
- **Antywirus blokuje `.exe`** — typowe dla plików z PyInstallera bez podpisu
  cyfrowego; dodaj wyjątek albo użyj wersji ze skryptem Pythona.
- **Wydruk PDF wychodzi za ciemny / za jasny** — podnieś albo obniż `threshold`
  w `config.json` (domyślnie 160). Sprawdzaj przez `--test-pdf plik.pdf`, bez
  czekania na zadanie z panelu.
- **Wydruk PDF jest za mały lub ucięty** — `label_width_mm` musi odpowiadać
  faktycznej szerokości etykiety w drukarce, a `dpi` modelowi (203 vs 300).
  Przy złym `dpi` wydruk wyjdzie dokładnie 1,5× za duży albo za mały.
- **„Do druku PDF potrzebny jest pakiet pypdfium2"** — zainstaluj brakujące
  biblioteki: `pip install pypdfium2 Pillow` (albo uruchom ponownie `install.bat`).
- **A4 na drukarce etykiet** — da się, ale cała strona zostanie zmniejszona do
  szerokości etykiety i tekst bywa nieczytelny. A4 lepiej drukować na zwykłej drukarce.
- **„Zadanie na drukarkę A4, ale żadna nie jest ustawiona"** — uruchom
  `print_agent.py --reconfigure` i wskaż zwykłą drukarkę (pytanie po wyborze Zebry).
- **„Nie znalazłem Microsoft Edge ani Chrome"** — agent szuka ich w standardowych
  lokalizacjach; jeśli masz je gdzie indziej, dopisz `"browser_path"` w `config.json`.
- **Dokument A4 wychodzi pusty albo obcięty** — sprawdź podgląd w panelu (klik
  w nazwę szablonu). Agent renderuje dokładnie to samo, więc błąd jest w szablonie,
  nie w druku.
- **Etykieta drukuje się nieczytelnie / przesunięta** — to zwykle kwestia ustawień
  samej drukarki (kalibracja, rozmiar etykiety w sterowniku), nie agenta — surowe ZPL
  idzie na drukarkę bez zmian.
- Program pisze na bieżąco, co robi (`[DRUK]`, `[OK]`, `[BŁĄD]`) — jeśli coś nie
  działa, to pierwsze miejsce do sprawdzenia.
