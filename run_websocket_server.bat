@echo off
REM Wrapper — implementacao em scripts\cron\ (corrige path antigo do Desktop)
call "%~dp0scripts\cron\run_websocket_server.bat" %*
exit /b %ERRORLEVEL%
