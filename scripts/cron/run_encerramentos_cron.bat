@echo off
REM Rotina diaria de encerramento de contas (cancelamentos, avisos e eliminacao com declaracao).
REM Agende no Agendador de Tarefas do Windows uma vez por dia (ex.: 03:30).
set "ROOT=%~dp0..\..\"
set "SCRIPT_PATH=%ROOT%src\public\cron\encerramentos.php"
php "%SCRIPT_PATH%"
