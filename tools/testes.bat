@echo off
REM ============================================================
REM  Corre os testes automaticos no computador local (XAMPP).
REM  Usa uma base DESCARTAVEL chamada nivora_test, recriada a cada
REM  execucao. A base real (restaurante_saas) NUNCA e tocada.
REM
REM  Uso (na pasta V00):   tools\testes.bat
REM  Se o root do MySQL tiver senha:  set DB_PASS=a_senha  antes.
REM ============================================================
setlocal
cd /d "%~dp0\.."

set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
if not exist "%MYSQL%" (
    echo Nao encontrei %MYSQL%. Ajuste a variavel MYSQL neste ficheiro.
    exit /b 1
)

if not exist phpunit.phar (
    echo A descarregar o PHPUnit 9.6...
    curl -sSL -o phpunit.phar https://phar.phpunit.de/phpunit-9.6.phar || (echo Falhou o download do PHPUnit. & exit /b 1)
)

set "APP_ENV=local"
set "DB_HOST=127.0.0.1"
set "DB_NAME=nivora_test"
if not defined DB_USER set "DB_USER=root"
set "PWARG="
if defined DB_PASS set "PWARG=-p%DB_PASS%"

echo A recriar a base de teste nivora_test...
"%MYSQL%" -h 127.0.0.1 -u %DB_USER% %PWARG% -e "DROP DATABASE IF EXISTS nivora_test; CREATE DATABASE nivora_test CHARACTER SET utf8mb4" || exit /b 1
"%MYSQL%" -h 127.0.0.1 -u %DB_USER% %PWARG% nivora_test < db\schema_base.sql || exit /b 1
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_test --json > nul || (echo As migracoes falharam na base de teste. & exit /b 1)

php phpunit.phar --testdox %*
