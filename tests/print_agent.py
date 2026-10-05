# Agent drukarki: tempo odpytywania (next_poll), wstrzymanie, wersja - bez Windows i sieci.
# Uruchom: python3 tests/print_agent.py
import sys, types, base64, io, contextlib
# stub win32print + requests (brak Windows / sieci w teście)
sys.modules['win32print'] = types.ModuleType('win32print')
req = types.ModuleType('requests')
class RequestException(Exception): pass
req.RequestException = RequestException
calls = {'get': [], 'post': []}
responses = []
class Resp:
    def __init__(self, data): self._d = data
    def json(self): return self._d
    def raise_for_status(self): pass
def get(url, params=None, timeout=None):
    calls['get'].append(params); return Resp(responses.pop(0))
def post(url, data=None, timeout=None):
    calls['post'].append(data); return Resp({})
req.get, req.post = get, post
sys.modules['requests'] = req
import os
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'agent'))
import print_agent as a
printed = []
a.print_job = lambda config, content, fmt, target, filename: printed.append((fmt, target, content))
cfg = {'base_url': 'https://x', 'api_key': 'k', 'printer_name': 'Zebra'}
ok = 0
def check(c, label):
    global ok
    assert c, label; ok += 1; print('OK:', label)

responses.append({'job': None})                      # stary serwer - bez next_poll
check(a.poll_once(cfg) is None, 'Old server: default interval')
check(calls['get'][-1]['v'] == a.AGENT_VERSION == '1.1', 'Agent reports its version')
responses.append({'job': None, 'next_poll': 30})
check(a.poll_once(cfg) == 30, 'Idle: waits 30 s as told')
responses.append({'job': None, 'next_poll': 99999})
check(a.poll_once(cfg) == a.MAX_POLL_SECONDS, 'Absurd value capped')
responses.append({'job': None, 'next_poll': 'abc'})
check(a.poll_once(cfg) is None, 'Junk value ignored')
buf = io.StringIO()
with contextlib.redirect_stdout(buf):
    responses.append({'job': None, 'paused': True, 'next_poll': 30}); a.poll_once(cfg)
    responses.append({'job': None, 'paused': True, 'next_poll': 30}); a.poll_once(cfg)
    responses.append({'job': None, 'next_poll': 4}); a.poll_once(cfg)
out = buf.getvalue()
check(out.count('[PAUZA]') == 1 and out.count('[WZNOWIONO]') == 1, 'Pause/resume message printed once per change')
job = {'id': 7, 'format': 'ZPL', 'filename': 'e.zpl', 'target': 'zebra', 'content_b64': base64.b64encode(b'^XA^XZ').decode()}
with contextlib.redirect_stdout(io.StringIO()):
    responses.append({'job': job, 'next_poll': 1}); w = a.poll_once(cfg)
check(w == 1 and printed[-1] == ('ZPL', 'zebra', b'^XA^XZ') and calls['post'][-1]['status'] == 'done', 'Job printed, acked, next poll immediately')
responses.append({'error': 'unauthorized'})
with contextlib.redirect_stdout(io.StringIO()):
    check(a.poll_once(cfg) is None, 'Auth error: default interval')
print(f'PASS: {ok} agent checks')
