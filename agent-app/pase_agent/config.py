"""Konfiguracja programu: gdzie leży, wartości domyślne, kod połączenia z panelu."""

from __future__ import annotations

import base64
import json
import os
import sys
from pathlib import Path
from typing import Optional

# Parametry etykiety (jak w dotychczasowym agencie). 203 dpi = najpopularniejsza Zebra,
# 101.6 mm = etykieta kurierska 4". Próg 160 - czytelne kody kreskowe.
DEFAULT_DPI = 203
DEFAULT_LABEL_WIDTH_MM = 101.6
DEFAULT_THRESHOLD = 160

DEFAULTS: dict = {
    "base_url": "",
    "api_key": "",
    "printer_name": "",       # drukarka etykiet (Zebra)
    "printer_a4": "",         # zwykła drukarka na dokumenty A4 (opcjonalna)
    "dpi": DEFAULT_DPI,
    "label_width_mm": DEFAULT_LABEL_WIDTH_MM,
    "threshold": DEFAULT_THRESHOLD,
    "browser_path": "",       # Edge/Chrome do dokumentów HTML - puste = szukaj automatycznie
    "auto_update": True,
}

CODE_PREFIX = "CRM1:"


def app_dir() -> Path:
    """Katalog danych programu (konfiguracja, dziennik)."""
    if sys.platform == "win32":
        base = Path(os.environ.get("APPDATA") or Path.home() / "AppData" / "Roaming")
    elif sys.platform == "darwin":
        base = Path.home() / "Library" / "Application Support"
    else:
        base = Path(os.environ.get("XDG_CONFIG_HOME") or Path.home() / ".config")
    path = base / "Veless"
    path.mkdir(parents=True, exist_ok=True)
    return path


def config_path() -> Path:
    return app_dir() / "config.json"


def log_path() -> Path:
    return app_dir() / "agent.log"


def normalize(config: dict) -> dict:
    """Uzupełnia brakujące pola i poprawia typy (np. ręcznie edytowany plik)."""
    out = dict(DEFAULTS)
    out.update({k: v for k, v in (config or {}).items() if k in DEFAULTS})
    out["base_url"] = str(out["base_url"] or "").strip().rstrip("/")
    out["api_key"] = str(out["api_key"] or "").strip()
    for key, cast, default in (("dpi", int, DEFAULT_DPI), ("threshold", int, DEFAULT_THRESHOLD),
                               ("label_width_mm", float, DEFAULT_LABEL_WIDTH_MM)):
        try:
            value = cast(str(out[key]).replace(",", "."))
            out[key] = value if value > 0 else default
        except (TypeError, ValueError):
            out[key] = default
    out["auto_update"] = bool(out["auto_update"])
    return out


def legacy_config_candidates() -> list[Path]:
    """Gdzie mógł leżeć config.json starego agenta (skrypt / PaseAgent.exe) - do przejęcia ustawień."""
    here = Path(sys.executable).resolve().parent if getattr(sys, "frozen", False) else Path(__file__).resolve().parent
    # Wcześniejsze nazwy programu („Woskarz CRM", w 2.0 „CRM Agent") - po zmianie nazwy przejmujemy ustawienia stamtąd.
    candidates = [app_dir().parent / "Woskarz CRM" / "config.json", app_dir().parent / "CRM Agent" / "config.json",
                  here / "config.json", Path("C:/PaseAgent/config.json"), Path.home() / "PaseAgent" / "config.json"]
    return [p for p in candidates if p != config_path()]


def load(path: Optional[Path] = None) -> dict:
    """Wczytuje konfigurację. Przy pierwszym uruchomieniu przejmuje ustawienia starego agenta."""
    path = path or config_path()
    raw: dict = {}
    if path.is_file():
        try:
            raw = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            raw = {}
    elif path == config_path():
        for legacy in legacy_config_candidates():
            try:
                raw = json.loads(legacy.read_text(encoding="utf-8"))
                if raw.get("api_key"):
                    break
            except (OSError, json.JSONDecodeError):
                raw = {}
    return normalize(raw)


def save(config: dict, path: Optional[Path] = None) -> None:
    path = path or config_path()
    tmp = path.with_suffix(".tmp")
    tmp.write_text(json.dumps(normalize(config), indent=2, ensure_ascii=False), encoding="utf-8")
    os.replace(tmp, path)


def is_configured(config: dict) -> bool:
    return bool(config.get("base_url") and config.get("api_key") and config.get("printer_name"))


def make_connection_code(base_url: str, api_key: str) -> str:
    """Kod połączenia (jak w panelu Drukowanie): adres + klucz w jednym ciągu do wklejenia."""
    payload = json.dumps({"u": base_url.rstrip("/"), "k": api_key}, separators=(",", ":")).encode()
    return CODE_PREFIX + base64.urlsafe_b64encode(payload).decode().rstrip("=")


def parse_connection_code(code: str) -> tuple[str, str]:
    """Kod połączenia -> (adres panelu, klucz). Błędny kod -> ValueError z czytelnym opisem."""
    code = (code or "").strip()
    if not code.startswith(CODE_PREFIX):
        raise ValueError("To nie jest kod połączenia z panelu (powinien zaczynać się od CRM1:).")
    body = code[len(CODE_PREFIX):]
    try:
        data = json.loads(base64.urlsafe_b64decode(body + "=" * (-len(body) % 4)))
        url, key = str(data["u"]).strip(), str(data["k"]).strip()
    except Exception as exc:  # noqa: BLE001 - każdy błąd dekodowania = zły kod
        raise ValueError("Kod połączenia jest niepełny albo uszkodzony - skopiuj go z panelu jeszcze raz.") from exc
    if not url.startswith(("https://", "http://")) or not key:
        raise ValueError("Kod połączenia nie zawiera adresu panelu albo klucza.")
    return url.rstrip("/"), key
