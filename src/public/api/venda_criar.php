<?php
require_once __DIR__ . '/../app_base.php';
/*
   API - Criar Venda
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    require_once APP_BASE . '/src/config/api_guard.php';
    require_once APP_BASE . '/src/config/database.php';
    require_once APP_BASE . '/src/Model/Venda.php';
    require_once APP_BASE . '/src/Model/Caixa.php';
    require_once APP_BASE . '/src/Model/Produto.php';
    require_once APP_BASE . '/src/Model/Mesa.php';
    require_once APP_BASE . '/src/Service/TurnoService.php';
    require_once APP_BASE . '/src/config/plano_check.php';
    require_once APP_BASE . '/src/config/promocao_schema.php';
    require_once APP_BASE . '/src/config/restaurante_context.php';
    require_once APP_BASE . '/src/config/estoque_inteligente.php';
    require_once APP_BASE . '/src/config/reserva_qr_schema.php';
} catch (Throwable $e) {
    security_json([
        "success" => false,
        "message" => "Erro interno ao carregar dependencias"
    ], 500);
}

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(["success" => false, "message" => "Metodo nao permitido"], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'CAIXA', 'GARCOM'],
    'rate_key' => 'venda_criar',
    'rate_max' => 60,
    'rate_window' => 60,
]);

$restauranteId = (int)$ctx['restaurante_id'];
$dados = json_decode((string)file_get_contents("php://input"), true);

if (empty($dados['itens']) || !is_array($dados['itens'])) {
    security_json(["success" => false, "message" => "Dados incompletos: faltam itens"], 422);
}

foreach ($dados['itens'] as $idx => $item) {
    if (!isset($item['id']) && !isset($item['Id'])) {
        error_log("Item $idx sem ID: " . json_encode($item));
        security_json(["success" => false, "message" => "Item sem ID"], 422);
    }
}

$database = new Database();
$db = $database->getConnection();
if (!$db) {
    security_json(["success" => false, "message" => "Erro de conexao com a base de dados"], 500);
}

$planRid = function_exists('session_restaurante_capability_id')
    ? (int)session_restaurante_capability_id()
    : $restauranteId;
$planRid = $planRid > 0 ? $planRid : $restauranteId;
$estoqueAvancadoAtivo = plano_tem_funcionalidade_db($planRid, 'estoque_avancado');
$dadosPlanoVenda = function_exists('plano_get_dados') ? plano_get_dados($planRid) : [];
$planoNomeVenda = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($dadosPlanoVenda['plano_nome'] ?? 'BASICO')
    : 'BASICO';
$exigeFichaVenda = $estoqueAvancadoAtivo && ($planoNomeVenda === 'PROFISSIONAL');
// Basico: ingredientes simples (baixa pela receita, sem bloquear a venda se faltar stock).
require_once APP_BASE . '/src/config/ingredientes_simples.php';
$ingredientesSimplesAtivo = !$estoqueAvancadoAtivo && plano_tem_ingredientes($planRid);

// Schema/DDL fora da transação — ALTER/CREATE no MySQL faz commit implícito e
// partia o commit da venda (erro genérico "Erro interno ao criar venda").
try {
    promocao_schema_garantir($db);
    $vendaSchema = new Venda($db);
    $vendaSchema->garantirSchemaPagamentoPublico();
    $vendaSchema->prepararNumeracaoFatura($restauranteId);
    if ($estoqueAvancadoAtivo || $ingredientesSimplesAtivo) {
        estoque_inteligente_schema_garantir($db);
    }
} catch (Throwable $schemaErr) {
    error_log('[venda_criar] schema: ' . $schemaErr->getMessage());
}

try {
    $turnoService = new TurnoService($database);
    $turnoAtivo = $turnoService->obterTurnoAtivoUsuario((int)$ctx['usuario_id'], $restauranteId);
    if (!$turnoAtivo) {
        throw new Exception("Nao ha turno ativo para este usuario. Inicie o turno antes de lancar vendas.");
    }

    $caixa = new Caixa($db);
    $caixa_aberto = $caixa->buscarAberto($restauranteId);
    if (!$caixa_aberto) {
        throw new Exception("Nao ha caixa aberto. Por favor, abra o caixa primeiro.");
    }

    $filialIdVenda = null;
    if (function_exists('session_restaurante_auth_id')) {
        $authRidVenda = (int)session_restaurante_auth_id();
        if ($authRidVenda > 0 && $authRidVenda !== $planRid) {
            $filialIdVenda = $authRidVenda;
        }
    }
    if ($exigeFichaVenda && !empty($dados['itens']) && is_array($dados['itens'])) {
        try {
            estoque_validar_itens_com_ficha_obrigatoria($db, $restauranteId, $filialIdVenda, $dados['itens']);
        } catch (RuntimeException $fichaErr) {
            $msgFicha = (string)$fichaErr->getMessage();
            if (strpos($msgFicha, 'SEM_FICHA:') === 0) {
                $nomesFicha = trim(substr($msgFicha, strlen('SEM_FICHA:')));
                security_json([
                    'success' => false,
                    'message' => 'Não é possível vender sem ficha técnica / insumos: ' . $nomesFicha,
                ], 422);
            }
            throw $fichaErr;
        }
    }

    $db->beginTransaction();

    $venda = new Venda($db);
    $venda->restaurante_id = $restauranteId;
    $venda->usuario_id = (int)$ctx['usuario_id'];
    $venda->caixa_id = $caixa_aberto['id'];
    $mesaId = isset($dados['mesa_id']) ? (int)$dados['mesa_id'] : 0;
    $venda->mesa_id = $mesaId > 0 ? $mesaId : null;
    $venda->total = (float)($dados['total'] ?? 0);
    $venda->desconto = (float)($dados['desconto'] ?? 0);
    $venda->total_final = (float)($dados['total_final'] ?? max(0, $venda->total - $venda->desconto));
    $venda->forma_pagamento = strtoupper(trim((string)($dados['forma_pagamento'] ?? 'DINHEIRO')));
    $formasValidas = ['DINHEIRO', 'MPESA', 'EMOLA', 'CARTAO', 'TRANSFERENCIA'];
    if (!in_array($venda->forma_pagamento, $formasValidas, true)) {
        throw new Exception('Forma de pagamento invalida');
    }
    if ($venda->desconto < 0 || $venda->total_final < 0) {
        throw new Exception('Valores de desconto/total invalidos');
    }

    $valorRecebido = null;
    $troco = null;
    if (array_key_exists('valor_recebido', $dados) && $dados['valor_recebido'] !== null && $dados['valor_recebido'] !== '') {
        $valorRecebido = (float)$dados['valor_recebido'];
    }
    if ($venda->forma_pagamento === 'DINHEIRO') {
        if ($valorRecebido === null || $valorRecebido <= 0) {
            throw new Exception('Informe o valor recebido em dinheiro.');
        }
        if ($valorRecebido + 0.0001 < $venda->total_final) {
            throw new Exception('Valor recebido inferior ao total a pagar.');
        }
        $troco = round($valorRecebido - $venda->total_final, 2);
    } elseif (array_key_exists('troco', $dados) && $dados['troco'] !== null && $dados['troco'] !== '') {
        $troco = (float)$dados['troco'];
    }

    $venda->valor_recebido = $valorRecebido;
    $venda->troco = $troco;
    // Venda do PDV: pagamento imediato
    $venda->status = 'PAGO';
    $venda->numero_fatura = $venda->gerarNumeroFatura($restauranteId);

    $venda_id = $venda->criar();
    if (!$venda_id) {
        throw new Exception("Erro ao criar venda no banco de dados");
    }

    $produto = new Produto($db);
    foreach ($dados['itens'] as $item) {
        $produtoId = (int)($item['id'] ?? $item['Id'] ?? 0);
        $quantidade = (int)($item['quantidade'] ?? 0);
        $preco = (float)($item['preco'] ?? $item['Preco'] ?? 0);
        if ($produtoId <= 0 || $quantidade <= 0) {
            throw new Exception("Item invalido na venda.");
        }

        $promocaoId = !empty($item['promocao_id']) ? (int)$item['promocao_id'] : null;
        if (!$venda->adicionarItem($venda_id, $produtoId, $quantidade, $preco, $promocaoId)) {
            throw new Exception("Erro ao adicionar item na venda.");
        }

        if (!$estoqueAvancadoAtivo) {
            try {
                $produto->atualizarEstoque($produtoId, $restauranteId, $quantidade, 'SAIDA');
            } catch (Throwable $stockErr) {
                error_log('[venda_criar] estoque produto: ' . $stockErr->getMessage());
            }
        }
    }
    if ($estoqueAvancadoAtivo) {
        $filialId = null;
        if (function_exists('session_restaurante_auth_id')) {
            $authRid = (int)session_restaurante_auth_id();
            if ($authRid > 0 && $authRid !== $planRid) {
                $filialId = $authRid;
            }
        }
        estoque_baixa_automatica_por_itens_venda(
            $db,
            $restauranteId,
            $filialId,
            $dados['itens'],
            (int)$venda_id,
            (int)$ctx['usuario_id']
        );
        try {
            estoque_gerar_alertas_automaticos($db, $restauranteId, $filialId);
        } catch (Throwable $alertaErr) {
            // Alertas nao devem impedir a venda ja registada.
            error_log('[venda_criar] alertas: ' . $alertaErr->getMessage());
        }
    }

    if ($ingredientesSimplesAtivo) {
        // Basico: baixa pela receita dentro de um savepoint - se faltar algum ingrediente
        // a venda continua (o dono ve o ingrediente "acabou" na lista e acerta a contagem).
        try {
            $db->exec('SAVEPOINT ingredientes_simples');
            estoque_baixa_automatica_por_itens_venda($db, $restauranteId, null, $dados['itens'], (int)$venda_id, (int)$ctx['usuario_id']);
            $db->exec('RELEASE SAVEPOINT ingredientes_simples');
        } catch (Throwable $ingErr) {
            try {
                $db->exec('ROLLBACK TO SAVEPOINT ingredientes_simples');
            } catch (Throwable $rb) {
            }
            error_log('[venda_criar] ingredientes simples: ' . $ingErr->getMessage());
        }
    }

    if ($mesaId > 0) {
        // PDV com mesa: após pagar, libera mesa e conclui reserva "No salão".
        reserva_liberar_mesa_apos_pagamento($db, $restauranteId, $mesaId);
    }

    $db->commit();
    security_audit_event('venda_criada', ['venda_id' => (int)$venda_id, 'caixa_id' => (int)$caixa_aberto['id']]);
    security_json([
        "success" => true,
        "message" => "Venda realizada com sucesso!",
        "venda_id" => $venda_id,
        "numero_fatura" => $venda->numero_fatura,
        "valor_recebido" => $valorRecebido,
        "troco" => $troco,
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        try {
            $db->rollBack();
        } catch (Throwable $rb) {
            // ignore
        }
    }
    $msg = trim((string)$e->getMessage());
    error_log('[venda_criar] ' . $msg);
    try {
        $logDir = nv_storage('logs');
        if (!is_dir($logDir)) {
            $logDir = nv_storage('logs');
        }
        if (is_dir($logDir) || @mkdir($logDir, 0775, true)) {
            @file_put_contents(
                rtrim($logDir, '/\\') . '/venda_criar.log',
                date('c') . ' ' . $msg . "\n",
                FILE_APPEND
            );
        }
    } catch (Throwable $logErr) {
        // ignore
    }

    if (strpos($msg, 'ESTOQUE_INSUFICIENTE:') === 0) {
        $detail = trim(substr($msg, strlen('ESTOQUE_INSUFICIENTE:')));
        security_json([
            "success" => false,
            "message" => "Estoque insuficiente para concluir venda",
            "detail" => $detail
        ], 422);
    }
    if (strpos($msg, 'SEM_FICHA:') === 0) {
        $detail = trim(substr($msg, strlen('SEM_FICHA:')));
        security_json([
            "success" => false,
            "message" => "Não é possível vender sem ficha técnica / insumos: " . $detail,
        ], 422);
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = $host === ''
        || str_contains($host, 'localhost')
        || str_contains($host, '127.0.0.1');

    $mensagemCliente = 'Erro interno ao criar venda';
    if (
        $isLocal
        || stripos($msg, 'turno') !== false
        || stripos($msg, 'caixa') !== false
        || stripos($msg, 'pagamento') !== false
        || stripos($msg, 'valor recebido') !== false
        || stripos($msg, 'desconto') !== false
        || stripos($msg, 'forma de pagamento') !== false
        || stripos($msg, 'estoque') !== false
        || stripos($msg, 'item') !== false
        || stripos($msg, 'venda') !== false
        || stripos($msg, 'active transaction') !== false
    ) {
        $mensagemCliente = $msg !== '' ? $msg : $mensagemCliente;
    }
    security_json(["success" => false, "message" => $mensagemCliente], 500);
}
