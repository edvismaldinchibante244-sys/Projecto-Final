@echo off
REM Wrapper — implementacao em scripts\cron\ (nao remover: Agendador pode apontar aqui)
call "%~dp0scripts\cron\run_backup_cron.bat" %*
exit /b %ERRORLEVEL%
