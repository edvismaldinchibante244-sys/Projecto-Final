@echo off
REM Wrapper — implementacao em scripts\cron\
call "%~dp0scripts\cron\run_notificar_vencimento_planos.bat" %*
exit /b %ERRORLEVEL%
