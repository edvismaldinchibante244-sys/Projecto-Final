@echo off
REM Cron: backup automatico
REM Localizacao real: scripts\cron\  |  Wrapper na raiz: run_backup_cron.bat
setlocal
pushd "%~dp0..\.."
set "ROOT=%CD%\"
popd

set "SCRIPT_PATH=%ROOT%src\public\cron\backup_automatico.php"
set "PHP_PATH="

if exist "C:\xampp\php\php.exe" set "PHP_PATH=C:\xampp\php\php.exe"
if exist "C:\php\php.exe" set "PHP_PATH=C:\php\php.exe"
if "%PHP_PATH%"=="" (
  where php >nul 2>&1 && for /f "delims=" %%i in ('where php') do (
    set "PHP_PATH=%%i"
    goto :run
  )
)

:run
if "%PHP_PATH%"=="" (
  echo [ERRO] php.exe nao encontrado.
  exit /b 1
)

"%PHP_PATH%" "%SCRIPT_PATH%" %*
exit /b %ERRORLEVEL%
