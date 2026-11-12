@echo off
REM Wrapper — implementacao em scripts\cron\ (nao remover: Agendador pode apontar aqui)
call "%~dp0scripts\cron\run_avisos_bonus_cron.bat" %*
exit /b %ERRORLEVEL%
