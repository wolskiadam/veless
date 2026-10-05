"""Zamiana treści zadań na to, co rozumie drukarka (przeniesione 1:1 z dotychczasowego agenta).

* PDF/JPG/PNG na Zebrę -> bitmapa czarno-biała -> grafika ZPL (^GFA): Zebra nie zna PDF-a.
* HTML (dokumenty A4) -> PDF silnikiem Edge/Chrome w trybie headless - wygląda jak podgląd w panelu.
"""

from __future__ import annotations

import io
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Optional

from .config import DEFAULT_DPI, DEFAULT_LABEL_WIDTH_MM, DEFAULT_THRESHOLD

RASTER_FORMATS = {"PDF", "JPG", "JPEG", "PNG", "GIF", "BMP", "TIF", "TIFF", "IMG", "IMAGE", "A4", "LBL"}

# Rozdzielczość renderowania dokumentów na zwykłą drukarkę (Windows/GDI).
A4_RENDER_DPI = 200

if sys.platform == "win32":
    BROWSER_CANDIDATES = (
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    )
elif sys.platform == "darwin":
    BROWSER_CANDIDATES = (
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
        "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
        "/Applications/Chromium.app/Contents/MacOS/Chromium",
        "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser",
    )
else:
    BROWSER_CANDIDATES = ("/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser")


def _require_pillow():
    try:
        from PIL import Image
    except ImportError as exc:
        raise RuntimeError("Brak biblioteki Pillow potrzebnej do druku PDF/obrazków.") from exc
    return Image


def render_pdf_to_images(data: bytes, dpi: int) -> list:
    """Strony PDF -> lista par (obrazek PIL, szerokość strony w mm)."""
    try:
        import pypdfium2 as pdfium
    except ImportError as exc:
        raise RuntimeError("Brak biblioteki pypdfium2 potrzebnej do druku PDF.") from exc
    document = pdfium.PdfDocument(data)
    try:
        pages = []
        for index in range(len(document)):
            page = document[index]
            width_pt = page.get_size()[0]
            pages.append((page.render(scale=dpi / 72).to_pil(), width_pt * 25.4 / 72))
        return pages
    finally:
        document.close()


def target_width_mm(label_width_mm: float, source_width_mm: Optional[float]) -> float:
    """PDF drukujemy 1:1, jeśli mieści się na taśmie; szerszy albo obrazek bez rozmiaru - do szerokości taśmy."""
    if source_width_mm is None or source_width_mm <= 0:
        return label_width_mm
    return min(source_width_mm, label_width_mm)


def image_to_zpl(image, dpi: int, label_width_mm: float, threshold: int, source_width_mm: Optional[float] = None) -> bytes:
    """Obrazek PIL -> jedna etykieta ZPL z grafiką ^GFA."""
    Image = _require_pillow()
    if image.mode in ("RGBA", "LA", "P"):
        image = image.convert("RGBA")
        image = Image.alpha_composite(Image.new("RGBA", image.size, (255, 255, 255, 255)), image)
    width_dots = max(8, int(round(target_width_mm(label_width_mm, source_width_mm) * dpi / 25.4)))
    if image.width != width_dots:
        height_dots = max(1, int(round(image.height * width_dots / image.width)))
        image = image.resize((width_dots, height_dots), Image.LANCZOS)
    # Próg zamiast ditheringu: ostre kody kreskowe.
    mono = image.convert("L").point(lambda pixel: 255 if pixel > threshold else 0, mode="1")
    row_bytes = (mono.width + 7) // 8
    packed = bytearray(mono.tobytes())
    for i in range(len(packed)):
        packed[i] ^= 0xFF  # PIL: 1 = biel, ZPL: 1 = czarny punkt
    total = len(packed)
    zpl = ("^XA" f"^PW{mono.width}" f"^LL{mono.height}" "^LH0,0"
           f"^FO0,0^GFA,{total},{total},{row_bytes},{packed.hex().upper()}^FS" "^XZ")
    return zpl.encode("ascii")


def content_to_pages(content: bytes, fmt: str, dpi: int) -> list:
    """Treść -> lista (obrazek, szerokość w mm albo None). PDF rozpoznajemy po sygnaturze."""
    if content[:5] == b"%PDF-" or fmt == "PDF":
        pages = render_pdf_to_images(content, dpi)
    else:
        Image = _require_pillow()
        pages = [(Image.open(io.BytesIO(content)), None)]
    if not pages:
        raise RuntimeError("Plik nie zawiera żadnej strony do wydrukowania.")
    return pages


def content_to_zpl_pages(content: bytes, fmt: str, config: dict) -> list:
    dpi = int(config.get("dpi", DEFAULT_DPI))
    width = float(config.get("label_width_mm", DEFAULT_LABEL_WIDTH_MM))
    threshold = int(config.get("threshold", DEFAULT_THRESHOLD))
    return [image_to_zpl(img, dpi, width, threshold, src_mm) for img, src_mm in content_to_pages(content, fmt, dpi)]


def needs_rasterizing(content: bytes, fmt: str) -> bool:
    if fmt in ("ZPL", "EPL"):
        return False
    return fmt in RASTER_FORMATS or content[:5] == b"%PDF-"


def is_html(content: bytes, fmt: str) -> bool:
    return fmt == "HTML" or content[:15].lstrip()[:9].lower() == b"<!doctype"


def find_browser(config: dict) -> str:
    configured = (config.get("browser_path") or "").strip()
    if configured:
        if Path(configured).is_file():
            return configured
        raise RuntimeError(f"Wskazana przeglądarka nie istnieje: {configured}")
    for candidate in BROWSER_CANDIDATES:
        if Path(candidate).is_file():
            return candidate
    found = shutil.which("msedge") or shutil.which("chrome") or shutil.which("google-chrome") or shutil.which("chromium")
    if found:
        return found
    raise RuntimeError("Do druku dokumentów A4 potrzebna jest przeglądarka Microsoft Edge albo Google Chrome - "
                       "zainstaluj jedną z nich albo wskaż ją w ustawieniach programu.")


def html_to_pdf(html: bytes, config: dict) -> bytes:
    browser = find_browser(config)
    with tempfile.TemporaryDirectory(prefix="crm-druk-") as workdir:
        workdir = Path(workdir)
        source, output = workdir / "dokument.html", workdir / "dokument.pdf"
        source.write_bytes(html)

        def run(headless_flag: str):
            command = [browser, headless_flag, "--disable-gpu", "--disable-extensions",
                       f"--user-data-dir={workdir / 'profil'}", "--no-pdf-header-footer",
                       f"--print-to-pdf={output}", source.as_uri()]
            flags = 0x08000000 if os.name == "nt" else 0  # CREATE_NO_WINDOW
            return subprocess.run(command, capture_output=True, timeout=120, creationflags=flags)

        result = run("--headless=new")
        if not output.is_file():
            result = run("--headless")
        if not output.is_file():
            details = (result.stderr or b"").decode("utf-8", errors="replace").strip()
            raise RuntimeError("Nie udało się wyrenderować dokumentu do PDF" + (f": {details[-300:]}" if details else "."))
        return output.read_bytes()
