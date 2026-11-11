<?php
require_once __DIR__ . '/../app_base.php';

header('Content-Type: application/json');

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/backup_helper.php';

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'backup_verificar',
    'rate_max' => 20,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$restauranteId = session_restaurante_contexto_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    security_json(['success' => false, 'message' => 'Backup invalido.'], 422);
}

try {
    $db = (new Database())->getConnection();
    backup_ensure_tables_exist($db);

    $stmt = $db->prepare("SELECT * FROM backup_historico WHERE id = :id AND restaurante_id = :rid LIMIT 1");
    $stmt->execute([':id' => $id, ':rid' => (int)$restauranteId]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        security_json(['success' => false, 'message' => 'Backup nao encontrado.'], 404);
    }

    $caminho = backup_resolver_caminho($linha['arquivo_caminho'] ?? '', $linha['arquivo_nome'] ?? '', (int)$restauranteId);
    if ($caminho === '' || !is_file($caminho)) {
        security_json(['success' => true, 'estado' => 'ausente', 'rotulo' => 'Ficheiro apagado', 'detalhe' => 'O ficheiro ja nao existe na pasta de backups.']);
    }

    $tamanho = (int)@filesize($caminho);
    if ($tamanho <= 0) {
        security_json(['success' => true, 'estado' => 'vazio', 'rotulo' => 'Ficheiro vazio', 'detalhe' => 'O ficheiro tem 0 bytes.']);
    }

    // O ficheiro tem de abrir e comecar como um dump SQL valido.
    $inicio = (string)@file_get_contents($caminho, false, null, 0, 512);
    if ($inicio === '' || stripos($inicio, 'CREATE TABLE') === false && stripos($inicio, '--') === false && stripos($inicio, 'INSERT') === false) {
        security_json(['success' => true, 'estado' => 'ilegivel', 'rotulo' => 'Com problema', 'detalhe' => 'O conteudo nao parece um backup SQL.']);
    }

    $hashGravado = trim((string)($linha['hash_arquivo'] ?? ''));
    if ($hashGravado !== '') {
        $hashAtual = (string)@md5_file($caminho);
        if ($hashAtual !== '' && !hash_equals($hashGravado, $hashAtual)) {
            security_json(['success' => true, 'estado' => 'alterado', 'rotulo' => 'Com problema', 'detalhe' => 'O ficheiro foi alterado depois de criado.']);
        }
    }

    security_json([
        'success' => true,
        'estado' => 'ok',
        'rotulo' => 'Verificado',
        'detalhe' => 'Abre bem, tem ' . backup_formatar_tamanho($tamanho) . ' e corresponde ao registo.',
    ]);
} catch (Throwable $e) {
    error_log('backup_verificar: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel verificar o backup.'], 500);
}
