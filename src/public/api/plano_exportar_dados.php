<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Exportar todos os dados do restaurante (ZIP com Excel, resumo e CSV).
   Disponivel mesmo com o plano bloqueado ou cancelado (Termos, seccoes 4 e 5).
   So o administrador que gere o plano. POST (com CSRF) para nao ser disparado por um link.

   Com a fila de trabalhos (migracao 20261108_1300_trabalhos_fila) o ficheiro e preparado em
   segundo plano: esta API so regista o pedido e devolve {trabalho: {...}}; a pagina pergunta o
   estado em api/trabalho_estado.php e descarrega em api/trabalho_descarregar.php.
   Sem a fila (migracao ainda nao aplicada), gera e envia o ZIP logo, como antes.
*/
include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/plano_upgrade_guard.php';
include_once APP_BASE . '/src/config/plano_encerramento.php';
include_once APP_BASE . '/src/config/trabalhos_fila.php';
include_once APP_BASE . '/src/config/plano_check.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}
$ctx = api_guard(['rate_key' => 'plano_exportar_dados', 'rate_max' => 10, 'rate_window' => 600, 'skip_turno' => true]);
$db = (new Database())->getConnection();
$rid = plano_upgrade_restaurante_alvo_id($db);
if ($rid <= 0 || !plano_upgrade_usuario_pode_gerir($db, (int)$ctx['usuario_id'], $rid)) {
    security_json(['success' => false, 'message' => 'Apenas o administrador do restaurante pode exportar os dados.'], 403);
}
// Empresarial com filiais: pode pedir a exportacao de toda a rede num so ficheiro.
$incluirRede = !empty($_POST['rede']) && (string)$_POST['rede'] !== '0';
if ($incluirRede) {
    $temMultiFilial = function_exists('plano_tem_funcionalidade_db')
        && plano_tem_funcionalidade_db($rid, 'multi_filial');
    if (!$temMultiFilial || count(enc_unidades_rede($db, $rid)) <= 1) {
        $incluirRede = false;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (fila_disponivel($db)) {
    try {
        $t = fila_criar($db, $rid, (int)$ctx['usuario_id'], 'exportar_dados', $incluirRede ? ['rede' => 1] : []);
        security_json(['success' => true, 'trabalho' => fila_publico($t)]);
    } catch (Throwable $e) {
        error_log('plano_exportar_dados (fila): ' . $e->getMessage());
        security_json(['success' => false, 'message' => 'Não foi possível pedir a exportação: ' . $e->getMessage()], 500);
    }
}

// Recurso: sem fila, gera agora.
@set_time_limit(300);
try {
    $zip = $incluirRede ? enc_exportar_zip_rede($db, $rid) : enc_exportar_zip($db, $rid);
} catch (Throwable $e) {
    error_log('plano_exportar_dados: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Não foi possível gerar o ficheiro: ' . $e->getMessage()], 500);
}
$nome = ($incluirRede ? 'dados-rede-' : 'dados-restaurante-') . $rid . '-' . date('Ymd-Hi') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . filesize($zip));
header('Cache-Control: no-store');
readfile($zip);
@unlink($zip);
exit;
