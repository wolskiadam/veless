"""Rozmowa z serwerem CRM: pytanie o wydruk, potwierdzenie, sprawdzenie aktualizacji."""

from __future__ import annotations

import base64
from dataclasses import dataclass, field
from typing import Optional

import requests

from . import VERSION
from .config import DEFAULT_DPI, DEFAULT_LABEL_WIDTH_MM

DEFAULT_POLL = 4          # gdy serwer nie poda next_poll (starszy serwer)
MIN_POLL, MAX_POLL = 1, 900
TIMEOUT = 15


class ServerError(Exception):
    """Serwer odmówił (np. zły klucz) - komunikat do pokazania użytkownikowi."""


@dataclass
class PollResult:
    job: Optional[dict] = None
    next_poll: int = DEFAULT_POLL
    paused: bool = False
    mode: str = ""          # 'active' / 'idle' - czy ktoś pracuje w panelu (serwer od 1.1)
    content: bytes = field(default=b"", repr=False)


def parse_next_poll(value) -> Optional[int]:
    try:
        return max(MIN_POLL, min(MAX_POLL, int(value)))
    except (TypeError, ValueError):
        return None


class Client:
    def __init__(self, config: dict, session: Optional[requests.Session] = None):
        self.config = config
        self.http = session or requests.Session()
        self.http.headers["User-Agent"] = f"Veless/{VERSION}"

    @property
    def base(self) -> str:
        return self.config["base_url"].rstrip("/")

    def _json(self, resp) -> dict:
        try:
            data = resp.json()
        except ValueError:
            resp.raise_for_status()
            raise ServerError(f"Serwer zwrócił nieczytelną odpowiedź (HTTP {resp.status_code}).")
        if isinstance(data, dict) and "error" in data:
            reason = data["error"]
            if reason == "unauthorized":
                raise ServerError("Serwer odrzucił klucz API - skopiuj kod połączenia z panelu (Konfiguracja → Drukowanie).")
            raise ServerError(f"Serwer odmówił: {reason}")
        return data if isinstance(data, dict) else {}

    def poll(self) -> PollResult:
        c = self.config
        resp = self.http.get(f"{self.base}/print_agent_poll.php", timeout=TIMEOUT, params={
            "key": c["api_key"],
            "dpi": int(c.get("dpi", DEFAULT_DPI)),
            "label_mm": float(c.get("label_width_mm", DEFAULT_LABEL_WIDTH_MM)),
            "a4": "1" if (c.get("printer_a4") or "").strip() else "0",
            "v": VERSION,
        })
        data = self._json(resp)
        result = PollResult(job=data.get("job"), paused=bool(data.get("paused")), mode=str(data.get("mode") or ""),
                            next_poll=parse_next_poll(data.get("next_poll")) or DEFAULT_POLL)
        if result.job:
            result.content = base64.b64decode(result.job.get("content_b64") or "")
        return result

    def ack(self, job_id: int, ok: bool, error: str = "") -> None:
        data = {"key": self.config["api_key"], "job_id": job_id, "status": "done" if ok else "failed"}
        if not ok:
            data["error"] = error[:1000]
        self.http.post(f"{self.base}/print_agent_ack.php", data=data, timeout=TIMEOUT)

    def check_update(self, platform: str) -> Optional[dict]:
        """{'version','sha256','size'} najnowszej wersji dla systemu albo None (brak/starszy serwer)."""
        resp = self.http.get(f"{self.base}/agent_update.php", timeout=TIMEOUT,
                             params={"key": self.config["api_key"], "platform": platform, "v": VERSION})
        if resp.status_code == 404:
            return None
        data = self._json(resp)
        return data if data.get("version") else None

    def download_update(self, platform: str, dest, progress=None) -> None:
        with self.http.get(f"{self.base}/agent_update.php", timeout=120, stream=True,
                           params={"key": self.config["api_key"], "platform": platform, "download": "1"}) as resp:
            if resp.status_code != 200:
                raise ServerError(f"Nie udało się pobrać aktualizacji (HTTP {resp.status_code}).")
            total = int(resp.headers.get("Content-Length") or 0)
            done = 0
            with open(dest, "wb") as fh:
                for chunk in resp.iter_content(chunk_size=256 * 1024):
                    fh.write(chunk)
                    done += len(chunk)
                    if progress and total:
                        progress(done, total)
