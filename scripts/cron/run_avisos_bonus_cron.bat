@echo off
REM Avisos da oferta de boas-vindas: lembrete do 7.o e do 12.o dia e email de fim.
REM Agende no Agendador de Tarefas do Windows uma vez por dia (ex.: 08:20).
set "ROOT=%~dp0..\..\"
set "SCRIPT_PATH=%ROOT%src\public\cron\avisos_bonus.php"
php "%SCRIPT_PATH%"
