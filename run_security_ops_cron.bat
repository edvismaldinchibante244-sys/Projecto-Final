@echo off
REM Wrapper — implementacao em scripts\cron\
call "%~dp0scripts\cron\run_security_ops_cron.bat" %*
exit /b %ERRORLEVEL%
