"""Pętla pracy agenta - niezależna od okien (GUI podpina się przez funkcje zwrotne).

Co przebieg: pyta serwer o wydruk -> drukuje -> potwierdza -> czeka tyle, ile każe serwer
(next_poll: częściej, gdy ktoś pracuje w panelu, rzadko, gdy nikt nie pracuje).
Co kilka godzin sprawdza, czy w panelu jest nowsza wersja programu.
"""

from __future__ import annotations

import datetime as dt
import logging
import threading
import time
from typing import Callable, Optional

import requests

from . import VERSION, updater
from .client import Client, ServerError
from .config import is_configured
from .jobs import print_job

log = logging.getLogger("pase_agent")

RETRY_NETWORK = 10       # s - brak internetu / serwer nie odpowiada
RETRY_SERVER_ERROR = 30  # s - np. zły klucz
UPDATE_EVERY = 6 * 3600  # s - sprawdzanie aktualizacji


class Agent:
    def __init__(self, get_config: Callable[[], dict],
                 on_status: Callable[[dict], None] = lambda s: None,
                 on_update_ready: Callable[[str, str], None] = lambda path, version: None,
                 client_factory=Client, printer=print_job, clock=time.time):
        self.get_config = get_config
        self.on_status = on_status
        self.on_update_ready = on_update_ready
        self.client_factory = client_factory
        self.printer = printer
        self.clock = clock
        self._wake = threading.Event()
        self._stop = threading.Event()
        self.local_pause = False
        self.state: dict = {"state": "starting", "message": "Uruchamianie…", "last_poll": None, "next_poll": None,
                            "printed_today": 0, "day": dt.date.today().isoformat(), "last_job": "", "version": VERSION}
        self._last_update_check = 0.0
        self.update_check_requested = False

    # ------------------------------------------------------------- sterowanie z GUI
    def wake(self) -> None:
        """Zapytaj serwer od razu (np. po zapisaniu ustawień albo wznowieniu)."""
        self._wake.set()

    def stop(self) -> None:
        self._stop.set()
        self._wake.set()

    def set_local_pause(self, paused: bool) -> None:
        self.local_pause = paused
        self.wake()

    def request_update_check(self) -> None:
        self.update_check_requested = True
        self.wake()

    # ------------------------------------------------------------- pętla
    def run(self) -> None:
        while not self._stop.is_set():
            wait = self.step()
            self._wake.clear()
            self._wake.wait(wait)

    def _set(self, **changes) -> None:
        today = dt.date.today().isoformat()
        if self.state.get("day") != today:
            self.state.update(day=today, printed_today=0)
        self.state.update(changes)
        self.on_status(dict(self.state))

    def step(self) -> float:
        """Jeden przebieg. Zwraca, ile sekund czekać do następnego."""
        config = self.get_config()
        if not is_configured(config):
            self._set(state="unconfigured", message="Podaj kod połączenia i wybierz drukarkę w Ustawieniach.")
            return 5
        if self.local_pause:
            self._set(state="paused_local", message="Wstrzymane na tym komputerze.", next_poll=None)
            return 3600
        client = self.client_factory(config)
        try:
            self._maybe_update(client)
            result = client.poll()
        except requests.RequestException as exc:
            log.warning("Brak połączenia: %s", exc)
            self._set(state="error", message="Brak połączenia z serwerem - ponawiam za chwilę.", next_poll=RETRY_NETWORK)
            return RETRY_NETWORK
        except ServerError as exc:
            log.error("%s", exc)
            self._set(state="error", message=str(exc), next_poll=RETRY_SERVER_ERROR)
            return RETRY_SERVER_ERROR

        now = self.clock()
        if result.paused:
            self._set(state="paused_server", message="Wydruki wstrzymane w panelu CRM.", last_poll=now, next_poll=result.next_poll)
            return result.next_poll
        if result.job:
            self._handle_job(client, config, result)
        idle = result.mode == "idle" if result.mode else result.next_poll > 10
        self._set(state="idle" if idle else "working",
                  message=("Nikt nie pracuje w panelu - sprawdzam rzadziej." if idle else "Gotowy do druku."),
                  last_poll=now, next_poll=result.next_poll)
        return result.next_poll

    def _handle_job(self, client, config: dict, result) -> None:
        job = result.job
        job_id = int(job["id"])
        name = job.get("filename") or f"zadanie-{job_id}"
        target = (job.get("target") or "zebra").lower()
        where = "drukarka A4" if target == "a4" else "drukarka etykiet"
        log.info("Drukuję #%s (%s, %s) -> %s", job_id, job.get("format"), name, where)
        try:
            self.printer(config, result.content, job.get("format") or "", target, name, log.info)
        except Exception as exc:  # noqa: BLE001 - jedno nieudane zadanie nie zatrzymuje programu
            log.error("Zadanie #%s nieudane: %s", job_id, exc)
            self._set(last_job=f"✗ {name}: {exc}")
            try:
                client.ack(job_id, False, str(exc))
            except requests.RequestException:
                pass  # zadanie i tak wróci do kolejki po stronie serwera
            return
        try:
            client.ack(job_id, True)
        except requests.RequestException:
            log.warning("Wydrukowano #%s, ale nie udało się potwierdzić - serwer może je powtórzyć.", job_id)
        log.info("Wydrukowano #%s", job_id)
        self._set(printed_today=self.state["printed_today"] + 1, last_job=f"✓ {name} ({where})")

    def _maybe_update(self, client) -> None:
        config = self.get_config()
        platform = updater.platform_id()
        due = self.clock() - self._last_update_check >= UPDATE_EVERY
        if platform is None or not (self.update_check_requested or (config.get("auto_update") and due)):
            return
        self.update_check_requested = False
        self._last_update_check = self.clock()
        try:
            info = client.check_update(platform)
        except (requests.RequestException, ServerError) as exc:
            log.warning("Nie udało się sprawdzić aktualizacji: %s", exc)
            return
        if not info or not updater.is_newer(info["version"]):
            log.info("Program jest aktualny (%s).", VERSION)
            return
        log.info("Dostępna wersja %s - pobieram.", info["version"])
        try:
            path = updater.download_verified(client, platform, info)
        except Exception as exc:  # noqa: BLE001
            log.error("Aktualizacja nieudana: %s", exc)
            return
        self.on_update_ready(str(path), str(info["version"]))
