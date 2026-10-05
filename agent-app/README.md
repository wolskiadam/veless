# Veless — program do drukowania (Windows / Mac)

Program z okienkiem, który pobiera wydruki z CRM i drukuje je na drukarce etykiet (Zebra)
albo zwykłej drukarce A4. Następca skryptu `agent/print_agent.py`.

- uruchamia się po zalogowaniu (Windows: wpis w rejestrze użytkownika, Mac: LaunchAgent),
- działa w tle z ikoną przy zegarze / na górnym pasku; kolor kropki = stan,
- konfiguracja w oknie: kod połączenia z panelu, drukarki, rozdzielczość, szerokość etykiety,
- sam się aktualizuje z panelu (`public/agent_update.php`), sprawdzając sumę SHA-256,
- przy pierwszym uruchomieniu przejmuje `config.json` starego agenta, jeśli leży obok.

Ustawienia i dziennik: Windows `%APPDATA%\Veless`, Mac `~/Library/Application Support/Veless`.

## Budowanie i wydawanie

Robi to GitHub Actions (`.github/workflows/build-agent-app.yml`) po każdej zmianie w `agent-app/`
na `main`: testy → PyInstaller na Windows i Macu → wgranie plików i `manifest.json`
do `storage/agent-builds/` na serwerze. Nową wersję wydaje się, podnosząc `VERSION`
w `pase_agent/__init__.py` — zainstalowane programy pobiorą ją w ciągu kilku godzin
(albo od razu: *Ustawienia → Sprawdź aktualizacje*).

Lokalnie:

```
pip install -r requirements.txt
python main.py                  # okno + ikona
python main.py --background     # tylko ikona
python main.py --test-print     # etykieta testowa bez okna
```

Testy (z katalogu repozytorium): `python tests/agent_app_core.py`, `python tests/agent_app_gui.py`
(na Linuksie bez ekranu: `QT_QPA_PLATFORM=offscreen`).

## Ograniczenia

- Pliki nie mają płatnego podpisu: przy pierwszym uruchomieniu Windows (SmartScreen) i macOS
  (Gatekeeper) pokazują ostrzeżenie — instrukcja jest w panelu, Konfiguracja → Drukowanie.
- Wersja na Maca jest budowana dla procesorów Apple (M1 i nowsze).
