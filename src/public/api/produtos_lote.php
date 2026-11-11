<?php
require_once __DIR__ . '/../app_base.php';

/* API - Ações em vários produtos de uma vez (ativar, desativar, mudar categoria). Só administrador. */

include_once APP_BASE . '/src/config/api_guard.php';
include_once APP_BASE . '/src/config/restaurante_context.php';
include_once APP_BASE . '/src/config/database.php';

header('Content-Type: application/json; charset=utf-8');

api_guard([
    'roles' => ['ADMIN'],
    'rate_key' => 'produtos_lote',
    'rate_max' => 30,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$rid = (int)session_restaurante_contexto_id();
$acao = (string)($_POST['acao'] ?? '');
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));

if ($rid <= 0 || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'Selecione pelo menos um produto.']);
    exit;
}
if (count($ids) > 500) {
    echo json_encode(['success' => false, 'message' => 'No máximo 500 produtos de cada vez.']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));

    if ($acao === 'ativar' || $acao === 'desativar') {
        $st = $db->prepare("UPDATE produtos SET ativo = ? WHERE restaurante_id = ? AND id IN ($in)");
        $st->execute(array_merge([$acao === 'ativar' ? 1 : 0, $rid], $ids));
        $n = $st->rowCount();
        $msg = $n . ' produto(s) ' . ($acao === 'ativar' ? 'ativado(s)' : 'desativado(s)') . '.';
    } elseif ($acao === 'categoria') {
        $catId = (int)($_POST['categoria_id'] ?? 0);
        if ($catId > 0) {
            $stC = $db->prepare("SELECT COUNT(*) FROM categorias WHERE id = ? AND restaurante_id = ?");
            $stC->execute([$catId, $rid]);
            if ((int)$stC->fetchColumn() === 0) {
                echo json_encode(['success' => false, 'message' => 'Categoria inválida.']);
                exit;
            }
        }
        $st = $db->prepare("UPDATE produtos SET categoria_id = ? WHERE restaurante_id = ? AND id IN ($in)");
        $st->execute(array_merge([$catId > 0 ? $catId : null, $rid], $ids));
        $n = $st->rowCount();
        $msg = $n . ' produto(s) mudado(s) de categoria.';
    } else {
        echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
        exit;
    }

    if (function_exists('audit_log')) {
        @audit_log(null, 'produtos_lote_' . $acao, ['ids' => $ids, 'categoria_id' => $_POST['categoria_id'] ?? null]);
    }
    echo json_encode(['success' => true, 'message' => $msg, 'alterados' => $n]);
} catch (Throwable $e) {
    error_log('Erro produtos_lote: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao atualizar os produtos.']);
}
