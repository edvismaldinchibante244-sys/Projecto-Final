<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Sangria / reforco de caixa (dinheiro que sai ou entra na gaveta durante o turno).
   Sangria feita por um operador de caixa precisa da autorizacao de um gerente.
 */

header('Content-Type: application/json');
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/caixa_movimentos.php';
require_once APP_BASE . '/src/Model/Caixa.php';

api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA'],
    'rate_key' => 'caixa_movimento',
    'rate_max' => 30,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$rid = (int)($_SESSION['restaurante_id'] ?? 0);
$uid = (int)($_SESSION['usuario_id'] ?? 0);
$tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
$valor = round((float)str_replace(',', '.', (string)($_POST['valor'] ?? '0')), 2);
$motivo = trim((string)($_POST['motivo'] ?? ''));
$motivo = function_exists('mb_substr') ? mb_substr($motivo, 0, 160) : substr($motivo, 0, 160);

if (!in_array($tipo, ['SANGRIA', 'REFORCO'], true)) {
    echo json_encode(['success' => false, 'message' => 'Tipo de movimento inválido.']);
    exit;
}
if ($valor <= 0 || $valor > 10000000) {
    echo json_encode(['success' => false, 'message' => 'Indique um valor maior que zero.']);
    exit;
}
if ($tipo === 'SANGRIA' && $motivo === '') {
    echo json_encode(['success' => false, 'message' => 'Indique o motivo da sangria (ex.: levado para o cofre).']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    if (!caixa_mov_tabela_existe($db)) {
        echo json_encode(['success' => false, 'message' => 'Falta aplicar a migração 20261108_1900_caixa_movimentos.']);
        exit;
    }
    $caixa = (new Caixa($db))->buscarAberto($rid);
    if (!$caixa) {
        echo json_encode(['success' => false, 'message' => 'Não há caixa aberto.']);
        exit;
    }
    $caixaId = (int)$caixa['id'];

    $autorizadoPor = null;
    if ($tipo === 'SANGRIA') {
        // Nao se pode retirar mais do que o dinheiro que devia estar na gaveta.
        $stDin = $db->prepare("SELECT COALESCE(SUM(total_final), 0) FROM vendas
            WHERE caixa_id = ? AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
              AND UPPER(TRIM(COALESCE(forma_pagamento, ''))) = 'DINHEIRO'");
        $stDin->execute([$caixaId]);
        $mov = caixa_mov_resumo($db, $caixaId, $rid);
        $naGaveta = round((float)($caixa['saldo_inicial'] ?? 0) + (float)$stDin->fetchColumn() + $mov['reforco'] - $mov['sangria'], 2);
        if ($valor > $naGaveta + 0.001) {
            echo json_encode(['success' => false, 'message' => sprintf('Só devem estar %s MZN na gaveta. Não pode retirar %s MZN.',
                number_format($naGaveta, 2, ',', '.'), number_format($valor, 2, ',', '.'))]);
            exit;
        }
        $aut = autorizacao_gerente_exigir('sangria_caixa', $caixaId,
            sprintf('Retirar %s MZN da gaveta — %s', number_format($valor, 2, ',', '.'), $motivo));
        $autorizadoPor = $aut ? (int)$aut['autorizador_id'] : $uid;
    }

    $st = $db->prepare("INSERT INTO caixa_movimentos (restaurante_id, caixa_id, usuario_id, autorizado_por, tipo, valor, motivo)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$rid, $caixaId, $uid, $autorizadoPor, $tipo, $valor, $motivo !== '' ? $motivo : null]);

    if (function_exists('audit_log')) {
        @audit_log(null, 'caixa_' . strtolower($tipo), ['caixa_id' => $caixaId, 'valor' => $valor, 'motivo' => $motivo]);
    }
    echo json_encode([
        'success' => true,
        'message' => ($tipo === 'SANGRIA' ? 'Sangria' : 'Reforço') . ' de ' . number_format($valor, 2, ',', '.') . ' MZN registado.',
    ]);
} catch (Throwable $e) {
    error_log('Erro caixa_movimento: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao registar o movimento.']);
}
