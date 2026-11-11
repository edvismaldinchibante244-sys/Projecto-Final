@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\start_pos_silent_print.ps1"
if errorlevel 1 pause
endlocal
