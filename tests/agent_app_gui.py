# Veless (program do drukowania): okna Qt bez ekranu (offscreen) z udawanym serwerem CRM i drukarkami.
# Uruchom: QT_QPA_PLATFORM=offscreen python3 tests/agent_app_gui.py [katalog_na_zrzuty]
import base64
import json
import os
import sys
import tempfile
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
TMP = Path(tempfile.mkdtemp(prefix="crm-gui-test-"))
os.environ["XDG_CONFIG_HOME"] = str(TMP / "cfg")
os.environ["HOME"] = str(TMP / "home")
ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "agent-app"))
SHOTS = Path(sys.argv[1]) if len(sys.argv) > 1 else None

state = {"jobs": [{"id": 11, "format": "ZPL", "filename": "etykieta-1.zpl", "target": "zebra",
                   "content_b64": base64.b64encode(b"^XA^XZ").decode()}], "acks": [], "polls": 0}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code, data):
        body = json.dumps(data).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        u = urlparse(self.path)
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        if q.get("key") != "GOODKEY":
            return self._send(401, {"error": "unauthorized"})
        if u.path.endswith("print_agent_poll.php"):
            state["polls"] += 1
            job = state["jobs"].pop(0) if state["jobs"] else None
            return self._send(200, {"job": job, "next_poll": 1, "mode": "active"})
        if u.path.endswith("agent_update.php"):
            return self._send(200, {"version": None})
        self._send(404, {})

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        state["acks"].append(parse_qs(self.rfile.read(length).decode()))
        self._send(200, {"ok": True})


srv = HTTPServer(("127.0.0.1", 0), Handler)
threading.Thread(target=srv.serve_forever, daemon=True).start()
BASE = f"http://127.0.0.1:{srv.server_port}"

from PySide6.QtCore import QTimer  # noqa: E402
from PySide6.QtWidgets import QApplication, QMessageBox  # noqa: E402
from pase_agent import config, printers  # noqa: E402

printed = []
printers.list_printers = lambda: ["HP LaserJet", "Zebra ZD421"]
printers.send_raw = lambda name, data, doc_name="": printed.append((name, data, doc_name))
QMessageBox.information = staticmethod(lambda *a, **k: None)
warnings = []
QMessageBox.warning = staticmethod(lambda parent, title, text: warnings.append(text))

from pase_agent.gui import Controller  # noqa: E402

app = QApplication(sys.argv)
app.setQuitOnLastWindowClosed(False)
c = Controller(app, start_hidden=False)
w = c.window
ok = 0


def check(cond, label):
    global ok
    if not cond:
        print("FAIL:", label)
        os._exit(1)
    ok += 1
    print("OK:", label)


def wait(ms):
    t = QTimer()
    t.setSingleShot(True)
    loop_done = []
    t.timeout.connect(lambda: loop_done.append(1))
    t.start(ms)
    while not loop_done:
        app.processEvents()


def shot(name):
    if SHOTS:
        w.grab().save(str(SHOTS / f"{name}.png"))


wait(300)
check(w.isVisible() and w.tabs.currentIndex() == 1, "First start: settings tab opened")
check(w.state_label.text() == "Wymaga konfiguracji", "Status: needs configuration")
shot("1-ustawienia-pierwsze")

w.code_edit.setText("zly-kod")
w._apply_code()
check(warnings and "CRM1" in warnings[-1], "Bad connection code rejected")
w.code_edit.setText(config.make_connection_code(BASE, "GOODKEY"))
w._apply_code()
check(w.url_edit.text() == BASE and w.key_edit.text() == "GOODKEY", "Connection code fills address and key")
check([w.zebra_combo.itemText(i) for i in range(w.zebra_combo.count())] == ["HP LaserJet", "Zebra ZD421"], "Printers listed")
w.zebra_combo.setCurrentIndex(w.zebra_combo.findData("Zebra ZD421"))
w.a4_combo.setCurrentIndex(w.a4_combo.findData("HP LaserJet"))
w.dpi_combo.setCurrentIndex(w.dpi_combo.findData(300))
w.width_spin.setValue(50.8)
w.autostart_check.setChecked(False)
w._save()
saved = json.loads(config.config_path().read_text())
check(saved["printer_name"] == "Zebra ZD421" and saved["printer_a4"] == "HP LaserJet" and saved["dpi"] == 300 and saved["label_width_mm"] == 50.8, "Settings saved")

for _ in range(40):
    wait(100)
    if printed and state["acks"]:
        break
check(printed and printed[0][0] == "Zebra ZD421" and printed[0][1] == b"^XA^XZ", "Job from server printed on chosen printer")
check(state["acks"] and state["acks"][0]["status"] == ["done"], "Job acknowledged to server")
wait(300)
check(w.state_label.text() == "Działa — gotowy do druku" and w.info["printed"].text() == "1", "Status shows working and 1 printed")
check("etykieta-1.zpl" in w.info["last_job"].text(), "Last job shown")
shot("2-status")

polls = state["polls"]
c.toggle_local_pause()
wait(400)
check(w.state_label.text() == "Wstrzymane na tym komputerze" and w.pause_btn.text() == "Wznów", "Local pause")
p2 = state["polls"]
wait(1500)
check(state["polls"] == p2, "No server requests while paused locally")
c.toggle_local_pause()
wait(1500)
check(state["polls"] > p2, "Resumed")

c.test_print()
check(printed[-1][1].startswith(b"^XA") and printed[-1][2] == "Test CRM", "Test label")
w.tabs.setCurrentIndex(2)
wait(100)
check("Wydrukowano #11" in w.log_view.toPlainText(), "Log tab shows activity")
shot("3-dziennik")
w.tabs.setCurrentIndex(1)
wait(50)
shot("4-ustawienia")

w.key_edit.setText("BADKEY")
w._save()
for _ in range(30):
    wait(100)
    if w.state_label.text() == "Problem z połączeniem":
        break
check(w.state_label.text() == "Problem z połączeniem" and "klucz" in w.state_msg.text(), "Wrong key: clear status message")

w.close()
check(not w.isVisible(), "Closing window keeps program running in background")
c.quit()
print(f"PASS: {ok} agent GUI checks")
os._exit(0)
