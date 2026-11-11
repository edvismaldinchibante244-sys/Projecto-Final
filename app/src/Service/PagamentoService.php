<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/pedido_schema.php';
require_once __DIR__ . '/../config/promocao_schema.php';
require_once __DIR__ . '/../config/offline_idempotency.php';
require_once __DIR__ . '/../config/reserva_qr_schema.php';
require_once __DIR__ . '/../Model/Caixa.php';
require_once __DIR__ . '/../Model/Venda.php';
require_once __DIR__ . '/../Model/Produto.php';

/**
 * Recebimento de pagamento de um pedido: converte o pedido em venda PAGA,
 * emite a fatura e liberta a mesa.
 *
 * Extraido de src/public/api/pedido_pagar.php, com duas correccoes:
 *  - o pedido e bloqueado (SELECT ... FOR UPDATE) dentro da transaccao, por
 *    isso dois caixas a cobrar o mesmo pedido ja nao criam duas vendas;
 *  - em DINHEIRO, um valor recebido inferior ao total e recusado.
 */
final class PagamentoService
{
    public const FORMAS_VALIDAS = ['DINHEIRO', 'MPESA', 'EMOLA', 'CARTAO', 'TRANSFERENCIA'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array{
     *   restaurante_id:int, usuario_id:int,
     *   telas_bar_cozinha?:bool, contas_abertas?:bool, estoque_avancado?:bool,
     *   idempotencia_payload?:array
     * } $ctx
     * @param array{
     *   pedido_id:int, forma_pagamento?:string, desconto_percent?:float|string,
     *   observacao?:string, referencia?:string, valor_recebido?:mixed, pagar_ao_enviar?:bool
     * } $dados
     * @return array{http:int, body:array}
     */
    public function pagarPedido(array $ctx, array $dados): array
    {
        $db = $this->db;
        $restauranteId = (int)$ctx['restaurante_id'];
        $usuarioId = (int)($ctx['usuario_id'] ?? 0);
        $pedidoId = (int)($dados['pedido_id'] ?? 0);
        $forma = strtoupper(trim((string)($dados['forma_pagamento'] ?? 'DINHEIRO')));
        $descontoPercent = (float)($dados['desconto_percent'] ?? 0);
        $observacao = trim((string)($dados['observacao'] ?? ''));
        $referencia = trim((string)($dados['referencia'] ?? ''));
        $pagarAoEnviar = !empty($dados['pagar_ao_enviar']);

        if ($referencia !== '') {
            $refLabel = 'Ref. ' . $forma . ': ' . $referencia;
            $observacao = $observacao !== '' ? ($observacao . ' | ' . $refLabel) : $refLabel;
        }
        if ($pedidoId <= 0) {
            return self::resultado(['success' => false, 'message' => 'Pedido invalido']);
        }
        if (!in_array($forma, self::FORMAS_VALIDAS, true)) {
            return self::resultado(['success' => false, 'message' => 'Forma de pagamento invalida']);
        }
        if ($descontoPercent < 0 || $descontoPercent > 100) {
            return self::resultado(['success' => false, 'message' => 'Desconto percentual invalido (0 a 100)']);
        }

        $valorRecebido = self::normalizarValorRecebido($dados['valor_recebido'] ?? null, $forma);

        offline_idempotency_prepare($db);
        // Schema fora da transaccao (DDL faz commit implicito no MySQL).
        try {
            pedido_schema_garantir($db);
            promocao_schema_garantir($db);
            $vendaSchema = new Venda($db);
            $vendaSchema->garantirSchemaPagamentoPublico();
            $vendaSchema->prepararNumeracaoFatura($restauranteId);
        } catch (Throwable $schemaErr) {
            error_log('[pagamento] schema: ' . $schemaErr->getMessage());
        }

        try {
            $db->beginTransaction();
            $idempotencia = offline_idempotency_begin(
                $db,
                $restauranteId,
                $usuarioId,
                'api/pedido_pagar.php',
                (array)($ctx['idempotencia_payload'] ?? [])
            );
            if (!empty($idempotencia['replay'])) {
                $db->commit();
                return self::resultado((array)$idempotencia['response'], (int)($idempotencia['http_status'] ?? 200));
            }

            // Bloqueia o pedido ate ao fim da transaccao: um segundo pagamento
            // simultaneo espera aqui e depois ve o estado PAGO.
            $stmtPedido = $db->prepare('
                SELECT id, restaurante_id, mesa_id, numero_pedido, status, total
                FROM pedidos WHERE id = :id AND restaurante_id = :rid
                LIMIT 1 FOR UPDATE
            ');
            $stmtPedido->execute(['id' => $pedidoId, 'rid' => $restauranteId]);
            $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC);
            if (!$pedido) {
                $db->rollBack();
                return self::resultado(['success' => false, 'message' => 'Recurso nao encontrado.'], 404);
            }

            $status = strtoupper((string)($pedido['status'] ?? ''));
            if ($status === 'PAGO') {
                // Repeticao (rede caiu depois do commit, ou outro caixa ja cobrou).
                $resposta = [
                    'success' => true,
                    'message' => 'Pagamento ja estava registrado',
                    'already_paid' => true,
                    'pedido_id' => $pedidoId,
                ];
                offline_idempotency_complete($db, $idempotencia, $resposta);
                $db->commit();
                return self::resultado($resposta);
            }
            if ($status === 'CANCELADO') {
                throw new Exception('Pedido cancelado nao pode ser pago');
            }

            $resumo = $this->resumoItens($pedidoId);
            $fluxoPdvSemKds = empty($ctx['telas_bar_cozinha']);
            $temContasAbertas = !empty($ctx['contas_abertas']);
            $statusAbertos = [
                'NOVO', 'PENDENTE', 'CONFIRMADO', 'PREPARANDO', 'ABERTO', 'EM_ANDAMENTO', 'EM ANDAMENTO',
                'PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA',
            ];
            $elegivel =
                in_array($status, ['PRONTO', 'ENTREGUE', 'CONTA', 'CONTA_SOLICITADA'], true)
                || ($resumo['total'] > 0 && $resumo['pendentes'] === 0 && $resumo['em_preparo'] === 0
                    && ($resumo['prontos'] + $resumo['entregues']) === $resumo['total'])
                || (($fluxoPdvSemKds || $temContasAbertas) && $resumo['total'] > 0 && in_array($status, $statusAbertos, true))
                // Take-away: pagar no acto de enviar (pedido ainda em preparo no KDS).
                || ($pagarAoEnviar && $resumo['total'] > 0 && in_array($status, $statusAbertos, true));

            if ($pagarAoEnviar && $observacao === '') {
                $observacao = 'TAKEAWAY_PREPAGO';
            } elseif ($pagarAoEnviar && stripos($observacao, 'TAKEAWAY_PREPAGO') === false) {
                $observacao = 'TAKEAWAY_PREPAGO | ' . $observacao;
            }

            if (!$elegivel) {
                throw new Exception(
                    'Somente pedidos PRONTO/ENTREGUE/CONTA (sem itens pendentes) podem ser pagos. '
                    . 'Status pedido=' . $status
                    . ' | itens: pendentes=' . $resumo['pendentes']
                    . ', em_preparo=' . $resumo['em_preparo']
                    . ', prontos=' . $resumo['prontos']
                    . ', entregues=' . $resumo['entregues']
                    . ', total=' . $resumo['total']
                );
            }

            // Referencia automatica para pagamentos electronicos sem referencia.
            if (in_array($forma, ['MPESA', 'EMOLA', 'TRANSFERENCIA', 'CARTAO'], true) && $referencia === '') {
                $referencia = $forma . '-' . date('YmdHis') . '-P' . $pedidoId;
                $refLabel = 'Ref. ' . $forma . ': ' . $referencia;
                $observacao = $observacao !== '' ? ($observacao . ' | ' . $refLabel) : $refLabel;
            }

            $caixaAberto = (new Caixa($db))->buscarAberto($restauranteId);
            if (!$caixaAberto) {
                throw new Exception('Nao ha caixa aberto. Abra o caixa para receber pagamentos.');
            }

            $stmtItens = $db->prepare('
                SELECT ip.produto_id, ip.promocao_id, ip.quantidade, ip.preco_unitario, ip.subtotal
                FROM itens_pedido ip WHERE ip.pedido_id = :pedido_id
            ');
            $stmtItens->execute(['pedido_id' => $pedidoId]);
            $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);
            if (!$itens) {
                throw new Exception('Pedido sem itens para pagamento');
            }

            $totais = self::calcularTotais($itens, $descontoPercent);
            $totalBruto = $totais['total_bruto'];
            $descontoValor = $totais['desconto'];
            $totalFinal = $totais['total_final'];

            if ($forma === 'DINHEIRO' && $valorRecebido !== null && $valorRecebido + 0.0001 < $totalFinal) {
                throw new Exception(
                    'Valor recebido inferior ao total a pagar (recebido ' . number_format($valorRecebido, 2, ',', '.')
                    . ' MZN, total ' . number_format($totalFinal, 2, ',', '.') . ' MZN).'
                );
            }
            $troco = $valorRecebido !== null ? round($valorRecebido - $totalFinal, 2) : null;

            $venda = new Venda($db);
            $venda->restaurante_id = $restauranteId;
            $venda->usuario_id = $usuarioId;
            $venda->caixa_id = (int)$caixaAberto['id'];
            $venda->mesa_id = !empty($pedido['mesa_id']) ? (int)$pedido['mesa_id'] : null;
            $venda->total = $totalBruto;
            $venda->desconto = $descontoValor;
            $venda->total_final = $totalFinal;
            $venda->forma_pagamento = $forma;
            $venda->valor_recebido = $valorRecebido;
            $venda->troco = $troco;
            $venda->status = 'PAGO';
            $venda->numero_fatura = $venda->gerarNumeroFatura($restauranteId);

            $vendaId = $venda->criar();
            if (!$vendaId) {
                throw new Exception('Erro ao criar venda');
            }

            $produto = new Produto($db);
            $estoqueAvancado = !empty($ctx['estoque_avancado']);
            foreach ($itens as $item) {
                $promocaoId = !empty($item['promocao_id']) ? (int)$item['promocao_id'] : null;
                if (!$venda->adicionarItem($vendaId, (int)$item['produto_id'], (int)$item['quantidade'], (float)$item['preco_unitario'], $promocaoId)) {
                    throw new Exception('Erro ao adicionar item da venda');
                }
                // Estoque simples: nao bloqueia o pagamento se a baixa falhar.
                if (!$estoqueAvancado) {
                    try {
                        $produto->atualizarEstoque((int)$item['produto_id'], $restauranteId, (int)$item['quantidade'], 'SAIDA');
                    } catch (Throwable $stockErr) {
                        error_log('[pagamento] estoque produto: ' . $stockErr->getMessage());
                    }
                }
            }

            $this->marcarPedidoPago($pedidoId, $restauranteId, $totalBruto, $observacao);

            if (!empty($pedido['mesa_id'])) {
                $this->libertarMesaSeSemPedidosAbertos($restauranteId, (int)$pedido['mesa_id'], $pedidoId);
            }

            $resposta = [
                'success' => true,
                'message' => 'Pagamento registrado com sucesso',
                'venda_id' => (int)$vendaId,
                'numero_fatura' => $venda->numero_fatura,
                'valor_recebido' => $valorRecebido,
                'troco' => $troco,
            ];
            offline_idempotency_complete($db, $idempotencia, $resposta);
            $db->commit();
            return self::resultado($resposta);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                try {
                    $db->rollBack();
                } catch (Throwable $rb) {
                    // ignorar
                }
            }
            $msg = trim($e->getMessage());
            error_log('[pagamento] ' . $msg);
            return self::resultado(['success' => false, 'message' => self::mensagemPublica($msg)]);
        }
    }

    /**
     * Totais da venda a partir dos itens do pedido.
     * @return array{total_bruto:float, desconto:float, total_final:float}
     */
    public static function calcularTotais(array $itens, float $descontoPercent): array
    {
        $bruto = 0.0;
        foreach ($itens as $item) {
            $bruto += isset($item['subtotal'])
                ? (float)$item['subtotal']
                : ((float)$item['quantidade'] * (float)$item['preco_unitario']);
        }
        $desconto = round($bruto * ($descontoPercent / 100), 2);
        return ['total_bruto' => $bruto, 'desconto' => $desconto, 'total_final' => max(0.0, $bruto - $desconto)];
    }

    /**
     * Valor recebido em numero, ou null se nao informado.
     * Em DINHEIRO, 0/vazio conta como "nao informado" (o caixa_mesas envia 0
     * quando o campo fica vazio) - sem troco calculado, como antes.
     */
    public static function normalizarValorRecebido($bruto, string $forma): ?float
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }
        $valor = (float)str_replace(',', '.', (string)$bruto);
        if (!is_finite($valor)) {
            return null;
        }
        if ($forma === 'DINHEIRO' && $valor <= 0) {
            return null;
        }
        return $valor;
    }

    /** Mensagem segura para o utilizador (sem detalhes tecnicos em producao). */
    public static function mensagemPublica(string $msg): string
    {
        $local = function_exists('security_is_local_request') && function_exists('security_is_production')
            && security_is_local_request() && !security_is_production();
        if ($local || preg_match('/referencia|caixa|turno|pago|cancelado|encontrado|itens|Somente pedidos|Desconto|Forma|Pedido|estoque|venda|mesa|recebido/i', $msg)) {
            return $msg !== '' ? $msg : 'Nao foi possivel registrar o pagamento.';
        }
        return 'Nao foi possivel registrar o pagamento.';
    }

    /** @return array{total:int,pendentes:int,em_preparo:int,prontos:int,entregues:int} */
    private function resumoItens(int $pedidoId): array
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total,
                SUM(CASE WHEN LOWER(COALESCE(status,'pendente')) = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN LOWER(COALESCE(status,'pendente')) = 'em_preparo' THEN 1 ELSE 0 END) AS em_preparo,
                SUM(CASE WHEN LOWER(COALESCE(status,'pendente')) = 'pronto' THEN 1 ELSE 0 END) AS prontos,
                SUM(CASE WHEN LOWER(COALESCE(status,'pendente')) = 'entregue' THEN 1 ELSE 0 END) AS entregues
            FROM itens_pedido WHERE pedido_id = :pedido_id
        ");
        $stmt->execute(['pedido_id' => $pedidoId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int)($r['total'] ?? 0),
            'pendentes' => (int)($r['pendentes'] ?? 0),
            'em_preparo' => (int)($r['em_preparo'] ?? 0),
            'prontos' => (int)($r['prontos'] ?? 0),
            'entregues' => (int)($r['entregues'] ?? 0),
        ];
    }

    private function marcarPedidoPago(int $pedidoId, int $restauranteId, float $totalBruto, string $observacao): void
    {
        $set = "status = 'PAGO', total = :total, atualizado_em = NOW(),
                entregue_em = COALESCE(entregue_em, NOW()), pago_em = COALESCE(pago_em, NOW())";
        $params = ['total' => $totalBruto, 'id' => $pedidoId, 'rid' => $restauranteId];
        if ($observacao !== '') {
            $set .= ", observacao = CASE WHEN observacao IS NULL OR observacao = '' THEN :obs_value
                                       ELSE CONCAT(observacao, ' | ', :obs_concat) END";
            $params['obs_value'] = $observacao;
            $params['obs_concat'] = $observacao;
        }
        $this->db->prepare("UPDATE pedidos SET {$set} WHERE id = :id AND restaurante_id = :rid")->execute($params);
    }

    private function libertarMesaSeSemPedidosAbertos(int $restauranteId, int $mesaId, int $pedidoId): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM pedidos
            WHERE restaurante_id = :rid AND mesa_id = :mesa_id AND id <> :pedido_id
              AND status IN ('PENDENTE','NOVO','CONFIRMADO','PREPARANDO','PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA')
        ");
        $stmt->execute(['rid' => $restauranteId, 'mesa_id' => $mesaId, 'pedido_id' => $pedidoId]);
        if ((int)$stmt->fetchColumn() === 0) {
            // Liberta a mesa e conclui a reserva (so depois do pagamento).
            reserva_liberar_mesa_apos_pagamento($this->db, $restauranteId, $mesaId);
        }
    }

    private static function resultado(array $body, int $http = 200): array
    {
        return ['http' => $http, 'body' => $body];
    }
}
