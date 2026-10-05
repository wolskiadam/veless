@echo off
setlocal
title Diagnoza agenta druku Veless
cd /d "%~dp0"
set "LOG=%~dp0diagnoza.txt"

echo Zbieram informacje o Pythonie i bibliotekach...
echo To potrwa kilkanascie sekund.
echo.

echo ===== DIAGNOZA AGENTA DRUKU VELESS ===== > "%LOG%"
echo Data: %DATE% %TIME% >> "%LOG%"
echo Folder: %~dp0 >> "%LOG%"
echo. >> "%LOG%"

echo --- gdzie jest python --- >> "%LOG%"
where python >> "%LOG%" 2>&1
echo. >> "%LOG%"
echo --- gdzie jest py --- >> "%LOG%"
where py >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- py -3 --version --- >> "%LOG%"
py -3 --version >> "%LOG%" 2>&1
echo. >> "%LOG%"
echo --- python --version --- >> "%LOG%"
python --version >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- szczegoly interpretera (py -3) --- >> "%LOG%"
py -3 -c "import sys; print(sys.executable); print(sys.version); print(sys.path)" >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- zainstalowane pakiety --- >> "%LOG%"
py -3 -m pip list >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- test importow PRZED instalacja --- >> "%LOG%"
py -3 -c "import requests; print('requests OK', requests.__version__)" >> "%LOG%" 2>&1
py -3 -c "import win32print; print('pywin32 OK')" >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- proba instalacji (pelne logi) --- >> "%LOG%"
py -3 -m pip install --disable-pip-version-check requests pywin32 pypdfium2 Pillow >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- proba instalacji --user --- >> "%LOG%"
py -3 -m pip install --user --disable-pip-version-check requests pywin32 pypdfium2 Pillow >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- test importow PO instalacji --- >> "%LOG%"
py -3 -c "import requests, win32print, pypdfium2, PIL; print('WSZYSTKO OK - biblioteki dzialaja')" >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo --- test polaczenia z pypi.org --- >> "%LOG%"
ping -n 2 pypi.org >> "%LOG%" 2>&1
echo. >> "%LOG%"

echo ===== KONIEC ===== >> "%LOG%"

type "%LOG%"
echo.
echo ==========================================
echo  Zapisano raport: %LOG%
echo  Jesli na koncu widzisz "WSZYSTKO OK" - uruchom start_agent.bat
echo  Jesli nie - przeslij plik diagnoza.txt
echo ==========================================
pause
