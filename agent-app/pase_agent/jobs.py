"""Jedno zadanie druku -> właściwa drukarka właściwą drogą (jak w dotychczasowym agencie)."""

from __future__ import annotations

from typing import Callable

from . import printers
from .rendering import content_to_zpl_pages, html_to_pdf, is_html, needs_rasterizing

TEST_ZPL = ("^XA" "^FO50,50^A0N,40,40^FDTest Veless^FS" "^FO50,110^A0N,30,30^FDDrukarka: OK^FS" "^XZ").encode("ascii")


def print_job(config: dict, content: bytes, job_format: str, target: str, filename: str,
              log: Callable[[str], None] = lambda _m: None) -> None:
    fmt = (job_format or "").upper()

    # HTML raz na PDF - ten sam dokument wygląda tak samo na Zebrze i na A4.
    if is_html(content, fmt):
        content = html_to_pdf(content, config)
        fmt = "PDF"

    if target == "a4":
        if fmt in ("ZPL", "EPL"):
            raise RuntimeError("Surowe komendy ZPL/EPL nie nadają się na zwykłą drukarkę.")
        printers.print_document(config.get("printer_a4", ""), content, fmt, filename)
        return

    if needs_rasterizing(content, fmt):
        pages = content_to_zpl_pages(content, fmt, config)
        if len(pages) > 1:
            log(f"{len(pages)} stron do wydrukowania.")
        for number, page in enumerate(pages, start=1):
            name = filename if len(pages) == 1 else f"{filename} ({number}/{len(pages)})"
            printers.send_raw(config.get("printer_name", ""), page, doc_name=name)
    else:
        printers.send_raw(config.get("printer_name", ""), content, doc_name=filename)


def test_label(config: dict) -> None:
    printers.send_raw(config.get("printer_name", ""), TEST_ZPL, doc_name="Test CRM")
