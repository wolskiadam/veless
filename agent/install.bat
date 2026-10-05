@echo off
setlocal
title Instalacja agenta druku Veless
cd /d "%~dp0"

echo ==========================================
echo    Instalacja agenta druku Veless
echo ==========================================
echo.

rem ---------- 1. Python ----------
rem W Windows 10/11 komenda "python" prawie zawsze ISTNIEJE, bo system podstawia
rem zaslepke Microsoft Store, ktora tylko wypisuje komunikat i nic nie uruchamia.
rem Dlatego sprawdzamy, czy interpreter NAPRAWDE wykonuje kod. Najpierw launcher
rem "py" (python.org instaluje go do System32, wiec dziala nawet bez PATH).
set "PYCMD="

py -3 -c "import sys" >nul 2>&1
if not errorlevel 1 set "PYCMD=py -3"
if defined PYCMD goto HAVEPY

python -c "import sys" >nul 2>&1
if not errorlevel 1 set "PYCMD=python"
if defined PYCMD goto HAVEPY

goto NOPYTHON

:HAVEPY
for /f "tokens=*" %%v in ('%PYCMD% --version 2^>^&1') do set "PYVER=%%v"
echo [1/4] Python: %PYVER%
%PYCMD% -c "import sys; print('      ' + sys.executable)"
goto DEPS

:NOPYTHON
echo [1/4] Nie znaleziono dzialajacej instalacji Pythona.
where winget >nul 2>&1
if errorlevel 1 goto PYMANUAL
echo       Probuje zainstalowac automatycznie (winget)...
winget install -e --id Python.Python.3.12 --accept-package-agreements --accept-source-agreements
if errorlevel 1 goto PYMANUAL
echo.
echo Python zostal zainstalowany.
echo ZAMKNIJ to okno i uruchom install.bat jeszcze raz.
pause
exit /b 0

:PYMANUAL
echo.
echo Zainstaluj Pythona recznie:
echo   1. Wejdz na https://www.python.org/downloads/
echo   2. Pobierz "Download Python 3.x" i uruchom instalator
echo   3. WAZNE: na pierwszym ekranie zaznacz "Add python.exe to PATH"
echo   4. Po instalacji uruchom install.bat jeszcze raz
echo.
echo Jesli Windows wypisuje "nie znaleziono Python; uruchom bez argumentow,
echo aby zainstalowac ze sklepu" - to wlasnie zaslepka Microsoft Store.
echo Mozna ja wylaczyc: Ustawienia ^> Aplikacje ^> Zaawansowane ustawienia
echo aplikacji ^> Aliasy wykonywania aplikacji ^> wylacz python.exe i python3.exe
echo.
pause
exit /b 1

rem ---------- 2. biblioteki ----------
rem Bez --quiet: jesli pip padnie, chcemy widziec powod na ekranie.
:DEPS
echo.
echo [2/4] Instaluje biblioteki (requests, pywin32, pypdfium2, Pillow)...
echo.
%PYCMD% -m pip install --disable-pip-version-check requests pywin32 pypdfium2 Pillow
if errorlevel 1 goto DEPSRETRY
goto DEPSCHECK

:DEPSRETRY
echo.
echo       Standardowa instalacja nieudana (czesto brak uprawnien do katalogu
echo       Pythona). Probuje jeszcze raz w trybie --user...
echo.
%PYCMD% -m pip install --user --disable-pip-version-check requests pywin32 pypdfium2 Pillow
if errorlevel 1 goto DEPSFAIL
goto DEPSCHECK

:DEPSCHECK
%PYCMD% -c "import requests, win32print, pypdfium2, PIL" >nul 2>&1
if errorlevel 1 goto DEPSFAIL
echo.
echo       Biblioteki OK
goto CONFIG

:DEPSFAIL
echo.
echo ------------------------------------------
echo  Nie udalo sie zainstalowac bibliotek.
echo ------------------------------------------
echo Powod powinien byc widoczny powyzej. Najczestsze przypadki:
echo   - brak internetu albo firmowy serwer proxy blokuje pip
echo   - antywirus/firewall blokuje polaczenie z pypi.org
echo   - brak uprawnien - sprobuj uruchomic install.bat jako administrator
echo.
echo Uruchom diagnoza.bat - zapisze plik diagnoza.txt z pelnymi szczegolami.
echo.
pause
exit /b 1

rem ---------- 3. konfiguracja ----------
:CONFIG
echo.
echo [3/4] Konfiguracja agenta
echo.
%PYCMD% print_agent.py --reconfigure
if errorlevel 1 goto CFGFAIL
goto AUTOSTART

:CFGFAIL
echo.
echo Konfiguracja przerwana - uruchom install.bat jeszcze raz.
pause
exit /b 1

rem ---------- 4. autostart ----------
:AUTOSTART
echo [4/4] Dodaje agenta do autostartu Windows...
powershell -NoProfile -Command "$w=New-Object -ComObject WScript.Shell; $s=$w.CreateShortcut([Environment]::GetFolderPath('Startup')+'\PASE Agent druku.lnk'); $s.TargetPath='%~dp0start_agent.bat'; $s.WorkingDirectory='%~dp0'; $s.Save()"
if errorlevel 1 goto NOAUTOSTART
echo       OK
goto TESTPRINT

:NOAUTOSTART
echo       Nie udalo sie dodac skrotu - mozesz zrobic to recznie:
echo       Win+R ^> shell:startup ^> wrzuc tam skrot do start_agent.bat
goto TESTPRINT

:TESTPRINT
echo.
echo ==========================================
echo    Gotowe. Agent wystartuje z Windows.
echo ==========================================
echo.
echo Wysylam etykiete testowa na drukarke...
%PYCMD% print_agent.py --test-print
echo.
echo Mozesz zamknac to okno. Agenta uruchomisz dwuklikiem w start_agent.bat
echo (albo sam wstanie po nastepnym zalogowaniu do Windows).
pause
