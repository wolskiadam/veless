"""Drukarki systemu: lista, druk surowy (ZPL/EPL) i druk dokumentów.

Windows: sterownik Windows (win32print - RAW dla Zebry, GDI dla A4).
macOS (i Linux): system CUPS - `lpstat` / `lp` (surowy druk: `-o raw`).
"""

from __future__ import annotations

import subprocess
import sys
import tempfile
from pathlib import Path

from .rendering import A4_RENDER_DPI, content_to_pages

IS_WINDOWS = sys.platform == "win32"


# ---------------------------------------------------------------- lista drukarek

def list_printers() -> list[str]:
    if IS_WINDOWS:
        import win32print
        flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        return sorted({p[2] for p in win32print.EnumPrinters(flags)})
    return _cups_printers()


def _cups_printers() -> list[str]:
    try:
        out = subprocess.run(["lpstat", "-e"], capture_output=True, text=True, timeout=10)
        names = [line.strip() for line in out.stdout.splitlines() if line.strip()]
        if names or out.returncode == 0:
            return sorted(set(names))
    except (OSError, subprocess.SubprocessError):
        pass
    try:  # starszy CUPS bez -e
        out = subprocess.run(["lpstat", "-a"], capture_output=True, text=True, timeout=10)
        return sorted({line.split()[0] for line in out.stdout.splitlines() if line.strip()})
    except (OSError, subprocess.SubprocessError):
        return []


# ---------------------------------------------------------------- druk surowy (Zebra)

def send_raw(printer_name: str, data: bytes, doc_name: str = "Etykieta CRM") -> None:
    if not printer_name:
        raise RuntimeError("Nie wybrano drukarki etykiet - ustaw ją w programie (Ustawienia).")
    if IS_WINDOWS:
        import win32print
        handle = win32print.OpenPrinter(printer_name)
        try:
            win32print.StartDocPrinter(handle, 1, (doc_name, None, "RAW"))
            try:
                win32print.StartPagePrinter(handle)
                win32print.WritePrinter(handle, data)
                win32print.EndPagePrinter(handle)
            finally:
                win32print.EndDocPrinter(handle)
        finally:
            win32print.ClosePrinter(handle)
        return
    _lp(["-d", printer_name, "-o", "raw", "-t", doc_name], data)


def _lp(args: list[str], data: bytes | None = None, path: Path | None = None) -> None:
    command = ["lp", *args] + ([str(path)] if path else [])
    try:
        result = subprocess.run(command, input=data, capture_output=True, timeout=60)
    except FileNotFoundError as exc:
        raise RuntimeError("Nie znaleziono systemowego polecenia druku (lp).") from exc
    if result.returncode != 0:
        details = (result.stderr or b"").decode("utf-8", errors="replace").strip()
        raise RuntimeError(f"Drukarka odrzuciła zadanie: {details or 'nieznany błąd'}")


# ---------------------------------------------------------------- dokumenty (A4)

def print_document(printer_name: str, content: bytes, fmt: str, doc_name: str) -> None:
    """PDF/obrazek na zwykłą drukarkę, dopasowany do strony."""
    if not printer_name:
        raise RuntimeError("Zadanie na drukarkę A4, ale żadna nie jest ustawiona - wybierz ją w Ustawieniach programu.")
    if IS_WINDOWS:
        images = [img for img, _mm in content_to_pages(content, fmt, A4_RENDER_DPI)]
        _print_images_gdi(printer_name, images, doc_name)
        return
    # CUPS przyjmuje PDF i obrazki wprost i sam dopasowuje do strony.
    suffix = ".pdf" if content[:5] == b"%PDF-" or fmt == "PDF" else "." + (fmt.lower() if fmt else "png")
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        tmp.write(content)
        path = Path(tmp.name)
    try:
        _lp(["-d", printer_name, "-o", "fit-to-page", "-t", doc_name], path=path)
    finally:
        path.unlink(missing_ok=True)


def _print_images_gdi(printer_name: str, images: list, doc_name: str) -> None:
    import win32con
    import win32ui
    from PIL import ImageWin

    device = win32ui.CreateDC()
    device.CreatePrinterDC(printer_name)
    printable_width = device.GetDeviceCaps(win32con.HORZRES)
    printable_height = device.GetDeviceCaps(win32con.VERTRES)
    device.StartDoc(doc_name)
    try:
        for image in images:
            device.StartPage()
            page = image.convert("RGB")
            scale = min(printable_width / page.width, printable_height / page.height)
            width, height = max(1, int(page.width * scale)), max(1, int(page.height * scale))
            left, top = (printable_width - width) // 2, (printable_height - height) // 2
            ImageWin.Dib(page).draw(device.GetHandleOutput(), (left, top, left + width, top + height))
            device.EndPage()
    finally:
        device.EndDoc()
        device.DeleteDC()
