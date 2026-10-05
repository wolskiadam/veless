"""Ikony rysowane w kodzie (bez plików graficznych): logo programu i kropka stanu."""

from __future__ import annotations

from PySide6.QtCore import QRectF, Qt
from PySide6.QtGui import QColor, QFont, QIcon, QPainter, QPainterPath, QPixmap

ACCENT = "#9c6b2e"
STATE_COLORS = {
    "working": "#2e9d5b",       # gotowy / pracuje
    "idle": "#2e9d5b",
    "paused_server": "#d9912b",
    "paused_local": "#d9912b",
    "error": "#c94a2f",
    "unconfigured": "#8a8d93",
    "starting": "#8a8d93",
}


def _logo(size: int, dot: str | None = None) -> QPixmap:
    pm = QPixmap(size, size)
    pm.fill(Qt.transparent)
    p = QPainter(pm)
    p.setRenderHint(QPainter.Antialiasing)
    r = QRectF(size * 0.06, size * 0.06, size * 0.88, size * 0.88)
    path = QPainterPath()
    path.addRoundedRect(r, size * 0.22, size * 0.22)
    p.fillPath(path, QColor(ACCENT))
    font = QFont()
    font.setBold(True)
    font.setPixelSize(int(size * 0.58))
    p.setFont(font)
    p.setPen(QColor("white"))
    p.drawText(r, Qt.AlignCenter, "W")
    if dot:
        d = size * 0.42
        p.setPen(QColor("white"))
        p.setBrush(QColor(dot))
        p.drawEllipse(QRectF(size - d - 1, size - d - 1, d, d))
    p.end()
    return pm


def app_icon() -> QIcon:
    icon = QIcon()
    for size in (16, 24, 32, 48, 64, 128, 256):
        icon.addPixmap(_logo(size))
    return icon


def tray_icon(state: str) -> QIcon:
    icon = QIcon()
    for size in (16, 22, 32, 44, 64):
        icon.addPixmap(_logo(size, STATE_COLORS.get(state, STATE_COLORS["starting"])))
    return icon


def dot_pixmap(state: str, size: int = 14) -> QPixmap:
    pm = QPixmap(size, size)
    pm.fill(Qt.transparent)
    p = QPainter(pm)
    p.setRenderHint(QPainter.Antialiasing)
    p.setPen(Qt.NoPen)
    p.setBrush(QColor(STATE_COLORS.get(state, STATE_COLORS["starting"])))
    p.drawEllipse(1, 1, size - 2, size - 2)
    p.end()
    return pm
