"""Uruchamianie programu przy starcie (po zalogowaniu do systemu).

Windows: wpis w rejestrze HKCU\\...\\Run (bez uprawnień administratora).
macOS: LaunchAgent w ~/Library/LaunchAgents (tak robią programy z paska menu).
"""

from __future__ import annotations

import plistlib
import subprocess
import sys
from pathlib import Path

RUN_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"
VALUE_NAME = "Veless"
LEGACY_VALUE_NAMES = ("CRM Agent", "Woskarz CRM")   # wpisy z wcześniejszych nazw programu - usuwane przy zapisie
LAUNCH_LABEL = "pl.crm.agent"


def executable_command() -> list[str]:
    """Czym uruchomić program: zbudowany .exe/.app albo (w trakcie tworzenia) skrypt Pythona."""
    if getattr(sys, "frozen", False):
        if sys.platform == "darwin":
            app = app_bundle()
            if app is not None:
                return ["/usr/bin/open", "-a", str(app), "--args", "--background"]
        return [sys.executable, "--background"]
    return [sys.executable, str(Path(__file__).resolve().parent.parent / "main.py"), "--background"]


def app_bundle() -> Path | None:
    """Katalog Veless.app, gdy działamy jako aplikacja macOS."""
    for parent in Path(sys.executable).resolve().parents:
        if parent.suffix == ".app":
            return parent
    return None


def plist_path() -> Path:
    return Path.home() / "Library" / "LaunchAgents" / f"{LAUNCH_LABEL}.plist"


def is_enabled() -> bool:
    if sys.platform == "win32":
        import winreg
        try:
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY) as key:
                for name in (VALUE_NAME, *LEGACY_VALUE_NAMES):
                    try:
                        winreg.QueryValueEx(key, name)
                        return True
                    except OSError:
                        continue
            return False
        except OSError:
            return False
    if sys.platform == "darwin":
        return plist_path().is_file()
    return False


def set_enabled(enabled: bool) -> None:
    if sys.platform == "win32":
        import winreg
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY, 0, winreg.KEY_SET_VALUE) as key:
            for name in LEGACY_VALUE_NAMES + (() if enabled else (VALUE_NAME,)):
                try:
                    winreg.DeleteValue(key, name)
                except FileNotFoundError:
                    pass
            if enabled:
                command = subprocess.list2cmdline(executable_command())
                winreg.SetValueEx(key, VALUE_NAME, 0, winreg.REG_SZ, command)
        return
    if sys.platform == "darwin":
        path = plist_path()
        if enabled:
            path.parent.mkdir(parents=True, exist_ok=True)
            with open(path, "wb") as fh:
                plistlib.dump({"Label": LAUNCH_LABEL, "ProgramArguments": executable_command(),
                               "RunAtLoad": True, "ProcessType": "Interactive"}, fh)
        else:
            path.unlink(missing_ok=True)
        return
    raise RuntimeError("Autostart jest dostępny na Windows i macOS.")


def refresh() -> None:
    """Po aktualizacji albo przeniesieniu programu wpis autostartu wskazuje na obecny plik."""
    if getattr(sys, "frozen", False) and sys.platform in ("win32", "darwin") and is_enabled():
        set_enabled(True)
