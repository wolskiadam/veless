"""Ikony programu (.ico dla Windows, .icns dla Maca) z logo rysowanego w pase_agent/icons.py.

    python build_tools/make_icons.py build/   ->  build/icon.ico, build/icon.icns, build/icon.png
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PIL import Image  # noqa: E402
from PySide6.QtGui import QGuiApplication  # noqa: E402


def main() -> int:
    out = Path(sys.argv[1] if len(sys.argv) > 1 else "build")
    out.mkdir(parents=True, exist_ok=True)
    app = QGuiApplication([])  # noqa: F841 - QPixmap wymaga aplikacji Qt
    from pase_agent import icons
    png = out / "icon.png"
    if not icons._logo(1024).save(str(png), "PNG"):
        raise SystemExit("Nie udało się zapisać icon.png")
    img = Image.open(png).convert("RGBA")
    img.save(out / "icon.ico", sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])
    img.save(out / "icon.icns")
    print(f"Ikony zapisane w {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
