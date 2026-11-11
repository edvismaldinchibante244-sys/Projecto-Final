<?php
require_once __DIR__ . '/../app_base.php';

header('Content-Type: application/json');

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/audit.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'usuario_historico',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$restauranteId = session_restaurante_contexto_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    security_json(['success' => false, 'message' => 'Utilizador invalido.'], 422);
}

$rotulos = [
    'usuario_cadastrado' => 'Conta criada',
    'usuario_atualizado' => 'Dados alterados',
    'usuario_senha_alterada' => 'Senha alterada',
    'usuario_excluido' => 'Acesso desactivado',
    'usuario_reativado' => 'Acesso reactivado',
];

try {
    $db = (new Database())->getConnection();
    $usuario = resource_require_usuario($db, $id, $restauranteId, ['id', 'nome']);
    audit_ensure_table($db);

    $stmt = $db->prepare("
        SELECT a.event, a.created_at, a.context_json, u.nome AS autor_nome
        FROM audit_logs a
        LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.restaurante_id = :rid
          AND a.event IN ('usuario_cadastrado', 'usuario_atualizado', 'usuario_senha_alterada', 'usuario_excluido', 'usuario_reativado')
          AND (a.context_json LIKE :alvo OR a.context_json LIKE :novo)
        ORDER BY a.id DESC
        LIMIT 30
    ");
    $stmt->execute([
        ':rid' => (int)$restauranteId,
        ':alvo' => '%"usuario_alvo_id":' . $id . '%',
        ':novo' => '%"novo_usuario_id":' . $id . '%',
    ]);

    $itens = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ctx = json_decode((string)($row['context_json'] ?? ''), true) ?: [];
        $detalhe = [];
        if (isset($ctx['perfil'])) {
            $detalhe[] = 'Perfil: ' . (string)$ctx['perfil'];
        }
        if (array_key_exists('ativo', $ctx)) {
            $detalhe[] = (int)$ctx['ativo'] === 1 ? 'Activo' : 'Inactivo';
        }
        if (!empty($ctx['senha_alterada'])) {
            $detalhe[] = 'Senha alterada';
        }
        if (!empty($ctx['self_edit'])) {
            $detalhe[] = 'Editou o proprio perfil';
        }
        $itens[] = [
            'quando' => date('d/m/Y H:i', strtotime((string)$row["created_at"])),
            'acao' => $rotulos[(string)$row['event']] ?? (string)$row['event'],
            'autor' => (string)($row['autor_nome'] ?? 'Sistema'),
            'detalhe' => implode(' · ', $detalhe),
        ];
    }

    security_json(['success' => true, 'nome' => (string)($usuario['nome'] ?? ''), 'itens' => $itens]);
} catch (Throwable $e) {
    error_log('usuario_historico: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Nao foi possivel carregar o historico.'], 500);
}
