<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/pedido_schema.php';
require_once __DIR__ . '/../config/promocao_schema.php';
require_once __DIR__ . '/../config/mesa_display_helper.php';
require_once __DIR__ . '/../config/offline_idempotency.php';
require_once __DIR__ . '/../config/estoque_inteligente.php';

/**
 * Criacao de pedidos pelo staff (PDV, garcom, balcao).
 *
 * Extraido de src/public/api/pedido_novo.php. Nao le $_SESSION nem escreve
 * respostas HTTP: recebe o contexto ja autenticado e devolve um resultado,
 * o que permite testa-lo (tests/Integration/PedidoServiceTest.php).
 *
 * Pedidos de clientes via QR NAO passam por aqui (ver reserva_publica_handler).
 */
final class PedidoService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array{
     *   restaurante_id:int, usuario_id:int, perfil:string,
     *   plano_nome?:string, estoque_avancado?:bool, filial_id?:?int,
     *   idempotencia_payload?:array
     * } $ctx Contexto autenticado (o restaurante vem SEMPRE da sessao).
     * @param array{mesa_id:int, observacao?:string, itens:array} $dados
     * @return array{http:int, body:array, evento:?array}
     */
    public function criar(array $ctx, array $dados): array
    {
        $db = $this->db;
        $restauranteId = (int)$ctx['restaurante_id'];
        $usuarioId = (int)($ctx['usuario_id'] ?? 0);
        $mesaId = (int)($dados['mesa_id'] ?? 0);
        $observacao = trim((string)($dados['observacao'] ?? ''));
        $itens = is_array($dados['itens'] ?? null) ? $dados['itens'] : [];

        if ($restauranteId <= 0) {
            return self::resultado(['success' => false, 'message' => 'Nao autenticado'], 401);
        }
        if ($mesaId <= 0) {
            return self::resultado(['success' => false, 'message' => 'Mesa invalida']);
        }
        if (empty($itens)) {
            return self::resultado(['success' => false, 'message' => 'Itens invalidos']);
        }

        pedido_schema_garantir($db);
        try {
            estoque_inteligente_schema_garantir($db);
        } catch (Throwable $schemaError) {
            self::log('falha ao garantir schema estoque: ' . $schemaError->getMessage());
        }

        [$hasProdutoDestino, $hasItemDestino, $hasItemStatus] = $this->colunasOpcionais();

        $stmtMesa = $db->prepare('SELECT id, numero, garcom_id FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1');
        $stmtMesa->execute(['id' => $mesaId, 'rid' => $restauranteId]);
        $mesa = $stmtMesa->fetch(PDO::FETCH_ASSOC);
        if (!$mesa) {
            return self::resultado(['success' => false, 'message' => 'Mesa nao encontrada']);
        }

        $garcomId = $usuarioId;
        $origem = pedido_normalizar_origem(
            turno_normalizar_perfil_seguro((string)($ctx['perfil'] ?? '')) === 'GARCOM' ? 'GARCOM' : 'BALCAO'
        );

        offline_idempotency_prepare($db);
        pedido_sequencia_preparar($db, $restauranteId); // fora da transacao
        $db->beginTransaction();

        try {
            $idempotencia = offline_idempotency_begin(
                $db,
                $restauranteId,
                $usuarioId,
                'api/pedido_novo.php',
                (array)($ctx['idempotencia_payload'] ?? [])
            );
            if (!empty($idempotencia['replay'])) {
                $db->commit();
                return self::resultado((array)$idempotencia['response'], (int)($idempotencia['http_status'] ?? 200));
            }

            // Seguranca: campos internos (_preco_override...) do cliente sao removidos aqui.
            $itens = promocao_expandir_linhas($db, $restauranteId, $itens);
            $numeroPedido = pedido_proximo_numero($db, $restauranteId);

            $produtosMap = $this->carregarProdutos($restauranteId, $itens, $hasProdutoDestino);

            $total = 0.0;
            $itensNormalizados = [];
            foreach ($itens as $item) {
                $produtoId = (int)($item['id'] ?? $item['produto_id'] ?? 0);
                $qtd = (int)($item['qtd'] ?? $item['qty'] ?? $item['quantidade'] ?? 0);
                if ($produtoId <= 0 || $qtd <= 0 || !isset($produtosMap[$produtoId])) {
                    continue;
                }
                $p = $produtosMap[$produtoId];
                $preco = array_key_exists('_preco_override', $item) ? (float)$item['_preco_override'] : (float)($p['preco'] ?? 0);
                $subtotal = array_key_exists('_subtotal_override', $item) ? (float)$item['_subtotal_override'] : $preco * $qtd;
                $promocaoNome = trim((string)($item['_promocao_nome'] ?? ''));

                $itensNormalizados[] = [
                    'produto_id' => $produtoId,
                    'produto_nome' => $promocaoNome !== ''
                        ? ('[' . $promocaoNome . '] ' . (string)($p['nome'] ?? ''))
                        : (string)($p['nome'] ?? ''),
                    'qtd' => $qtd,
                    'preco' => $preco,
                    'subtotal' => $subtotal,
                    'destino' => self::inferirDestino($p['categoria'] ?? null, $p['nome'] ?? null),
                    'promocao_id' => (int)($item['_promocao_id'] ?? 0),
                ];
                $total += $subtotal;
            }

            if (empty($itensNormalizados)) {
                throw new Exception('Nenhum item valido para criar o pedido');
            }

            $estoqueAvancado = !empty($ctx['estoque_avancado']);
            $filialId = isset($ctx['filial_id']) && (int)$ctx['filial_id'] > 0 ? (int)$ctx['filial_id'] : null;

            if ($estoqueAvancado && strtoupper((string)($ctx['plano_nome'] ?? '')) === 'PROFISSIONAL') {
                $itensFicha = array_map(static fn($it) => ['id' => $it['produto_id'], 'quantidade' => $it['qtd']], $itensNormalizados);
                estoque_validar_itens_com_ficha_obrigatoria($db, $restauranteId, $filialId, $itensFicha);
            }

            // Idempotencia curta: duplo clique / repeticao do frontend.
            $obsNorm = function_exists('mb_strtolower') ? mb_strtolower($observacao, 'UTF-8') : strtolower($observacao);
            $duplicado = $this->procurarDuplicadoRecente($restauranteId, $mesaId, $origem, $obsNorm, $itensNormalizados);
            if ($duplicado !== null) {
                offline_idempotency_complete($db, $idempotencia, $duplicado);
                $db->commit();
                return self::resultado($duplicado);
            }

            $destinos = array_values(array_unique(array_map(
                static fn($i) => strtolower((string)($i['destino'] ?? 'cozinha')),
                $itensNormalizados
            )));
            sort($destinos);
            $destinoResumo = 'cozinha';
            if (in_array('bar', $destinos, true) && in_array('cozinha', $destinos, true)) {
                $destinoResumo = 'cozinha_bar';
            } elseif (in_array('bar', $destinos, true)) {
                $destinoResumo = 'bar';
            }

            $db->prepare(
                "INSERT INTO pedidos (numero_pedido, mesa_id, garcom_id, status, total, observacao, origem, criado_em, atualizado_em, restaurante_id)
                 VALUES (:numero, :mesa_id, :garcom_id, 'PREPARANDO', :total, :observacao, :origem, NOW(), NOW(), :rid)"
            )->execute([
                'numero' => $numeroPedido,
                'mesa_id' => $mesaId,
                'garcom_id' => $garcomId > 0 ? $garcomId : null,
                'total' => $total,
                'observacao' => $observacao ?: null,
                'origem' => $origem,
                'rid' => $restauranteId,
            ]);
            $pedidoId = (int)$db->lastInsertId();

            $this->inserirItens($pedidoId, $itensNormalizados, $hasItemDestino && $hasItemStatus);

            // Baixa de ingredientes pela receita: Estoque Inteligente ou Ingredientes simples (Basico).
            if ($estoqueAvancado || !empty($ctx['estoque_ingredientes'])) {
                try {
                    estoque_baixa_automatica_por_pedido($db, $restauranteId, $filialId, $pedidoId, $garcomId > 0 ? $garcomId : null);
                    if ($estoqueAvancado) {
                        estoque_gerar_alertas_automaticos($db, $restauranteId, $filialId);
                    }
                } catch (Throwable $stockError) {
                    self::log('falha em estoque avancado: ' . $stockError->getMessage());
                }
            }

            // Balcao/take-away fica sempre LIVRE; mesa normal fica OCUPADA.
            if (mesa_eh_balcao($mesa['numero'] ?? '')) {
                $db->prepare("UPDATE mesas SET status = 'LIVRE' WHERE id = :id AND restaurante_id = :rid")
                    ->execute(['id' => $mesaId, 'rid' => $restauranteId]);
            } else {
                $db->prepare(
                    "UPDATE mesas SET status = 'OCUPADA', garcom_id = COALESCE(garcom_id, :garcom_id) WHERE id = :id AND restaurante_id = :rid"
                )->execute(['id' => $mesaId, 'rid' => $restauranteId, 'garcom_id' => $garcomId > 0 ? $garcomId : null]);
            }

            $resposta = [
                'success' => true,
                'message' => 'Pedido criado com sucesso!',
                'pedido_id' => $pedidoId,
                'numero_pedido' => $numeroPedido,
                'total' => number_format($total, 2, ',', '.'),
                'status' => 'PREPARANDO',
                'origem' => $origem,
                'destinos' => $destinos,
                'destino_resumo' => $destinoResumo,
            ];
            offline_idempotency_complete($db, $idempotencia, $resposta);
            $db->commit();

            return self::resultado($resposta, 200, [
                'id' => $pedidoId,
                'numero_pedido' => $numeroPedido,
                'mesa_id' => $mesaId,
                'mesa_numero' => $mesa['numero'] ?? '?',
                'garcom_id' => $garcomId > 0 ? $garcomId : null,
                'status' => 'PREPARANDO',
                'total' => $total,
                'criado_em' => date('c'),
                'restaurante_id' => $restauranteId,
                'origem' => $origem,
            ]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::log($e->getMessage());
            $msg = (string)$e->getMessage();
            if (strpos($msg, 'ESTOQUE_INSUFICIENTE:') === 0) {
                return self::resultado([
                    'success' => false,
                    'message' => 'Estoque insuficiente para concluir pedido.',
                    'detail' => trim(substr($msg, strlen('ESTOQUE_INSUFICIENTE:'))),
                ], 422);
            }
            if (strpos($msg, 'SEM_FICHA:') === 0) {
                return self::resultado([
                    'success' => false,
                    'message' => 'Não é possível pedir sem ficha técnica / insumos: ' . trim(substr($msg, strlen('SEM_FICHA:'))),
                ], 422);
            }
            if ($e instanceof DomainException) {
                // Promocao indisponivel, identificador offline reutilizado, etc.
                return self::resultado(['success' => false, 'message' => $msg], 422);
            }
            return self::resultado(['success' => false, 'message' => 'Erro interno ao criar pedido.'], 500);
        }
    }

    /** Destino (bar/cozinha) inferido pelo nome do produto e da categoria. */
    public static function inferirDestino($categoria, $nome): string
    {
        $txt = strtolower(trim((string)(($categoria ?? '') . ' ' . ($nome ?? ''))));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
        if ($ascii !== false) {
            $txt = strtolower($ascii);
        }
        $bebidas = [
            'bebida', 'bar', 'drink', 'coquetel', 'cocktail', 'sumo', 'suco',
            'refrigerante', 'agua', 'agua mineral', 'cerveja', 'vinho', 'whisky',
            'rum', 'vodka', 'gin', 'licor', 'cha', 'te', 'cafe', 'espresso',
            'capuccino', 'cappuccino', 'latte', 'milkshake', 'batido', 'smoothie',
            'energetico', 'red bull', 'guarana', 'fanta', 'sprite', 'pepsi',
            'coca', 'coca-cola', 'schweppes', 'tonica',
        ];
        foreach ($bebidas as $kw) {
            if (strpos($txt, $kw) !== false) {
                return 'bar';
            }
        }
        return 'cozinha';
    }

    /** @return array{0:bool,1:bool,2:bool} produtos.destino, itens_pedido.destino, itens_pedido.status */
    private function colunasOpcionais(): array
    {
        try {
            return [
                (bool)$this->db->query("SHOW COLUMNS FROM produtos LIKE 'destino'")->fetch(PDO::FETCH_ASSOC),
                (bool)$this->db->query("SHOW COLUMNS FROM itens_pedido LIKE 'destino'")->fetch(PDO::FETCH_ASSOC),
                (bool)$this->db->query("SHOW COLUMNS FROM itens_pedido LIKE 'status'")->fetch(PDO::FETCH_ASSOC),
            ];
        } catch (Throwable $e) {
            return [false, false, false]; // bases antigas
        }
    }

    /** @return array<int, array> produtos activos do restaurante, por id */
    private function carregarProdutos(int $restauranteId, array $itens, bool $comDestino): array
    {
        $ids = [];
        foreach ($itens as $item) {
            $pid = (int)($item['id'] ?? $item['produto_id'] ?? 0);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            throw new Exception('Nenhum produto valido no pedido');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT p.id, p.preco, p.nome, c.nome AS categoria' . ($comDestino ? ', p.destino' : '')
            . " FROM produtos p LEFT JOIN categorias c ON c.id = p.categoria_id
                WHERE p.restaurante_id = ? AND p.ativo = 1 AND p.id IN ($in)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$restauranteId], $ids));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $map[(int)$p['id']] = $p;
        }
        return $map;
    }

    /** Devolve a resposta "ja processado" se o mesmo pedido foi criado ha menos de 8 s. */
    private function procurarDuplicadoRecente(int $restauranteId, int $mesaId, string $origem, string $obsNorm, array $itens): ?array
    {
        $finger = static function (array $linhas): string {
            sort($linhas);
            return implode('|', $linhas);
        };
        $atuais = [];
        foreach ($itens as $it) {
            $atuais[] = $it['produto_id'] . 'x' . $it['qtd'] . '@' . ($it['promocao_id'] ?? 0) . ':' . number_format((float)$it['subtotal'], 2, '.', '');
        }
        $hashAtual = hash('sha256', $finger($atuais) . '|' . $mesaId . '|' . $obsNorm . '|' . $origem);

        $stmt = $this->db->prepare("
            SELECT id, numero_pedido FROM pedidos
            WHERE restaurante_id = :rid AND mesa_id = :mesa_id AND origem = :origem
              AND criado_em >= DATE_SUB(NOW(), INTERVAL 8 SECOND)
            ORDER BY id DESC LIMIT 5
        ");
        $stmt->execute(['rid' => $restauranteId, 'mesa_id' => $mesaId, 'origem' => $origem]);
        $stmtItens = $this->db->prepare('
            SELECT produto_id, quantidade, subtotal, promocao_id FROM itens_pedido
            WHERE pedido_id = :pedido_id ORDER BY produto_id ASC, quantidade ASC
        ');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cand) {
            $stmtItens->execute(['pedido_id' => (int)$cand['id']]);
            $linhas = [];
            foreach ($stmtItens->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ci) {
                $linhas[] = ((int)$ci['produto_id']) . 'x' . ((int)$ci['quantidade'])
                    . '@' . (int)($ci['promocao_id'] ?? 0) . ':' . number_format((float)($ci['subtotal'] ?? 0), 2, '.', '');
            }
            if ($linhas && hash_equals($hashAtual, hash('sha256', $finger($linhas) . '|' . $mesaId . '|' . $obsNorm . '|' . $origem))) {
                return [
                    'success' => true,
                    'message' => 'Pedido ja processado (idempotencia).',
                    'pedido_id' => (int)$cand['id'],
                    'numero_pedido' => (string)($cand['numero_pedido'] ?? ''),
                    'duplicado' => true,
                ];
            }
        }
        return null;
    }

    private function inserirItens(int $pedidoId, array $itens, bool $comDestinoEStatus): void
    {
        $sql = $comDestinoEStatus
            ? "INSERT INTO itens_pedido (pedido_id, produto_id, promocao_id, produto_nome, quantidade, destino, status, preco_unitario, subtotal)
               VALUES (:pedido_id, :produto_id, :promocao_id, :produto_nome, :qtd, :destino, 'em_preparo', :preco, :subtotal)"
            : "INSERT INTO itens_pedido (pedido_id, produto_id, promocao_id, produto_nome, quantidade, preco_unitario, subtotal)
               VALUES (:pedido_id, :produto_id, :promocao_id, :produto_nome, :qtd, :preco, :subtotal)";
        $stmt = $this->db->prepare($sql);
        foreach ($itens as $item) {
            $params = [
                'pedido_id' => $pedidoId,
                'produto_id' => $item['produto_id'],
                'promocao_id' => !empty($item['promocao_id']) ? $item['promocao_id'] : null,
                'produto_nome' => $item['produto_nome'],
                'qtd' => $item['qtd'],
                'preco' => $item['preco'],
                'subtotal' => $item['subtotal'],
            ];
            if ($comDestinoEStatus) {
                $params['destino'] = $item['destino'];
            }
            $stmt->execute($params);
        }
    }

    private static function resultado(array $body, int $http = 200, ?array $evento = null): array
    {
        return ['http' => $http, 'body' => $body, 'evento' => $evento];
    }

    private static function log(string $mensagem): void
    {
        @error_log('pedido_novo: ' . $mensagem);
        if (defined('APP_BASE')) {
            @file_put_contents(APP_BASE . '/storage/logs/pedido_novo_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL, FILE_APPEND);
        }
    }
}

if (!function_exists('turno_normalizar_perfil_seguro')) {
    /** turno_normalizar_perfil() quando disponivel; senao normalizacao basica. */
    function turno_normalizar_perfil_seguro(string $perfil): string
    {
        if (!function_exists('turno_normalizar_perfil')) {
            $helpers = __DIR__ . '/../config/turno_helpers.php';
            if (is_file($helpers)) {
                require_once $helpers;
            }
        }
        if (function_exists('turno_normalizar_perfil')) {
            return (string)turno_normalizar_perfil($perfil);
        }
        $p = strtoupper(trim($perfil));
        return in_array($p, ['GARÇOM', 'OPERADOR', 'FUNCIONARIO'], true) ? 'GARCOM' : $p;
    }
}
