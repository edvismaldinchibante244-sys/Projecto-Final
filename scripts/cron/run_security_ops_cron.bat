@echo off
REM Cron: security ops runner (monitor + reactor + preflight)
setlocal
pushd "%~dp0..\.."
set "ROOT=%CD%\"
popd

set "RUNNER=%ROOT%tools\security_ops_runner.php"

if not exist "%RUNNER%" (
  echo Runner nao encontrado: %RUNNER%
  exit /b 1
)

set "PHP_EXE="
if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if exist "C:\php\php.exe" if "%PHP_EXE%"=="" set "PHP_EXE=C:\php\php.exe"
if "%PHP_EXE%"=="" set "PHP_EXE=php"

"%PHP_EXE%" "%RUNNER%" --minutes=10 --threshold=25 --invalid-threshold=30 --block-minutes=30 %*
exit /b %ERRORLEVEL%
