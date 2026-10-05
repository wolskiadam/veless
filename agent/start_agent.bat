@echo off
title Agent druku Veless
cd /d "%~dp0"

rem Ta sama sztuczka co w install.bat: sprawdzamy, ktora komenda REALNIE
rem uruchamia Pythona (a nie jest zaslepka Microsoft Store).
py -3 -c "import sys" >nul 2>&1
if not errorlevel 1 goto RUNPY

python -c "import sys" >nul 2>&1
if not errorlevel 1 goto RUNPYTHON

echo Nie znaleziono Pythona. Uruchom najpierw install.bat
pause
exit /b 1

:RUNPY
py -3 print_agent.py
goto END

:RUNPYTHON
python print_agent.py

:END
pause
