@echo off
REM Cron: notificacao de vencimento / lifecycle de planos
setlocal
pushd "%~dp0..\.."
set "ROOT=%CD%\"
popd

set "SCRIPT_PATH=%ROOT%src\public\cron\notificar_vencimento_planos.php"
set "LOG_DIR=%ROOT%app\storage\logs"
set "LOG_FILE=%LOG_DIR%\plano_lifecycle.log"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

set "PHP_EXE="
if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if exist "C:\php\php.exe" if "%PHP_EXE%"=="" set "PHP_EXE=C:\php\php.exe"
if "%PHP_EXE%"=="" set "PHP_EXE=php"

"%PHP_EXE%" "%SCRIPT_PATH%" %* >> "%LOG_FILE%" 2>&1
exit /b %ERRORLEVEL%
