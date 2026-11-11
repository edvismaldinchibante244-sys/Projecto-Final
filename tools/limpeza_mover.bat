@echo off
REM ============================================================
REM  Limpeza do projeto: MOVE (nao apaga) ficheiros sem uso para
REM  V00\_to_delete\, mantendo a estrutura de pastas.
REM  Todos foram verificados: nenhum codigo, cron ou doc os referencia.
REM
REM  Depois de mover: use o sistema uns dias e corra tools\testes.bat.
REM  Se algo falhar, volte a mover o ficheiro do _to_delete para o sitio.
REM  Quando tiver certeza, apague a pasta _to_delete.
REM ============================================================
setlocal EnableDelayedExpansion
cd /d "%~dp0\.."
set /a MOVIDOS=0
set /a AUSENTES=0

echo.
echo == Paginas antigas ainda acessiveis pelo browser
call :mover "src\public\funcionarios_turnos_fixed.php" "src\public"
call :mover "src\public\produtos_ia_helper.php" "src\public"
call :mover "src\public\produtos_ia_interface.html" "src\public"
call :mover "src\public\mesas.js" "src\public"
call :mover "src\public\enable_qr_plano.php" "src\public"
call :mover "src\public\redefinir_senha_admin.php" "src\public"
call :mover "src\public\reset_senha.php" "src\public"

echo.
echo == Configuracao duplicada / scripts de uma so vez
call :mover "app\src\config\database_local.php" "app\src\config"
call :mover "app\src\config\list_usuarios_cli.php" "app\src\config"
call :mover "app\src\config\test_email_cli.php" "app\src\config"
call :mover "app\src\config\enable_2fa_all_users.php" "app\src\config"
call :mover "app\src\config\run_schema_garcom.php" "app\src\config"
call :mover "app\src\config\run_schema_planos.php" "app\src\config"
call :mover "app\src\config\run_login_security_schema.php" "app\src\config"
call :mover "app\config_legacy_backup" "app"

echo.
echo == Logs de debug
call :mover "app\debug_email.log" "app"
call :mover "app\debug_produto_api.log" "app"
call :mover "app\debug_produto_listar.log" "app"

echo.
echo == Ferramentas de diagnostico/correccao pontuais (sem referencias)
call :mover "tools\check_comprovativo_66.php" "tools"
call :mover "tools\check_comprovativo_arquivo.php" "tools"
call :mover "tools\check_comprovativo_dimensoes.php" "tools"
call :mover "tools\check_constraints.php" "tools"
call :mover "tools\check_estoque_schema.php" "tools"
call :mover "tools\check_login_user.php" "tools"
call :mover "tools\check_restaurante_status.php" "tools"
call :mover "tools\check_timezone.php" "tools"
call :mover "tools\fix_filial_66_status.php" "tools"
call :mover "tools\fix_turno_fk.php" "tools"
call :mover "tools\list_mesas.php" "tools"
call :mover "tools\mesa_labels_debug.php" "tools"
call :mover "tools\query_pedidos_status.php" "tools"
call :mover "tools\show_turno_tables.php" "tools"
call :mover "tools\restyle_solicitar_plano_head.php" "tools"
call :mover "tools\rewire_cardapio_assets.php" "tools"
call :mover "tools\extract_cardapio_assets.php" "tools"
call :mover "tools\extract_index_scripts.php" "tools"
call :mover "tools\rebuild_index_landing.php" "tools"
call :mover "tools\sync_filiais_plano_status.php" "tools"
call :mover "tools\reset_login_temp_password.php" "tools"

echo.
echo == Cookies de sessao de testes de intrusao
call :mover "tools\pentest_cookie.txt" "tools"
call :mover "tools\pentest_live_cookie.txt" "tools"
call :mover "tools\pentest_mt_cookie.txt" "tools"

echo.
echo Movidos: !MOVIDOS!   Ja nao existiam: !AUSENTES!
echo.
echo Proximo passo: tools\testes.bat
echo Imagens/anexos sem uso: php tools\imagens_orfas.php
exit /b 0

:mover
if not exist "%~1" (
    echo   [ja nao existe] %~1
    set /a AUSENTES+=1
    exit /b 0
)
if not exist "_to_delete\%~2" mkdir "_to_delete\%~2"
move "%~1" "_to_delete\%~2\" >nul && (
    echo   [movido] %~1
    set /a MOVIDOS+=1
)
exit /b 0
