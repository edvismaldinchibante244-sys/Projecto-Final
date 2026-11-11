<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_insumo_excluir',
    'rate_max' => 40,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$insumoId = (int)($_POST['insumo_id'] ?? 0);
$modo = strtolower(trim((string)($_POST['modo'] ?? 'excluir')));
if ($insumoId <= 0) {
    security_json(['success' => false, 'message' => 'Insumo invalido'], 422);
}

try {
    $db = (new Database())->getConnection();
    estoque_inteligente_schema_garantir($db);

    $stmtOwn = $db->prepare("SELECT id, nome FROM estoque_insumos WHERE id = :id AND restaurante_id = :rid LIMIT 1");
    $stmtOwn->execute([':id' => $insumoId, ':rid' => $rid]);
    $insumo = $stmtOwn->fetch(PDO::FETCH_ASSOC);
    if (!$insumo) {
        security_json(['success' => false, 'message' => 'Insumo nao encontrado'], 404);
    }

    $checks = [
        'ficha tecnica' => "SELECT COUNT(*) FROM estoque_ficha_itens WHERE insumo_id = :id",
        'movimentos' => "SELECT COUNT(*) FROM estoque_movimentos WHERE insumo_id = :id AND restaurante_id = :rid",
        'perdas' => "SELECT COUNT(*) FROM estoque_perdas WHERE insumo_id = :id AND restaurante_id = :rid",
        'lotes' => "SELECT COUNT(*) FROM estoque_lotes WHERE insumo_id = :id AND restaurante_id = :rid",
    ];
    $temVinculo = false;
    $vinculoLabel = '';
    foreach ($checks as $label => $sql) {
        $st = $db->prepare($sql);
        $st->bindValue(':id', $insumoId, PDO::PARAM_INT);
        if (strpos($sql, ':rid') !== false) {
            $st->bindValue(':rid', $rid, PDO::PARAM_INT);
        }
        $st->execute();
        if ((int)$st->fetchColumn() > 0) {
            $temVinculo = true;
            $vinculoLabel = $label;
            break;
        }
    }

    $stmtSaldo = $db->prepare("SELECT COALESCE(SUM(ABS(saldo_base)),0) FROM estoque_saldos WHERE insumo_id = :id AND restaurante_id = :rid");
    $stmtSaldo->execute([':id' => $insumoId, ':rid' => $rid]);
    $saldoAbs = (float)$stmtSaldo->fetchColumn();

    if ($modo === 'arquivar') {
        $db->beginTransaction();
        $stmtArq = $db->prepare("UPDATE estoque_insumos SET ativo = 0, atualizado_em = NOW() WHERE id = :id AND restaurante_id = :rid");
        $stmtArq->execute([':id' => $insumoId, ':rid' => $rid]);

        $stmtResolveAlertas = $db->prepare("
            UPDATE estoque_alertas
            SET resolvido = 1, resolvido_em = NOW()
            WHERE restaurante_id = :rid
              AND insumo_id = :id
              AND resolvido = 0
        ");
        $stmtResolveAlertas->execute([':rid' => $rid, ':id' => $insumoId]);

        $db->commit();
        estoque_inteligente_log('estoque_insumo_arquivado', [
            'restaurante_id' => $rid,
            'insumo_id' => $insumoId,
            'insumo_nome' => (string)($insumo['nome'] ?? ''),
            'usuario_id' => (int)($ctx['usuario_id'] ?? 0),
        ]);
        security_json(['success' => true, 'message' => 'Insumo arquivado com sucesso.']);
    }

    if ($temVinculo) {
        security_json([
            'success' => false,
            'message' => 'Nao e possivel apagar: insumo vinculado a ' . $vinculoLabel . '.',
            'can_archive' => true,
        ], 409);
    }
    if ($saldoAbs > 0.0001) {
        security_json([
            'success' => false,
            'message' => 'Nao e possivel apagar: insumo ainda possui saldo em estoque.',
            'can_archive' => true,
        ], 409);
    }

    $stmtDelSaldo = $db->prepare("DELETE FROM estoque_saldos WHERE insumo_id = :id AND restaurante_id = :rid");
    $stmtDelSaldo->execute([':id' => $insumoId, ':rid' => $rid]);

    $stmtDel = $db->prepare("DELETE FROM estoque_insumos WHERE id = :id AND restaurante_id = :rid");
    $stmtDel->execute([':id' => $insumoId, ':rid' => $rid]);

    estoque_inteligente_log('estoque_insumo_excluido', [
        'restaurante_id' => $rid,
        'insumo_id' => $insumoId,
        'insumo_nome' => (string)($insumo['nome'] ?? ''),
        'usuario_id' => (int)($ctx['usuario_id'] ?? 0),
    ]);

    security_json(['success' => true, 'message' => 'Insumo removido com sucesso.']);
} catch (Throwable $e) {
    error_log('estoque_insumo_excluir: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro ao excluir insumo'], 500);
}
