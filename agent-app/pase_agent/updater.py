"""Automatyczne aktualizacje: nowa wersja z panelu CRM, sprawdzona sumą SHA-256.

Działający program nie może nadpisać sam siebie, więc podmianę robi mały skrypt,
który czeka na zamknięcie programu, wstawia nowy plik i uruchamia go ponownie.
Gdy cokolwiek się nie uda, zostaje dotychczasowa wersja.
"""

from __future__ import annotations

import hashlib
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Callable, Optional

from . import VERSION
from .autostart import app_bundle


def platform_id() -> Optional[str]:
    """Który plik pobierać; None = uruchomienie z kodu źródłowego (bez samoaktualizacji)."""
    if not getattr(sys, "frozen", False):
        return None
    return {"win32": "windows", "darwin": "macos"}.get(sys.platform)


def parse_version(text: str) -> tuple:
    parts = []
    for piece in str(text).strip().split("."):
        digits = "".join(ch for ch in piece if ch.isdigit())
        parts.append(int(digits) if digits else 0)
    return tuple(parts + [0] * (3 - len(parts)))[:3]


def is_newer(candidate: str, current: str = VERSION) -> bool:
    return parse_version(candidate) > parse_version(current)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def download_verified(client, platform: str, info: dict, progress: Optional[Callable] = None) -> Path:
    workdir = Path(tempfile.mkdtemp(prefix="crm-agent-update-"))
    target = workdir / ("Veless.exe" if platform == "windows" else "Veless-macos.zip")
    client.download_update(platform, target, progress)
    expected = str(info.get("sha256") or "").lower()
    if not expected or sha256_file(target) != expected:
        target.unlink(missing_ok=True)
        raise RuntimeError("Pobrany plik aktualizacji jest uszkodzony (niezgodna suma kontrolna) - spróbuję później.")
    return target


def apply(downloaded: Path) -> None:
    """Uruchamia podmianę; po wywołaniu program powinien się zamknąć (skrypt czeka na jego koniec)."""
    pid = os.getpid()
    if sys.platform == "win32":
        exe = Path(sys.executable).resolve()
        _check_writable(exe.parent)
        script = downloaded.parent / "update.bat"
        script.write_text(
            "@echo off\r\n"
            ":wait\r\n"
            f'tasklist /FI "PID eq {pid}" | find "{pid}" >nul && (timeout /t 1 /nobreak >nul & goto wait)\r\n'
            f'move /y "{downloaded}" "{exe}" >nul\r\n'
            f'start "" "{exe}" --background\r\n'
            'del "%~f0"\r\n', encoding="utf-8")
        flags = 0x08000000 | 0x00000008  # CREATE_NO_WINDOW | DETACHED_PROCESS
        subprocess.Popen(["cmd", "/c", str(script)], creationflags=flags, close_fds=True)
        return
    if sys.platform == "darwin":
        bundle = app_bundle()
        if bundle is None:
            raise RuntimeError("Nie znalazłem pakietu Veless.app - zaktualizuj ręcznie.")
        _check_writable(bundle.parent)
        unpack = downloaded.parent / "unpacked"
        subprocess.run(["/usr/bin/ditto", "-x", "-k", str(downloaded), str(unpack)], check=True, timeout=120)
        new_app = next(unpack.glob("*.app"), None)
        if new_app is None:
            raise RuntimeError("Archiwum aktualizacji nie zawiera programu.")
        script = downloaded.parent / "update.sh"
        script.write_text(
            f'while kill -0 {pid} 2>/dev/null; do sleep 1; done\n'
            f'rm -rf "{bundle}.old"\n'
            f'if mv "{bundle}" "{bundle}.old" && mv "{new_app}" "{bundle}"; then rm -rf "{bundle}.old"; '
            f'else rm -rf "{bundle}"; mv "{bundle}.old" "{bundle}"; fi\n'
            f'xattr -dr com.apple.quarantine "{bundle}" 2>/dev/null\n'
            f'open "{bundle}" --args --background\n', encoding="utf-8")
        subprocess.Popen(["/bin/sh", str(script)], start_new_session=True, close_fds=True)
        return
    raise RuntimeError("Samoaktualizacja działa w wersji programu na Windows i macOS.")


def _check_writable(folder: Path) -> None:
    probe = folder / ".crm-write-test"
    try:
        probe.write_bytes(b"")
        probe.unlink()
    except OSError as exc:
        raise RuntimeError(f"Brak uprawnień do zapisu w {folder} - przenieś program np. do folderu użytkownika "
                           "albo zaktualizuj ręcznie.") from exc
