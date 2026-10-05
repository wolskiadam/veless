"""Veless (program do drukowania) - uruchomienie.

    Veless                okno + ikona przy zegarze
    Veless --background   start w tle (autostart) - tylko ikona
    Veless --test-print   etykieta testowa bez okna (diagnostyka)
"""

from __future__ import annotations

import argparse
import logging
import sys
from logging.handlers import RotatingFileHandler

from pase_agent import APP_NAME, VERSION, config

SERVER_NAME = "crm-agent-single-instance"


def setup_logging() -> None:
    logger = logging.getLogger("pase_agent")
    logger.setLevel(logging.INFO)
    handler = RotatingFileHandler(config.log_path(), maxBytes=1_000_000, backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    logger.addHandler(handler)
    if sys.stderr is not None and not getattr(sys, "frozen", False):
        logger.addHandler(logging.StreamHandler())


def main() -> int:
    parser = argparse.ArgumentParser(prog="Veless", description=f"{APP_NAME} {VERSION}")
    parser.add_argument("--background", action="store_true", help="start w tle (tylko ikona)")
    parser.add_argument("--test-print", action="store_true", help="etykieta testowa i koniec")
    parser.add_argument("--version", action="version", version=f"{APP_NAME} {VERSION}")
    args, _qt_args = parser.parse_known_args()
    setup_logging()

    if args.test_print:
        from pase_agent import jobs
        jobs.test_label(config.load())
        if sys.stdout is not None:
            print("Wysłano etykietę testową.")
        return 0

    from PySide6.QtNetwork import QLocalServer, QLocalSocket
    from PySide6.QtWidgets import QApplication

    # Tylko jedna kopia programu: kolejne uruchomienie prosi działającą o pokazanie okna.
    probe = QLocalSocket()
    probe.connectToServer(SERVER_NAME)
    if probe.waitForConnected(300):
        probe.write(b"show")
        probe.waitForBytesWritten(300)
        return 0

    app = QApplication(sys.argv)
    app.setApplicationName(APP_NAME)
    app.setQuitOnLastWindowClosed(False)

    from pase_agent import icons
    from pase_agent.gui import Controller
    app.setWindowIcon(icons.app_icon())
    controller = Controller(app, start_hidden=args.background)

    QLocalServer.removeServer(SERVER_NAME)
    server = QLocalServer()
    server.listen(SERVER_NAME)
    server.newConnection.connect(lambda: (server.nextPendingConnection(), controller.show_window()))

    return app.exec()


if __name__ == "__main__":
    sys.exit(main())
