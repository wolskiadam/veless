"""
Agent druku Veless - działa na komputerze z drukarką Zebra (USB, Windows).

Co robi:
  1. Co kilka sekund pyta serwer CRM: "czy jest coś do wydrukowania?".
  2. Jeśli tak - odbiera zadanie i wysyła je na drukarkę:
       * ZPL/EPL  - surowe komendy, prosto na drukarkę (bez okna dialogowego
                    Windows - dokładnie to, co robią BaseLinker/PrintNode);
       * PDF/JPG/PNG - agent sam renderuje stronę do bitmapy, zamienia ją na
                    czarno-białą grafikę i wysyła jako obrazek ZPL (^GFA).
                    Dzięki temu drukujesz na Zebrze etykiety kurierskie w PDF
                    i skany/zdjęcia, nie mając pliku ZPL.
  3. Potwierdza serwerowi wykonanie (albo zgłasza błąd, np. drukarka offline).

Wymaga: Python 3.9+ na Windows, pakiety `requests` i `pywin32`.
Do druku PDF/JPG dodatkowo `pypdfium2` i `Pillow` (bez nich ZPL działa normalnie,
a zadania graficzne kończą się czytelnym błędem).
Instalacja zależności:
    pip install requests pywin32 pypdfium2 Pillow

Pierwsze uruchomienie pyta o adres serwera, klucz API, drukarkę oraz parametry
etykiety (rozdzielczość i szerokość) - zapisuje odpowiedzi do pliku config.json
obok tego skryptu, więc pyta tylko raz.

Uruchomienie:
    python print_agent.py                     (normalna praca - pętla odpytywania)
    python print_agent.py --test-print         (wysyła testową etykietę na drukarkę)
    python print_agent.py --test-pdf plik.pdf  (sprawdza rasteryzację na własnym pliku)
    python print_agent.py --info               (wypisuje ustawienia i rozmiar wydruku)
    python print_agent.py --reconfigure        (pyta o ustawienia od nowa)
"""

from __future__ import annotations

import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import base64
from pathlib import Path
from typing import Optional

try:
    import requests
except ImportError:
    print("Brak pakietu 'requests'. Zainstaluj: pip install requests")
    sys.exit(1)

try:
    import win32print
except ImportError:
    print("Brak pakietu 'pywin32'. Zainstaluj: pip install pywin32")
    sys.exit(1)

# Katalog, w ktorym trzymamy config.json. Uwaga: gdy agent jest zbudowany jako
# jednoplikowy .exe (PyInstaller), __file__ wskazuje tymczasowy katalog rozpakowania,
# ktory Windows kasuje po zamknieciu programu - konfiguracja przepadlaby po kazdym
# uruchomieniu. Dlatego w trybie "frozen" zapisujemy obok samego .exe.
if getattr(sys, "frozen", False):
    BASE_DIR = Path(sys.executable).resolve().parent
else:
    BASE_DIR = Path(__file__).resolve().parent

CONFIG_PATH = BASE_DIR / "config.json"

# Domyślne parametry etykiety. 203 dpi to najpopularniejsza rozdzielczość Zebry
# (modele "203 dpi" / 8 dots per mm); 300 dpi mają modele z dopiskiem 300dpi.
# Szerokość 101.6 mm to standardowe 4 cale etykiety kurierskiej.
DEFAULT_DPI = 203
DEFAULT_LABEL_WIDTH_MM = 101.6
# Próg zamiany odcieni szarości na czerń/biel. Niżej = mniej czerni (jaśniejszy
# wydruk), wyżej = więcej czerni. 160 dobrze radzi sobie z etykietami kurierskimi,
# gdzie liczy się czytelny kod kreskowy, a nie wierność szarości.
DEFAULT_THRESHOLD = 160

# Rozdzielczość renderowania dokumentów na zwykłą drukarkę. 200 dpi to rozsądny
# kompromis: tekst wychodzi ostro, a strona A4 to ~1650x2340 punktów, czyli
# rozmiar, który bez problemu mieści się w pamięci i szybko idzie do sterownika.
A4_RENDER_DPI = 200

# Gdzie szukać przeglądarki do renderowania HTML. Edge jest na każdym Windows 10/11,
# Chrome bywa dodatkowo - wystarczy którakolwiek, bo obie to Chromium i przyjmują
# te same przełączniki wiersza poleceń.
BROWSER_CANDIDATES = (
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
)

# Wersja programu - meldowana serwerowi (panel Drukowanie pokazuje, czy stacja ma aktualną).
AGENT_VERSION = "1.1"

# Domyślny odstęp między pytaniami o wydruki. Od wersji 1.1 serwer podaje w odpowiedzi
# „next_poll” (sekundy): często, gdy ktoś pracuje w panelu, rzadko, gdy nikt nie pracuje
# albo wydruki są wstrzymane. Ustawia się to w panelu (Konfiguracja → Synchronizacja).
POLL_INTERVAL_SECONDS = 4
MIN_POLL_SECONDS = 1
MAX_POLL_SECONDS = 900
RETRY_AFTER_ERROR_SECONDS = 10

# Ostatni znany stan wstrzymania (żeby komunikat pisać tylko przy zmianie).
_paused_state = {"paused": False}


def load_config() -> Optional[dict]:
    if not CONFIG_PATH.exists():
        return None
    try:
        return json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return None


def save_config(config: dict) -> None:
    CONFIG_PATH.write_text(json.dumps(config, indent=2, ensure_ascii=False), encoding="utf-8")


def list_printers() -> list[str]:
    flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
    return [p[2] for p in win32print.EnumPrinters(flags)]


def prompt_setup(existing: Optional[dict] = None) -> dict:
    print("\n=== Konfiguracja agenta druku Veless ===")
    default_url = (existing or {}).get("base_url", "https://twojsklep.pl/crm/public")
    base_url = input(f"Adres CRM [{default_url}]: ").strip() or default_url
    base_url = base_url.rstrip("/")

    default_key = (existing or {}).get("api_key", "")
    prompt_key = f"Klucz API agenta [{'ustawiony' if default_key else 'brak'}]: "
    api_key = input(prompt_key).strip() or default_key
    if not api_key:
        print("Klucz API jest wymagany - znajdziesz go w panelu: Konfiguracja -> Drukowanie.")
        sys.exit(1)

    printers = list_printers()
    if not printers:
        print("Nie znaleziono żadnej zainstalowanej drukarki w Windows.")
        print("Zainstaluj sterownik drukarki Zebra (Zebra Setup Utilities) i uruchom ponownie.")
        sys.exit(1)

    print("\nDostępne drukarki:")
    for i, name in enumerate(printers, start=1):
        print(f"  {i}. {name}")

    default_printer = (existing or {}).get("printer_name")
    default_idx = printers.index(default_printer) + 1 if default_printer in printers else None
    prompt = f"Wybierz numer drukarki [{default_idx or '?'}]: "
    choice = input(prompt).strip()
    if choice == "" and default_idx:
        printer_name = printers[default_idx - 1]
    else:
        try:
            printer_name = printers[int(choice) - 1]
        except (ValueError, IndexError):
            print("Nieprawidłowy wybór.")
            sys.exit(1)

    # --- Druga drukarka: zwykła, do dokumentów A4 ---
    print("\nDrukarka do dokumentów A4 (spis produktów, karta zamówienia).")
    print("Zostaw puste (Enter), jeśli wszystko ma iść na Zebrę.")
    default_a4 = (existing or {}).get("printer_a4") or ""
    default_a4_idx = printers.index(default_a4) + 1 if default_a4 in printers else None
    a4_choice = input(f"Numer drukarki A4 z listy powyżej [{default_a4_idx or 'brak'}]: ").strip()
    if a4_choice == "":
        printer_a4 = default_a4
    else:
        try:
            printer_a4 = printers[int(a4_choice) - 1]
        except (ValueError, IndexError):
            print("Nieprawidłowy wybór - pomijam drukarkę A4.")
            printer_a4 = ""

    # --- Parametry etykiety (używane tylko przy druku PDF/JPG) ---
    print("\nParametry etykiety - potrzebne tylko przy druku PDF/obrazków.")
    print("Jeśli nie wiesz, zostaw wartości domyślne (Enter).")

    default_dpi = int((existing or {}).get("dpi", DEFAULT_DPI))
    dpi_raw = input(f"Rozdzielczość drukarki w dpi (203 albo 300) [{default_dpi}]: ").strip()
    try:
        dpi = int(dpi_raw) if dpi_raw else default_dpi
    except ValueError:
        dpi = default_dpi
    if dpi <= 0:
        dpi = DEFAULT_DPI

    default_width = float((existing or {}).get("label_width_mm", DEFAULT_LABEL_WIDTH_MM))
    width_raw = input(f"Szerokość etykiety w mm [{default_width:g}]: ").strip().replace(",", ".")
    try:
        label_width_mm = float(width_raw) if width_raw else default_width
    except ValueError:
        label_width_mm = default_width
    if label_width_mm <= 0:
        label_width_mm = DEFAULT_LABEL_WIDTH_MM

    config = {
        "base_url": base_url,
        "api_key": api_key,
        "printer_name": printer_name,
        "printer_a4": printer_a4,
        "dpi": dpi,
        "label_width_mm": label_width_mm,
        "threshold": int((existing or {}).get("threshold", DEFAULT_THRESHOLD)),
        "browser_path": (existing or {}).get("browser_path", ""),
    }
    save_config(config)
    print(f"\nZapisano konfigurację w {CONFIG_PATH}")
    print(f"Drukarka etykiet: {printer_name}")
    print(f"Drukarka A4: {printer_a4 or '(brak - dokumenty pójdą na Zebrę)'}")
    print(f"Etykieta: {label_width_mm:g} mm przy {dpi} dpi "
          f"({int(round(label_width_mm * dpi / 25.4))} punktów szerokości)\n")
    return config


def send_raw_to_printer(printer_name: str, data: bytes, doc_name: str = "Etykieta CRM") -> None:
    """Wysyła surowe dane (ZPL/EPL) na drukarkę z pominięciem GDI/okna druku -
    standardowy wzorzec Windows (RAW datatype) używany przez etykiety Zebra."""
    handle = win32print.OpenPrinter(printer_name)
    try:
        job_id = win32print.StartDocPrinter(handle, 1, (doc_name, None, "RAW"))
        try:
            win32print.StartPagePrinter(handle)
            win32print.WritePrinter(handle, data)
            win32print.EndPagePrinter(handle)
        finally:
            win32print.EndDocPrinter(handle)
    finally:
        win32print.ClosePrinter(handle)


# ============================================================
#  PDF / obrazek -> grafika ZPL
#
#  Zebra nie rozumie PDF-a ani JPG-a - przyjmuje komendy ZPL. Etykiety kurierskie
#  przychodzą jednak najczęściej jako PDF. Dlatego agent renderuje stronę do
#  bitmapy, zamienia ją na czystą czerń/biel (drukarka termiczna nie zna odcieni
#  szarości - punkt jest albo wypalony, albo nie) i wysyła jako obrazek ^GFA.
#  To ta sama droga, którą chodzą sterowniki Zebry, tylko liczona u nas.
# ============================================================

# Formaty zadań, które wymagają rasteryzacji (reszta idzie na drukarkę surowa).
RASTER_FORMATS = {"PDF", "JPG", "JPEG", "PNG", "GIF", "BMP", "TIF", "TIFF", "IMG", "IMAGE", "A4", "LBL"}


def _require_pillow():
    try:
        from PIL import Image
    except ImportError:
        raise RuntimeError(
            "Do druku PDF/obrazków potrzebny jest pakiet 'Pillow'. Zainstaluj: pip install Pillow"
        )
    return Image


def render_pdf_to_images(data: bytes, dpi: int) -> list:
    """Renderuje strony PDF-a do obrazków PIL wraz z ich fizyczną szerokością.

    @return lista par (obrazek, szerokość_strony_w_mm)
    """
    try:
        import pypdfium2 as pdfium
    except ImportError:
        raise RuntimeError(
            "Do druku PDF potrzebny jest pakiet 'pypdfium2'. Zainstaluj: pip install pypdfium2"
        )

    document = pdfium.PdfDocument(data)
    try:
        pages = []
        for index in range(len(document)):
            page = document[index]
            # Rozmiar strony w punktach PostScript (1/72 cala) - tak PDF zapisuje
            # swój prawdziwy rozmiar. Bierzemy go, żeby nie rozciągać etykiety
            # zaprojektowanej na konkretny format.
            width_pt = page.get_size()[0]
            # pypdfium renderuje w 72 dpi razy skala - stąd przelicznik.
            pages.append((page.render(scale=dpi / 72).to_pil(), width_pt * 25.4 / 72))
        return pages
    finally:
        document.close()


def target_width_mm(label_width_mm: float, source_width_mm: Optional[float]) -> float:
    """Szerokość, na jaką skalujemy wydruk.

    PDF niesie prawdziwy rozmiar strony (np. A6 = 105 mm), obrazek JPG/PNG nie
    niesie żadnego. Dlatego:
      * gdy znamy rozmiar źródła i mieści się na taśmie - drukujemy 1:1, w rozmiarze
        zaprojektowanym przez autora pliku (rozciąganie etykiety 50 mm na 100 mm
        taśmy psuło proporcje i rozmywało druk);
      * gdy źródło jest szersze niż taśma (np. etykieta kurierska na A4) albo
        rozmiaru nie znamy - dopasowujemy do szerokości taśmy.
    """
    if source_width_mm is None or source_width_mm <= 0:
        return label_width_mm
    return min(source_width_mm, label_width_mm)


def image_to_zpl(
    image,
    dpi: int,
    label_width_mm: float,
    threshold: int,
    source_width_mm: Optional[float] = None,
) -> bytes:
    """Zamienia obrazek PIL na jedną etykietę ZPL z grafiką ^GFA."""
    Image = _require_pillow()

    # Przezroczystość na biało - inaczej puste tło wyszłoby czarne i drukarka
    # wypaliłaby całą etykietę.
    if image.mode in ("RGBA", "LA", "P"):
        image = image.convert("RGBA")
        image = Image.alpha_composite(Image.new("RGBA", image.size, (255, 255, 255, 255)), image)

    width_dots = max(8, int(round(target_width_mm(label_width_mm, source_width_mm) * dpi / 25.4)))
    if image.width != width_dots:
        height_dots = max(1, int(round(image.height * width_dots / image.width)))
        image = image.resize((width_dots, height_dots), Image.LANCZOS)

    # Próg czerni zamiast automatycznego ditheringu: kody kreskowe i tekst wychodzą
    # ostre, a dithering rozsypałby je w siatkę punktów, której skaner nie odczyta.
    mono = image.convert("L").point(lambda pixel: 255 if pixel > threshold else 0, mode="1")

    row_bytes = (mono.width + 7) // 8
    packed = bytearray(mono.tobytes())
    # W PIL bit 1 = biel, w ZPL bit 1 = punkt czarny - stąd inwersja.
    for i in range(len(packed)):
        packed[i] ^= 0xFF

    total = len(packed)
    zpl = (
        "^XA"
        f"^PW{mono.width}"          # szerokość wydruku w punktach
        f"^LL{mono.height}"         # długość etykiety w punktach
        "^LH0,0"
        f"^FO0,0^GFA,{total},{total},{row_bytes},{packed.hex().upper()}^FS"
        "^XZ"
    )
    return zpl.encode("ascii")


def content_to_pages(content: bytes, fmt: str, dpi: int) -> list:
    """Treść zadania -> lista par (obrazek PIL, szerokość źródła w mm albo None).

    Szerokość znamy tylko dla PDF-a; obrazek rastrowy nie niesie informacji
    o rozmiarze w milimetrach, więc dla niego zwracamy None.
    """
    # Rozpoznajemy PDF po sygnaturze pliku, nie po deklarowanym formacie - serwer
    # może opisać zadanie jako "LBL" czy "A4", a i tak w środku jest PDF.
    if content[:5] == b"%PDF-" or fmt == "PDF":
        pages = render_pdf_to_images(content, dpi)
    else:
        Image = _require_pillow()
        pages = [(Image.open(io.BytesIO(content)), None)]

    if not pages:
        raise RuntimeError("Plik nie zawiera żadnej strony do wydrukowania.")
    return pages


def content_to_images(content: bytes, fmt: str, dpi: int) -> list:
    """Same obrazki, bez rozmiarów - do druku na zwykłej drukarce (GDI skaluje sam)."""
    return [image for image, _width_mm in content_to_pages(content, fmt, dpi)]


def content_to_zpl_pages(content: bytes, fmt: str, config: dict) -> list:
    """Zamienia treść zadania (PDF/JPG/PNG) na listę etykiet ZPL - jedna na stronę."""
    dpi = int(config.get("dpi", DEFAULT_DPI))
    label_width_mm = float(config.get("label_width_mm", DEFAULT_LABEL_WIDTH_MM))
    threshold = int(config.get("threshold", DEFAULT_THRESHOLD))

    return [
        image_to_zpl(image, dpi, label_width_mm, threshold, source_width_mm)
        for image, source_width_mm in content_to_pages(content, fmt, dpi)
    ]


# ============================================================
#  HTML -> PDF (renderowanie przeglądarką) i druk na zwykłej drukarce
#
#  Dokumenty A4 (spis produktów, karta zamówienia) serwer wysyła jako HTML -
#  ten sam, który widzisz w podglądzie. Zamieniamy go na PDF silnikiem Edge'a
#  w trybie headless: Edge jest na każdym Windows 10/11, więc nic nie trzeba
#  instalować, a wydruk wygląda dokładnie jak podgląd w przeglądarce.
# ============================================================

def find_browser(config: dict) -> str:
    configured = (config.get("browser_path") or "").strip()
    if configured:
        if Path(configured).is_file():
            return configured
        raise RuntimeError(f"Ścieżka z config.json nie istnieje: {configured}")

    for candidate in BROWSER_CANDIDATES:
        if Path(candidate).is_file():
            return candidate

    found = shutil.which("msedge") or shutil.which("chrome")
    if found:
        return found

    raise RuntimeError(
        "Nie znalazłem Microsoft Edge ani Chrome - są potrzebne do druku dokumentów A4. "
        'Jeśli masz je w nietypowym miejscu, dopisz "browser_path" w config.json.'
    )


def html_to_pdf(html: bytes, config: dict) -> bytes:
    browser = find_browser(config)

    with tempfile.TemporaryDirectory(prefix="pase-druk-") as workdir:
        workdir = Path(workdir)
        source = workdir / "dokument.html"
        output = workdir / "dokument.pdf"
        source.write_bytes(html)

        def run(headless_flag: str):
            command = [
                browser,
                headless_flag,
                "--disable-gpu",
                "--disable-extensions",
                # Własny profil w katalogu tymczasowym: bez tego Edge otwarty
                # przez użytkownika blokuje profil i renderowanie się nie uda.
                f"--user-data-dir={workdir / 'profil'}",
                "--no-pdf-header-footer",
                f"--print-to-pdf={output}",
                source.as_uri(),
            ]
            # CREATE_NO_WINDOW - żeby przy każdym wydruku nie mrugało czarne okno.
            flags = 0x08000000 if os.name == "nt" else 0
            return subprocess.run(command, capture_output=True, timeout=120, creationflags=flags)

        result = run("--headless=new")
        if not output.is_file():
            # Starsze wydania Edge'a nie znają "--headless=new".
            result = run("--headless")

        if not output.is_file():
            details = (result.stderr or b"").decode("utf-8", errors="replace").strip()
            raise RuntimeError(
                "Nie udało się wyrenderować dokumentu do PDF"
                + (f": {details[-300:]}" if details else " (przeglądarka nie zwróciła pliku).")
            )

        return output.read_bytes()


def print_images_on_windows_printer(printer_name: str, images: list, doc_name: str) -> None:
    """Drukuje obrazki na zwykłej drukarce przez sterownik Windows (GDI).

    Tu nie da się pójść drogą RAW jak przy Zebrze: zwykła drukarka oczekuje
    danych w swoim języku, który zna wyłącznie jej sterownik. Rysujemy więc
    stronę na kontekście urządzenia, a sterownik zamienia ją na to, co rozumie.
    """
    try:
        import win32ui
        import win32con
        from PIL import ImageWin
    except ImportError as exc:
        raise RuntimeError(f"Brak biblioteki potrzebnej do druku na drukarce A4: {exc}")

    device = win32ui.CreateDC()
    device.CreatePrinterDC(printer_name)

    # Rozmiar obszaru zadruku w punktach - już po odjęciu marginesów drukarki.
    printable_width = device.GetDeviceCaps(win32con.HORZRES)
    printable_height = device.GetDeviceCaps(win32con.VERTRES)

    device.StartDoc(doc_name)
    try:
        for image in images:
            device.StartPage()
            page = image.convert("RGB")

            # Wpasowanie w stronę z zachowaniem proporcji i wyśrodkowaniem.
            scale = min(printable_width / page.width, printable_height / page.height)
            width = max(1, int(page.width * scale))
            height = max(1, int(page.height * scale))
            left = (printable_width - width) // 2
            top = (printable_height - height) // 2

            ImageWin.Dib(page).draw(device.GetHandleOutput(), (left, top, left + width, top + height))
            device.EndPage()
    finally:
        device.EndDoc()
        device.DeleteDC()


def needs_rasterizing(content: bytes, fmt: str) -> bool:
    if fmt in ("ZPL", "EPL"):
        return False
    return fmt in RASTER_FORMATS or content[:5] == b"%PDF-"


def print_job(config: dict, content: bytes, job_format: str, target: str, filename: str) -> None:
    """Kieruje jedno zadanie na właściwą drukarkę właściwą drogą."""
    fmt = (job_format or "").upper()

    # HTML zamieniamy na PDF raz, niezależnie od drukarki - dzięki temu ten sam
    # dokument wygląda tak samo na Zebrze i na A4.
    if fmt == "HTML" or content[:15].lstrip()[:9].lower() == b"<!doctype":
        content = html_to_pdf(content, config)
        fmt = "PDF"

    if target == "a4":
        printer_a4 = (config.get("printer_a4") or "").strip()
        if not printer_a4:
            raise RuntimeError(
                "Zadanie na drukarkę A4, ale żadna nie jest ustawiona. "
                "Uruchom: print_agent.py --reconfigure"
            )
        if fmt in ("ZPL", "EPL"):
            raise RuntimeError("Surowe komendy ZPL/EPL nie nadają się na zwykłą drukarkę.")

        images = content_to_images(content, fmt, A4_RENDER_DPI)
        print_images_on_windows_printer(printer_a4, images, filename)
        return

    # Zebra: PDF/obrazek rasteryzujemy do ZPL, surowe komendy idą bez zmian.
    if needs_rasterizing(content, fmt):
        pages = content_to_zpl_pages(content, fmt, config)
        if len(pages) > 1:
            print(f"       {len(pages)} stron do wydrukowania.")
        for number, page in enumerate(pages, start=1):
            page_name = filename if len(pages) == 1 else f"{filename} ({number}/{len(pages)})"
            send_raw_to_printer(config["printer_name"], page, doc_name=page_name)
    else:
        send_raw_to_printer(config["printer_name"], content, doc_name=filename)


def _next_poll(data: dict) -> Optional[int]:
    """Odstęp podany przez serwer (sekundy) albo None, gdy starszy serwer go nie podaje."""
    try:
        value = int(data.get("next_poll"))
    except (TypeError, ValueError):
        return None
    return max(MIN_POLL_SECONDS, min(MAX_POLL_SECONDS, value))


def poll_once(config: dict) -> Optional[int]:
    """Jedno pytanie o wydruk. Zwraca, za ile sekund zapytać ponownie (None = domyślnie)."""
    poll_url = f"{config['base_url']}/print_agent_poll.php"
    ack_url = f"{config['base_url']}/print_agent_ack.php"

    # Przy okazji odpytywania meldujemy panelowi ustawienia tej stacji. Dzięki temu
    # podgląd wydruku w panelu liczy rozmiar na podstawie TEJ drukarki, a nie
    # wartości domyślnych - i od razu widać, gdy ktoś ma inną taśmę niż zakładamy.
    resp = requests.get(
        poll_url,
        params={
            "key":      config["api_key"],
            "dpi":      int(config.get("dpi", DEFAULT_DPI)),
            "label_mm": float(config.get("label_width_mm", DEFAULT_LABEL_WIDTH_MM)),
            "a4":       "1" if (config.get("printer_a4") or "").strip() else "0",
            "v":        AGENT_VERSION,
        },
        timeout=15,
    )
    # Uwaga: NIE wołamy raise_for_status() przed odczytem JSON-a - serwer zawsze
    # zwraca czytelny błąd w treści (np. 401 przy złym kluczu), a raise_for_status()
    # rzuciłby wyjątek zanim zdążylibyśmy go pokazać, chowając prawdziwy powód
    # za ogólnym "błąd połączenia".
    try:
        data = resp.json()
    except ValueError:
        resp.raise_for_status()
        raise

    if "error" in data:
        print(f"[BŁĄD] Serwer odmówił: {data['error']} - sprawdź klucz API w konfiguracji.")
        return None

    paused = bool(data.get("paused"))
    if paused != _paused_state["paused"]:
        _paused_state["paused"] = paused
        print("[PAUZA] Wydruki wstrzymane w panelu - czekam." if paused else "[WZNOWIONO] Wydruki znów są wydawane.")

    wait = _next_poll(data)
    job = data.get("job")
    if job is None:
        return wait  # nic do druku - normalna sytuacja, sprawdzimy ponownie za chwilę

    job_id = job["id"]
    filename = job.get("filename") or f"job-{job_id}"
    # Starszy serwer pola "target" nie zna - wtedy wszystko idzie na Zebrę, jak dotąd.
    target = (job.get("target") or "zebra").lower()
    where = "drukarka A4" if target == "a4" else "Zebra"
    print(f"[DRUK] Zadanie #{job_id} ({job['format']}, {filename}) -> {where}...")

    try:
        content = base64.b64decode(job["content_b64"])
        print_job(config, content, job.get("format") or "", target, filename)

        requests.post(ack_url, data={"key": config["api_key"], "job_id": job_id, "status": "done"}, timeout=15)
        print(f"[OK] Zadanie #{job_id} wydrukowane.")
    except Exception as exc:  # noqa: BLE001 - agent ma żyć dalej mimo błędu jednego zadania
        error_message = str(exc)
        print(f"[BŁĄD] Zadanie #{job_id} nieudane: {error_message}")
        try:
            requests.post(
                ack_url,
                data={"key": config["api_key"], "job_id": job_id, "status": "failed", "error": error_message},
                timeout=15,
            )
        except requests.RequestException:
            pass  # brak połączenia - zadanie i tak wróci do kolejki po stronie serwera
    return wait


def run_loop(config: dict) -> None:
    print(f"Agent druku Veless uruchomiony. Drukarka etykiet: {config['printer_name']}")
    if (config.get("printer_a4") or "").strip():
        print(f"Drukarka A4: {config['printer_a4']}")
    else:
        print("Drukarka A4: nieustawiona - dokumenty A4 będą odrzucane.")
    print(f"Serwer: {config['base_url']}")
    print("Naciśnij Ctrl+C, aby zatrzymać.\n")
    print(f"Wersja agenta: {AGENT_VERSION}")
    while True:
        try:
            wait = poll_once(config)
            time.sleep(wait if wait is not None else POLL_INTERVAL_SECONDS)
        except KeyboardInterrupt:
            print("\nZatrzymano.")
            break
        except requests.RequestException as exc:
            print(f"[BŁĄD POŁĄCZENIA] {exc} - ponawiam za {RETRY_AFTER_ERROR_SECONDS}s.")
            time.sleep(RETRY_AFTER_ERROR_SECONDS)


def test_print(config: dict) -> None:
    # Minimalna, uniwersalna etykieta testowa ZPL - drukuje się na każdej Zebrze.
    test_zpl = (
        "^XA"
        "^FO50,50^A0N,40,40^FDTest Veless^FS"
        "^FO50,110^A0N,30,30^FDDrukarka: OK^FS"
        "^XZ"
    ).encode("ascii")
    send_raw_to_printer(config["printer_name"], test_zpl, doc_name="Test CRM")
    print("Wysłano etykietę testową. Sprawdź drukarkę.")


def print_size_report(config: dict, path: Optional[str] = None) -> None:
    """Wypisuje ustawienia i - dla wskazanego pliku - dokładny rozmiar wydruku.

    To pierwsze miejsce do sprawdzenia, gdy etykieta wychodzi za mała lub za duża:
    pokazuje, co agent policzył, zamiast kazać zgadywać.
    """
    dpi = int(config.get("dpi", DEFAULT_DPI))
    label_width_mm = float(config.get("label_width_mm", DEFAULT_LABEL_WIDTH_MM))

    print("=== Ustawienia agenta ===")
    print(f"  plik konfiguracyjny : {CONFIG_PATH}")
    print(f"  drukarka etykiet    : {config.get('printer_name') or '(brak)'}")
    print(f"  drukarka A4         : {config.get('printer_a4') or '(brak)'}")
    print(f"  rozdzielczość (dpi) : {dpi}")
    print(f"  szerokość etykiety  : {label_width_mm:g} mm "
          f"= {label_width_mm / 25.4:.2f} cala "
          f"= {int(round(label_width_mm * dpi / 25.4))} punktów")
    print(f"  próg czerni         : {config.get('threshold', DEFAULT_THRESHOLD)}")

    if path is None:
        print("\nAby sprawdzić konkretny plik:  print_agent.py --test-pdf etykieta.pdf")
        return

    source = Path(path)
    if not source.is_file():
        print(f"\nNie znaleziono pliku: {source}")
        sys.exit(1)

    content = source.read_bytes()
    fmt = source.suffix.lstrip(".").upper()

    print(f"\n=== Plik: {source.name} ===")
    for number, (image, source_width_mm) in enumerate(content_to_pages(content, fmt, dpi), start=1):
        target_mm = target_width_mm(label_width_mm, source_width_mm)
        width_dots = max(8, int(round(target_mm * dpi / 25.4)))
        height_dots = max(1, int(round(image.height * width_dots / image.width)))
        height_mm = height_dots * 25.4 / dpi

        if source_width_mm is not None:
            print(f"  strona {number}: rozmiar w pliku {source_width_mm:.1f} mm szerokości")
            if source_width_mm > label_width_mm:
                print(f"     szersza niż taśma ({label_width_mm:g} mm) - zmniejszam do szerokości taśmy")
            else:
                print("     mieści się na taśmie - drukuję w oryginalnym rozmiarze (1:1)")
        else:
            print(f"  strona {number}: obrazek bez rozmiaru fizycznego - skaluję do szerokości taśmy")

        print(f"     WYDRUK: {target_mm:.1f} x {height_mm:.1f} mm "
              f"({width_dots} x {height_dots} punktów przy {dpi} dpi)")

    print("\nZmierz linijką wydruk i porównaj z liczbami powyżej.")
    print("  - wydruk ok. 1,5x mniejszy niż podano  -> drukarka ma 300 dpi, a w konfiguracji jest 203")
    print("  - wydruk dokładnie 2x mniejszy         -> 'label_width_mm' jest o połowę za małe")
    print("  - wydruk zgodny z liczbami, ale nie wypełnia taśmy -> tak jest zaprojektowany plik")
    print(f"Poprawki wpisz w {CONFIG_PATH} albo uruchom: print_agent.py --reconfigure")


def test_pdf(config: dict, path: str) -> None:
    """Drukuje wskazany plik PDF/JPG - do sprawdzenia rasteryzacji na własnym pliku."""
    source = Path(path)
    if not source.is_file():
        print(f"Nie znaleziono pliku: {source}")
        sys.exit(1)

    print_size_report(config, path)

    content = source.read_bytes()
    pages = content_to_zpl_pages(content, source.suffix.lstrip(".").upper(), config)

    for number, page in enumerate(pages, start=1):
        send_raw_to_printer(config["printer_name"], page, doc_name=f"{source.name} ({number})")
    print(f"\nWysłano na drukarkę ({len(pages)} stron(y)).")
    print("Jeśli wydruk jest za jasny lub za ciemny, zmień \"threshold\" "
          f"(teraz {config.get('threshold', DEFAULT_THRESHOLD)}, zakres 0-255).")


def main() -> None:
    args = sys.argv[1:]
    config = load_config()

    # Konfigurację uzupełniamy także wtedy, gdy config.json jest niepełny - tak
    # wygląda plik pobrany z panelu razem z programem: ma już adres i klucz, ale
    # drukarki musi wskazać user przy pierwszym uruchomieniu.
    needs_setup = config is None or not (config.get("printer_name") or "").strip()

    if "--reconfigure" in args or needs_setup:
        config = prompt_setup(config)

    if "--info" in args:
        print_size_report(config)
        return

    if "--test-print" in args:
        test_print(config)
        return

    if "--test-pdf" in args:
        index = args.index("--test-pdf")
        if index + 1 >= len(args):
            print("Podaj ścieżkę do pliku: python print_agent.py --test-pdf etykieta.pdf")
            sys.exit(1)
        test_pdf(config, args[index + 1])
        return

    run_loop(config)


if __name__ == "__main__":
    main()
