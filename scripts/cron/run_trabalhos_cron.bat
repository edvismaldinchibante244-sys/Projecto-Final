@echo off
REM Trabalhos em segundo plano (exportacao de dados). Agende a cada minuto no Agendador de Tarefas.
set "ROOT=%~dp0..\..\"
php "%ROOT%src\public\cron\trabalhos.php"
