# Veless (program do drukowania) (agent-app/): rdzeń bez okien - konfiguracja, serwer, pętla, druk CUPS, autostart, aktualizacje.
# Bez Windows, Maca, drukarki i sieci. Uruchom: python3 tests/agent_app_core.py
import base64
import hashlib
import io
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "agent-app"))
TMP = Path(tempfile.mkdtemp(prefix="crm-agent-test-"))
os.environ["XDG_CONFIG_HOME"] = str(TMP / "cfg")
os.environ["HOME"] = str(TMP / "home")

import requests  # noqa: E402
from pase_agent import VERSION, autostart, config, printers, updater  # noqa: E402
from pase_agent.agent import Agent  # noqa: E402
from pase_agent.client import Client, ServerError  # noqa: E402
from pase_agent.rendering import image_to_zpl, needs_rasterizing, content_to_zpl_pages  # noqa: E402

ok = 0


def check(cond, label):
    global ok
    if not cond:
        raise SystemExit("FAIL: " + label)
    ok += 1
    print("OK:", label)


# --- konfiguracja ---
code = config.make_connection_code("https://sklep.pl/crm/public/", "KEY123")
check(config.parse_connection_code(code) == ("https://sklep.pl/crm/public", "KEY123"), "Connection code round-trip")
for bad in ("", "abc", "CRM1:!!!", config.CODE_PREFIX + base64.urlsafe_b64encode(b'{"u":"ftp://x","k":"k"}').decode()):
    try:
        config.parse_connection_code(bad)
        check(False, "Bad code rejected")
    except ValueError:
        pass
check(True, "Invalid connection codes rejected with message")
n = config.normalize({"dpi": "abc", "label_width_mm": "50,8", "threshold": -3, "base_url": " https://a/ ", "evil": 1})
check(n["dpi"] == 203 and n["label_width_mm"] == 50.8 and n["threshold"] == 160 and n["base_url"] == "https://a" and "evil" not in n, "Config normalized")
legacy = TMP / "legacy" / "config.json"
legacy.parent.mkdir()
legacy.write_text(json.dumps({"base_url": "https://old", "api_key": "OLD", "printer_name": "Zebra ZD420", "printer_a4": "HP"}))
with mock.patch.object(config, "legacy_config_candidates", lambda: [legacy]):
    cfg = config.load()
check(cfg["api_key"] == "OLD" and cfg["printer_name"] == "Zebra ZD420", "Settings taken over from old agent on first start")
check(config.app_dir().name == "Veless"
      and config.app_dir().parent / "CRM Agent" / "config.json" in config.legacy_config_candidates(),
      "Settings of 2.0 (folder 'CRM Agent') are taken over after the rename")
config.save(dict(cfg, printer_a4="Brother"))
check(config.load()["printer_a4"] == "Brother" and config.config_path().is_file(), "Settings saved in user data folder")


# --- serwer ---
class Resp:
    def __init__(self, data, status=200):
        self._d, self.status_code, self.headers = data, status, {}

    def json(self):
        if isinstance(self._d, Exception):
            raise self._d
        return self._d

    def raise_for_status(self):
        if self.status_code >= 400:
            raise requests.HTTPError(str(self.status_code))


class FakeSession:
    def __init__(self):
        self.headers, self.gets, self.posts, self.queue = {}, [], [], []

    def get(self, url, params=None, timeout=None, stream=False):
        self.gets.append((url, params))
        r = self.queue.pop(0)
        if isinstance(r, Exception):
            raise r
        return r

    def post(self, url, data=None, timeout=None):
        self.posts.append((url, data))
        return Resp({})


cfg = config.normalize({"base_url": "https://crm", "api_key": "K", "printer_name": "Zebra", "printer_a4": ""})
s = FakeSession()
cl = Client(cfg, s)
s.queue.append(Resp({"job": None, "next_poll": 30, "mode": "idle"}))
r = cl.poll()
check(r.job is None and r.next_poll == 30 and r.mode == "idle", "Poll: server tells when to ask again")
check(s.gets[-1][1]["v"] == VERSION and s.gets[-1][1]["a4"] == "0" and s.gets[-1][0] == "https://crm/print_agent_poll.php", "Poll reports version and station")
s.queue.append(Resp({"job": None}))
check(cl.poll().next_poll == 4, "Old server without next_poll: 4 s")
s.queue.append(Resp({"error": "unauthorized"}, 401))
try:
    cl.poll()
    check(False, "401")
except ServerError as e:
    check("kod połączenia" in str(e), "Wrong key: clear message")


# --- pętla ---
printed, statuses = [], []


def fake_printer(conf, content, fmt, target, name, log):
    if name == "zepsute.pdf":
        raise RuntimeError("Drukarka offline")
    printed.append((content, fmt, target, name))


def make_agent(session, conf):
    return Agent(lambda: conf, on_status=statuses.append, client_factory=lambda c: Client(c, session), printer=fake_printer)


s = FakeSession()
a = make_agent(s, config.normalize({}))
check(a.step() == 5 and statuses[-1]["state"] == "unconfigured", "Not configured: asks for connection code, no requests")
check(s.gets == [], "No server requests before configuration")
a = make_agent(s, cfg)
job = {"id": 7, "format": "ZPL", "filename": "etykieta.zpl", "target": "zebra", "content_b64": base64.b64encode(b"^XA^XZ").decode()}
s.queue.append(Resp({"job": job, "next_poll": 1, "mode": "active"}))
check(a.step() == 1 and printed[-1] == (b"^XA^XZ", "ZPL", "zebra", "etykieta.zpl"), "Job printed, next ask right away")
check(s.posts[-1][1] == {"key": "K", "job_id": 7, "status": "done"} and statuses[-1]["printed_today"] == 1, "Job acknowledged and counted")
s.queue.append(Resp({"job": dict(job, id=8, filename="zepsute.pdf"), "next_poll": 4, "mode": "active"}))
a.step()
check(s.posts[-1][1]["status"] == "failed" and "offline" in s.posts[-1][1]["error"] and statuses[-1]["state"] == "working", "Failed job reported, agent keeps running")
s.queue.append(Resp({"job": None, "next_poll": 30, "mode": "idle"}))
check(a.step() == 30 and statuses[-1]["state"] == "idle", "Idle mode shown")
s.queue.append(Resp({"job": None, "paused": True, "next_poll": 30}))
check(a.step() == 30 and statuses[-1]["state"] == "paused_server", "Paused in panel shown")
s.queue.append(requests.ConnectionError("down"))
check(a.step() == 10 and statuses[-1]["state"] == "error", "Network error: retry in 10 s")
a.set_local_pause(True)
n_gets = len(s.gets)
check(a.step() == 3600 and statuses[-1]["state"] == "paused_local" and len(s.gets) == n_gets, "Local pause: no requests at all")


# --- druk ---
from PIL import Image  # noqa: E402

img = Image.new("RGB", (400, 200), "white")
for x in range(50, 150):
    for y in range(50, 150):
        img.putpixel((x, y), (0, 0, 0))
zpl = image_to_zpl(img, 203, 50.8, 160)
check(zpl.startswith(b"^XA^PW406") and b"^GFA," in zpl and zpl.endswith(b"^XZ"), "Image -> ZPL graphic (50.8 mm @ 203 dpi = 406 dots)")
buf = io.BytesIO()
img.save(buf, format="PDF")
pages = content_to_zpl_pages(buf.getvalue(), "PDF", cfg)
check(len(pages) == 1 and pages[0].startswith(b"^XA"), "PDF rendered to ZPL")
check(needs_rasterizing(b"%PDF-1.4", "LBL") and not needs_rasterizing(b"^XA", "ZPL"), "Rasterize only graphics")
calls = []
with mock.patch.object(printers, "IS_WINDOWS", False), \
     mock.patch.object(printers.subprocess, "run", lambda cmd, **kw: calls.append((cmd, kw.get("input"))) or subprocess.CompletedProcess(cmd, 0, b"", b"")):
    printers.send_raw("Zebra_ZD420", b"^XA^XZ", "etykieta")
    printers.print_document("HP_A4", buf.getvalue(), "PDF", "karta")
check(calls[0] == (["lp", "-d", "Zebra_ZD420", "-o", "raw", "-t", "etykieta"], b"^XA^XZ"), "Mac/CUPS: raw ZPL via lp -o raw")
check(calls[1][0][:6] == ["lp", "-d", "HP_A4", "-o", "fit-to-page", "-t"] and calls[1][0][-1].endswith(".pdf"), "Mac/CUPS: A4 document fit to page")
with mock.patch.object(printers, "IS_WINDOWS", False), \
     mock.patch.object(printers.subprocess, "run", lambda cmd, **kw: subprocess.CompletedProcess(cmd, 1, b"", b"printer not found")):
    try:
        printers.send_raw("X", b"^XA")
        check(False, "lp error")
    except RuntimeError as e:
        check("printer not found" in str(e), "Printer error message passed through")
with mock.patch.object(printers.subprocess, "run", lambda cmd, **kw: subprocess.CompletedProcess(cmd, 0, "Zebra_ZD420\nHP_LaserJet\n", "")):
    check(printers._cups_printers() == ["HP_LaserJet", "Zebra_ZD420"], "Mac/CUPS: printers listed")


# --- autostart (macOS) ---
with mock.patch.object(autostart.sys, "platform", "darwin"):
    autostart.set_enabled(True)
    check(autostart.is_enabled(), "macOS autostart: LaunchAgent created")
    import plistlib
    plist = plistlib.loads(autostart.plist_path().read_bytes())
    check(plist["RunAtLoad"] is True and "--background" in plist["ProgramArguments"], "LaunchAgent starts in background at login")
    autostart.set_enabled(False)
    check(not autostart.is_enabled(), "macOS autostart: removed")


# --- aktualizacje ---
check(updater.is_newer("2.0.10", "2.0.9") and not updater.is_newer("2.0.0", "2.0.0") and not updater.is_newer("1.9", "2.0.0"), "Version comparison")
check(updater.platform_id() is None, "Running from source: no self-update")
payload = b"new program"


class DLClient:
    def download_update(self, platform, dest, progress=None):
        Path(dest).write_bytes(payload)


good = updater.download_verified(DLClient(), "windows", {"sha256": hashlib.sha256(payload).hexdigest()})
check(good.read_bytes() == payload, "Update downloaded and verified")
try:
    updater.download_verified(DLClient(), "windows", {"sha256": "0" * 64})
    check(False, "sha")
except RuntimeError as e:
    check("uszkodzony" in str(e), "Corrupted update rejected")

print(f"PASS: {ok} agent core checks")
