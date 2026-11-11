@echo off
REM Alias: mesmo job que run_plano_lifecycle_cron.bat
call "%~dp0run_plano_lifecycle_cron.bat" %*
exit /b %ERRORLEVEL%
