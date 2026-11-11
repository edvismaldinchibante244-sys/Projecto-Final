@echo off
REM Rotina diaria de manutencao: resumo diario de vendas e limpeza de registos antigos.
REM Agende no Agendador de Tarefas do Windows uma vez por dia (ex.: 04:15).
set "ROOT=%~dp0..\..\"
set "SCRIPT_PATH=%ROOT%src\public\cron\manutencao.php"
php "%SCRIPT_PATH%"
