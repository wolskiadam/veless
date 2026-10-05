"""Okna programu: ikona przy zegarze / na pasku menu i okno Status · Ustawienia · Dziennik."""

from __future__ import annotations

import logging
import os
import subprocess
import sys
import time
from datetime import datetime

from PySide6.QtCore import QObject, Qt, QThread, QTimer, QUrl, Signal
from PySide6.QtGui import QAction, QDesktopServices, QGuiApplication
from PySide6.QtWidgets import (QApplication, QCheckBox, QComboBox, QDoubleSpinBox, QFormLayout, QFrame,
                               QGridLayout, QGroupBox, QHBoxLayout, QLabel, QLineEdit, QMenu, QMessageBox,
                               QPlainTextEdit, QPushButton, QSystemTrayIcon, QTabWidget, QVBoxLayout, QWidget)

from . import APP_NAME, VERSION, autostart, config, icons, jobs, printers, updater
from .agent import Agent
from .client import Client, ServerError

log = logging.getLogger("pase_agent")

STATE_TEXT = {
    "starting": "Uruchamianie…",
    "working": "Działa — gotowy do druku",
    "idle": "Działa — tryb oszczędny",
    "paused_server": "Wstrzymane w panelu CRM",
    "paused_local": "Wstrzymane na tym komputerze",
    "error": "Problem z połączeniem",
    "unconfigured": "Wymaga konfiguracji",
}

STYLE = """
QWidget { font-size: 13px; color: #22252b; }
QWidget#root { background: #f6f5f2; }
QGroupBox { background: #ffffff; border: 1px solid #e6e3da; border-radius: 10px; margin-top: 14px; padding: 12px; font-weight: 600; }
QGroupBox::title { subcontrol-origin: margin; left: 12px; padding: 0 4px; color: #6d7076; }
QPushButton { background: #efeee9; border: 1px solid #e0ddd3; border-radius: 7px; padding: 6px 12px; }
QPushButton:hover { border-color: #9c6b2e; }
QPushButton#primary { background: #9c6b2e; color: white; border: none; font-weight: 700; }
QPushButton#primary:hover { background: #835a24; }
QLineEdit, QComboBox, QDoubleSpinBox { background: white; border: 1px solid #e0ddd3; border-radius: 6px; padding: 5px 7px; }
QLineEdit:focus, QComboBox:focus, QDoubleSpinBox:focus { border-color: #9c6b2e; }
QTabWidget::pane { border: none; }
QTabBar::tab { padding: 8px 16px; color: #6d7076; }
QTabBar::tab:selected { color: #22252b; border-bottom: 2px solid #9c6b2e; font-weight: 600; }
QLabel#muted { color: #6d7076; font-size: 12px; }
QLabel#state { font-size: 17px; font-weight: 700; }
QPlainTextEdit { background: white; border: 1px solid #e6e3da; border-radius: 8px; font-family: Menlo, Consolas, monospace; font-size: 12px; }
"""


class Bridge(QObject):
    """Sygnały z wątku pracy do okien (Qt przenosi je bezpiecznie do wątku okien)."""
    status = Signal(dict)
    update_ready = Signal(str, str)
    log_line = Signal(str)


class QtLogHandler(logging.Handler):
    def __init__(self, bridge: Bridge):
        super().__init__()
        self.bridge = bridge
        self.setFormatter(logging.Formatter("%(asctime)s  %(message)s", "%H:%M:%S"))

    def emit(self, record):
        try:
            self.bridge.log_line.emit(self.format(record))
        except RuntimeError:
            pass  # okno już zamknięte


class AgentThread(QThread):
    def __init__(self, agent: Agent):
        super().__init__()
        self.agent = agent

    def run(self):
        self.agent.run()


class MainWindow(QWidget):
    def __init__(self, controller: "Controller"):
        super().__init__()
        self.c = controller
        self.setObjectName("root")
        self.setWindowTitle(APP_NAME)
        self.setWindowIcon(icons.app_icon())
        self.setStyleSheet(STYLE)
        self.resize(560, 600)
        self._close_hint_shown = False

        layout = QVBoxLayout(self)
        layout.setContentsMargins(16, 12, 16, 16)
        self.tabs = QTabWidget()
        self.tabs.addTab(self._status_tab(), "Status")
        self.tabs.addTab(self._settings_tab(), "Ustawienia")
        self.tabs.addTab(self._log_tab(), "Dziennik")
        layout.addWidget(self.tabs)

    # ------------------------------------------------------------------ Status
    def _status_tab(self) -> QWidget:
        w = QWidget()
        v = QVBoxLayout(w)
        box = QGroupBox("Stan")
        g = QGridLayout(box)
        self.dot = QLabel()
        self.state_label = QLabel(STATE_TEXT["starting"])
        self.state_label.setObjectName("state")
        self.state_msg = QLabel("")
        self.state_msg.setObjectName("muted")
        self.state_msg.setWordWrap(True)
        head = QHBoxLayout()
        head.addWidget(self.dot)
        head.addWidget(self.state_label, 1)
        g.addLayout(head, 0, 0, 1, 2)
        g.addWidget(self.state_msg, 1, 0, 1, 2)
        self.info = {}
        for row, (key, label) in enumerate([("server", "Panel CRM"), ("last_poll", "Ostatnie połączenie"),
                                            ("next_poll", "Następne za"), ("printed", "Wydrukowano dziś"),
                                            ("last_job", "Ostatni wydruk"), ("printers", "Drukarki"),
                                            ("version", "Wersja programu")], start=2):
            name = QLabel(label)
            name.setObjectName("muted")
            value = QLabel("—")
            value.setTextInteractionFlags(Qt.TextSelectableByMouse)
            value.setWordWrap(True)
            g.addWidget(name, row, 0)
            g.addWidget(value, row, 1)
            self.info[key] = value
        g.setColumnStretch(1, 1)
        v.addWidget(box)

        actions = QHBoxLayout()
        self.test_btn = QPushButton("Wydruk testowy")
        self.test_btn.clicked.connect(self.c.test_print)
        self.pause_btn = QPushButton("Wstrzymaj na tym komputerze")
        self.pause_btn.clicked.connect(self.c.toggle_local_pause)
        self.panel_btn = QPushButton("Otwórz panel CRM")
        self.panel_btn.clicked.connect(self.c.open_panel)
        for b in (self.test_btn, self.pause_btn, self.panel_btn):
            actions.addWidget(b)
        v.addLayout(actions)
        v.addStretch(1)
        return w

    def show_status(self, s: dict) -> None:
        state = s.get("state", "starting")
        self.dot.setPixmap(icons.dot_pixmap(state, 16))
        self.state_label.setText(STATE_TEXT.get(state, state))
        self.state_msg.setText(s.get("message", ""))
        cfg = self.c.config
        self.info["server"].setText(cfg.get("base_url") or "—")
        self.info["last_poll"].setText(datetime.fromtimestamp(s["last_poll"]).strftime("%H:%M:%S") if s.get("last_poll") else "—")
        self.info["next_poll"].setText(f'{s["next_poll"]} s' if s.get("next_poll") else "—")
        self.info["printed"].setText(str(s.get("printed_today", 0)))
        self.info["last_job"].setText(s.get("last_job") or "—")
        self.info["printers"].setText(f'Etykiety: {cfg.get("printer_name") or "—"}\nA4: {cfg.get("printer_a4") or "(brak)"}')
        self.info["version"].setText(VERSION)
        self.pause_btn.setText("Wznów" if state == "paused_local" else "Wstrzymaj na tym komputerze")

    # ------------------------------------------------------------------ Ustawienia
    def _settings_tab(self) -> QWidget:
        w = QWidget()
        v = QVBoxLayout(w)

        conn = QGroupBox("Połączenie z CRM")
        f = QFormLayout(conn)
        hint = QLabel("Wklej kod połączenia z panelu: Konfiguracja → Drukowanie → „Kod połączenia”.")
        hint.setObjectName("muted")
        hint.setWordWrap(True)
        f.addRow(hint)
        code_row = QHBoxLayout()
        self.code_edit = QLineEdit()
        self.code_edit.setPlaceholderText("CRM1:…")
        use_code = QPushButton("Użyj kodu")
        use_code.clicked.connect(self._apply_code)
        code_row.addWidget(self.code_edit, 1)
        code_row.addWidget(use_code)
        f.addRow("Kod połączenia", code_row)
        self.url_edit = QLineEdit()
        self.url_edit.setPlaceholderText("https://…/crm/public")
        self.key_edit = QLineEdit()
        self.key_edit.setEchoMode(QLineEdit.Password)
        f.addRow("Adres panelu", self.url_edit)
        f.addRow("Klucz API", self.key_edit)
        test_conn = QPushButton("Sprawdź połączenie")
        test_conn.clicked.connect(self._test_connection)
        f.addRow("", test_conn)
        v.addWidget(conn)

        prn = QGroupBox("Drukarki")
        f2 = QFormLayout(prn)
        row = QHBoxLayout()
        self.zebra_combo = QComboBox()
        self.zebra_combo.setMinimumWidth(260)
        refresh = QPushButton("Odśwież")
        refresh.clicked.connect(self._fill_printers)
        row.addWidget(self.zebra_combo, 1)
        row.addWidget(refresh)
        f2.addRow("Drukarka etykiet (Zebra)", row)
        self.a4_combo = QComboBox()
        f2.addRow("Drukarka A4 (opcjonalnie)", self.a4_combo)
        self.dpi_combo = QComboBox()
        for d in (203, 300, 600):
            self.dpi_combo.addItem(f"{d} dpi", d)
        f2.addRow("Rozdzielczość etykiet", self.dpi_combo)
        self.width_spin = QDoubleSpinBox()
        self.width_spin.setRange(10, 200)
        self.width_spin.setDecimals(1)
        self.width_spin.setSuffix(" mm")
        f2.addRow("Szerokość etykiety", self.width_spin)
        v.addWidget(prn)

        app = QGroupBox("Program")
        v2 = QVBoxLayout(app)
        self.autostart_check = QCheckBox("Uruchamiaj przy starcie komputera")
        self.autoupdate_check = QCheckBox("Aktualizuj automatycznie")
        upd = QPushButton("Sprawdź aktualizacje teraz")
        upd.clicked.connect(self.c.check_updates)
        v2.addWidget(self.autostart_check)
        v2.addWidget(self.autoupdate_check)
        v2.addWidget(upd, 0, Qt.AlignLeft)
        v.addWidget(app)

        save = QPushButton("Zapisz ustawienia")
        save.setObjectName("primary")
        save.clicked.connect(self._save)
        v.addWidget(save, 0, Qt.AlignRight)
        v.addStretch(1)
        self.load_settings()
        return w

    def _fill_printers(self) -> None:
        cfg = self.c.config
        try:
            names = printers.list_printers()
        except Exception as exc:  # noqa: BLE001
            names = []
            log.error("Nie udało się odczytać listy drukarek: %s", exc)
        for combo, current, optional in ((self.zebra_combo, cfg.get("printer_name", ""), False),
                                         (self.a4_combo, cfg.get("printer_a4", ""), True)):
            combo.clear()
            if optional:
                combo.addItem("(brak — dokumenty A4 będą odrzucane)", "")
            elif not names:
                combo.addItem("(nie znaleziono drukarek)", "")
            for name in names:
                combo.addItem(name, name)
            if current and current not in names:
                combo.addItem(f"{current} (niedostępna)", current)
            idx = combo.findData(current)
            combo.setCurrentIndex(max(0, idx))

    def load_settings(self) -> None:
        cfg = self.c.config
        self.url_edit.setText(cfg.get("base_url", ""))
        self.key_edit.setText(cfg.get("api_key", ""))
        self._fill_printers()
        self.dpi_combo.setCurrentIndex(max(0, self.dpi_combo.findData(int(cfg.get("dpi", 203)))))
        self.width_spin.setValue(float(cfg.get("label_width_mm", 101.6)))
        self.autoupdate_check.setChecked(bool(cfg.get("auto_update", True)))
        try:
            self.autostart_check.setChecked(autostart.is_enabled())
        except Exception:  # noqa: BLE001
            self.autostart_check.setEnabled(False)

    def _apply_code(self) -> None:
        try:
            url, key = config.parse_connection_code(self.code_edit.text())
        except ValueError as exc:
            QMessageBox.warning(self, APP_NAME, str(exc))
            return
        self.url_edit.setText(url)
        self.key_edit.setText(key)
        self.code_edit.clear()
        self._test_connection()

    def _form_config(self) -> dict:
        return config.normalize(dict(self.c.config, base_url=self.url_edit.text(), api_key=self.key_edit.text(),
                                     printer_name=self.zebra_combo.currentData() or "",
                                     printer_a4=self.a4_combo.currentData() or "",
                                     dpi=self.dpi_combo.currentData(), label_width_mm=self.width_spin.value(),
                                     auto_update=self.autoupdate_check.isChecked()))

    def _test_connection(self) -> None:
        cfg = self._form_config()
        if not cfg["base_url"] or not cfg["api_key"]:
            QMessageBox.warning(self, APP_NAME, "Podaj adres panelu i klucz (albo wklej kod połączenia).")
            return
        QGuiApplication.setOverrideCursor(Qt.WaitCursor)
        try:
            # Samo pytanie „czy jest wydruk” mogłoby pobrać zadanie - sprawdzamy więc przez aktualizacje (bez skutków).
            Client(cfg).check_update(updater.platform_id() or "windows")
            ok, msg = True, "Połączenie działa — klucz przyjęty."
        except ServerError as exc:
            ok, msg = False, str(exc)
        except Exception as exc:  # noqa: BLE001
            ok, msg = False, f"Nie udało się połączyć z {cfg['base_url']}: {exc}"
        finally:
            QGuiApplication.restoreOverrideCursor()
        (QMessageBox.information if ok else QMessageBox.warning)(self, APP_NAME, msg)

    def _save(self) -> None:
        cfg = self._form_config()
        if not cfg["printer_name"]:
            QMessageBox.warning(self, APP_NAME, "Wybierz drukarkę etykiet.")
            return
        try:
            if autostart.is_enabled() != self.autostart_check.isChecked():
                autostart.set_enabled(self.autostart_check.isChecked())
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(self, APP_NAME, f"Nie udało się zmienić autostartu: {exc}")
        self.c.save_config(cfg)
        self.tabs.setCurrentIndex(0)

    # ------------------------------------------------------------------ Dziennik
    def _log_tab(self) -> QWidget:
        w = QWidget()
        v = QVBoxLayout(w)
        self.log_view = QPlainTextEdit()
        self.log_view.setReadOnly(True)
        self.log_view.setMaximumBlockCount(500)
        v.addWidget(self.log_view)
        row = QHBoxLayout()
        folder = QPushButton("Otwórz folder z dziennikiem")
        folder.clicked.connect(lambda: QDesktopServices.openUrl(QUrl.fromLocalFile(str(config.app_dir()))))
        row.addWidget(folder)
        row.addStretch(1)
        v.addLayout(row)
        return w

    def append_log(self, line: str) -> None:
        self.log_view.appendPlainText(line)

    def closeEvent(self, event):  # zamknięcie okna = chowanie do ikony, program działa dalej
        event.ignore()
        self.hide()
        if not self._close_hint_shown and self.c.tray is not None:
            self._close_hint_shown = True
            self.c.tray.showMessage(APP_NAME, "Program działa dalej w tle — ikona jest przy zegarze.",
                                    QSystemTrayIcon.Information, 4000)


class Controller(QObject):
    """Łączy pętlę agenta, okno i ikonę."""

    def __init__(self, app: QApplication, start_hidden: bool):
        super().__init__()
        self.app = app
        self.config = config.load()
        self.bridge = Bridge()
        handler = QtLogHandler(self.bridge)
        logger = logging.getLogger("pase_agent")
        if logger.level == logging.NOTSET:
            logger.setLevel(logging.INFO)
        logger.addHandler(handler)
        self.agent = Agent(lambda: self.config, on_status=self.bridge.status.emit,
                           on_update_ready=self.bridge.update_ready.emit)
        self.tray = QSystemTrayIcon(icons.tray_icon("starting")) if QSystemTrayIcon.isSystemTrayAvailable() else None
        self.window = MainWindow(self)
        self.bridge.status.connect(self._on_status)
        self.bridge.log_line.connect(self.window.append_log)
        self.bridge.update_ready.connect(self._on_update_ready)
        self._last_state = None
        self._build_tray()

        self.thread = AgentThread(self.agent)
        self.thread.start()
        log.info("%s %s uruchomiony. Dane programu: %s", APP_NAME, VERSION, config.app_dir())
        try:
            autostart.refresh()
        except Exception as exc:  # noqa: BLE001 - autostart nie może zatrzymać programu
            log.warning("Nie udało się odświeżyć autostartu: %s", exc)

        if not config.is_configured(self.config):
            self.show_window(tab=1)
        elif not start_hidden or self.tray is None:
            self.show_window()

    # ------------------------------------------------------------------ ikona
    def _build_tray(self) -> None:
        if self.tray is None:
            return
        menu = QMenu()
        self.tray_state = QAction(STATE_TEXT["starting"])
        self.tray_state.setEnabled(False)
        menu.addAction(self.tray_state)
        menu.addSeparator()
        menu.addAction("Otwórz…", self.show_window)
        menu.addAction("Wydruk testowy", self.test_print)
        self.tray_pause = menu.addAction("Wstrzymaj na tym komputerze", self.toggle_local_pause)
        menu.addAction("Otwórz panel CRM", self.open_panel)
        menu.addSeparator()
        menu.addAction("Zakończ", self.quit)
        self.tray.setContextMenu(menu)
        self.tray.setToolTip(APP_NAME)
        self.tray.activated.connect(lambda reason: self.show_window() if reason in (
            QSystemTrayIcon.Trigger, QSystemTrayIcon.DoubleClick) and sys.platform != "darwin" else None)
        self.tray.show()
        self._menu = menu

    def _on_status(self, s: dict) -> None:
        state = s.get("state", "starting")
        self.window.show_status(s)
        if self.tray is not None:
            self.tray.setIcon(icons.tray_icon(state))
            self.tray.setToolTip(f"{APP_NAME} — {STATE_TEXT.get(state, state)}")
            self.tray_state.setText(STATE_TEXT.get(state, state))
            self.tray_pause.setText("Wznów" if state == "paused_local" else "Wstrzymaj na tym komputerze")
            if state == "error" and self._last_state not in ("error", None):
                self.tray.showMessage(APP_NAME, s.get("message", ""), QSystemTrayIcon.Warning, 5000)
        self._last_state = state

    # ------------------------------------------------------------------ akcje
    def show_window(self, tab: int | None = None) -> None:
        if tab is not None:
            self.window.tabs.setCurrentIndex(tab)
        self.window.show()
        self.window.raise_()
        self.window.activateWindow()

    def save_config(self, cfg: dict) -> None:
        config.save(cfg)
        self.config = config.load()
        log.info("Zapisano ustawienia (drukarka etykiet: %s, A4: %s).", self.config["printer_name"], self.config["printer_a4"] or "brak")
        self.agent.wake()

    def test_print(self) -> None:
        try:
            jobs.test_label(self.config)
            log.info("Wysłano etykietę testową na %s.", self.config.get("printer_name"))
            if self.tray:
                self.tray.showMessage(APP_NAME, "Wysłano etykietę testową.", QSystemTrayIcon.Information, 3000)
        except Exception as exc:  # noqa: BLE001
            log.error("Wydruk testowy nieudany: %s", exc)
            QMessageBox.warning(self.window, APP_NAME, f"Wydruk testowy nieudany: {exc}")

    def toggle_local_pause(self) -> None:
        self.agent.set_local_pause(not self.agent.local_pause)
        log.info("Wydruki %s na tym komputerze.", "wstrzymane" if self.agent.local_pause else "wznowione")

    def open_panel(self) -> None:
        if self.config.get("base_url"):
            QDesktopServices.openUrl(QUrl(self.config["base_url"] + "/admin/printing.php"))

    def check_updates(self) -> None:
        if updater.platform_id() is None:
            QMessageBox.information(self.window, APP_NAME, "Aktualizacje działają w zainstalowanej wersji programu (.exe / .app).")
            return
        log.info("Sprawdzam aktualizacje…")
        self.agent.request_update_check()

    def _on_update_ready(self, path: str, version: str) -> None:
        try:
            updater.apply(__import__("pathlib").Path(path))
        except Exception as exc:  # noqa: BLE001
            log.error("Nie udało się zainstalować wersji %s: %s", version, exc)
            return
        log.info("Instaluję wersję %s - program uruchomi się ponownie.", version)
        if self.tray:
            self.tray.showMessage(APP_NAME, f"Aktualizacja do wersji {version} — za chwilę uruchomię się ponownie.",
                                  QSystemTrayIcon.Information, 3000)
        QTimer.singleShot(1500, self.quit)

    def quit(self) -> None:
        self.agent.stop()
        self.thread.wait(5000)
        if self.tray:
            self.tray.hide()
        self.app.quit()
