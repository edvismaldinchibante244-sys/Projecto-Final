<?php
require_once __DIR__ . '/../app_base.php';

/*
   API - Fechar Caixa
   Arquitetura N-Tier
 */

header('Content-Type: application/json');
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/autorizacao_gerente.php';
require_once APP_BASE . '/src/config/turno_helpers.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/offline_idempotency.php';
require_once APP_BASE . '/src/Model/Caixa.php';
require_once APP_BASE . '/src/config/caixa_movimentos.php';
require_once APP_BASE . '/src/Service/TurnoService.php';

api_guard([
    'roles' => ['ADMIN', 'CAIXA'],
    'rate_key' => 'caixa_fechar',
    'rate_max' => 20,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['restaurante_id']) || !isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
        http_response_code(401);
        echo json_encode(array("success" => false, "message" => "Não autenticado"));
        exit;
    }

    $perfil = turno_normalizar_perfil($_SESSION['perfil'] ?? '');
    if (!in_array($perfil, ['ADMIN', 'CAIXA'], true)) {
        http_response_code(403);
        echo json_encode(array("success" => false, "message" => "Sem permissão para fechar o caixa"));
        exit;
    }

    if (!isset($_POST['fechamento']) || $_POST['fechamento'] === '') {
        echo json_encode(array("success" => false, "message" => "Valor de fechamento não informado"));
        exit;
    }

    $fechamento = floatval($_POST['fechamento']);

    if ($fechamento < 0) {
        echo json_encode(array("success" => false, "message" => "Valor de fechamento inválido"));
        exit;
    }

    $database = new Database();
    $turnoService = new TurnoService($database);
    $turnoRestauranteId = function_exists('session_restaurante_auth_id') ? session_restaurante_auth_id() : (int)$_SESSION['restaurante_id'];
    $turnoAtivo = $turnoService->obterTurnoAtivoUsuario((int)$_SESSION['usuario_id'], $turnoRestauranteId);
    if (!$turnoAtivo) {
        echo json_encode(array("success" => false, "message" => "É necessário ter turno ativo para fechar o caixa"));
        exit;
    }

    require_once APP_BASE . '/src/Controller/CaixaController.php';

    // Dinheiro contado diferente do esperado na gaveta (abertura + vendas em dinheiro):
    // o caixa so fecha com a autorizacao de um gerente.
    if (!autorizacao_gerente_dispensada($perfil)) {
        $dbConf = $database->getConnection();
        $caixaConf = (new Caixa($dbConf))->buscarAberto((int)$_SESSION['restaurante_id']);
        if ($caixaConf) {
            $stmtDin = $dbConf->prepare("SELECT COALESCE(SUM(total_final), 0) FROM vendas
                WHERE caixa_id = ? AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                  AND UPPER(TRIM(COALESCE(forma_pagamento, ''))) = 'DINHEIRO'");
            $stmtDin->execute([(int)$caixaConf['id']]);
            $movConf = caixa_mov_resumo($dbConf, (int)$caixaConf['id'], (int)$_SESSION['restaurante_id']);
            // Esperado = abertura + vendas em dinheiro + reforcos - sangrias
            $esperadoGaveta = round((float)($caixaConf['saldo_inicial'] ?? 0) + (float)$stmtDin->fetchColumn()
                + $movConf['reforco'] - $movConf['sangria'], 2);
            $diferenca = round($fechamento - $esperadoGaveta, 2);
            if (abs($diferenca) >= 0.01) {
                autorizacao_gerente_exigir(
                    'fechar_caixa_diferenca',
                    (int)$caixaConf['id'],
                    sprintf('Esperado %s MZN, contado %s MZN (diferença %s%s MZN).',
                        number_format($esperadoGaveta, 2, ',', '.'),
                        number_format($fechamento, 2, ',', '.'),
                        $diferenca > 0 ? '+' : '',
                        number_format($diferenca, 2, ',', '.'))
                );
            }
        }
    }

    try {
        $db = $database->getConnection();
        offline_idempotency_prepare($db);
        $db->beginTransaction();
        $idempotency = offline_idempotency_begin($db, (int)$_SESSION['restaurante_id'], (int)$_SESSION['usuario_id'], 'api/caixa_fechar.php', $_POST);
        offline_idempotency_replay($db, $idempotency);
        $caixaModel = new Caixa($db);
        $caixaAberto = $caixaModel->buscarAberto((int)$_SESSION['restaurante_id']);
        $controller = new CaixaController($db);
        $resultado = $controller->fechar($_SESSION['restaurante_id'], $fechamento);
        if (!empty($resultado['success']) && !empty($caixaAberto['id'])) {
            $turnoService->encerrarVinculoCaixa((int)$_SESSION['restaurante_id'], (int)$caixaAberto['id']);
            $resultado['caixa_id'] = (int)$caixaAberto['id'];
        }
        offline_idempotency_complete($db, $idempotency, $resultado);
        $db->commit();
        echo json_encode($resultado);
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        error_log("Erro fechar caixa: " . $e->getMessage());
        echo json_encode(array("success" => false, "message" => "Erro interno ao fechar caixa"));
    }
} else {
    echo json_encode(array("success" => false, "message" => "Método não permitido"));
}

