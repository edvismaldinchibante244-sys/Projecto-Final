@echo off
REM Wrapper — implementacao em scripts\cron\
call "%~dp0scripts\cron\run_plano_lifecycle_cron.bat" %*
exit /b %ERRORLEVEL%
