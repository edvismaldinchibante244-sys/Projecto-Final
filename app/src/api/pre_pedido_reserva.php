<?php
/**
 * Pre-pedido de uma reserva feita pelo QR.
 *
 * O cliente escolhe os pratos ao reservar, mas a cozinha so os deve receber quando
 * ele chega. Por isso o carrinho fica guardado em reserva_pre_pedidos (com os precos
 * que o cliente viu) e so passa a pedido real no check-in. Pedidos por QR sem
 * reserva (cardapio na mesa) continuam a ir logo para a cozinha.
 */

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

require_once __DIR__ . '/../config/pedido_schema.php';

if (!function_exists('reserva_pre_pedido_gravar_pedido')) {
    /**
     * Grava pedido + itens + baixa de stock simples + pedido_qr_meta.
     * Nao abre nem fecha transacao: o chamador faz pedido_sequencia_preparar() antes
     * e envolve a chamada numa transacao. Codigo movido de criar_pedido_qr_publico().
     *
     * @param array $c restauranteId, mesaId, mesa, observacao, obsItensParts, clienteNome,
     *                 clienteTelefone, total, temColObsItem, itensNormalizados, temEstoqueAvancado,
     *                 token, idemKey
     * @return array{pedido_id:int, numero:string}
     */
    function reserva_pre_pedido_gravar_pedido(PDO $db, array $c): array
    {
        $restauranteId = (int)$c['restauranteId'];
        $mesaId = (int)$c['mesaId'];
        $mesa = (array)($c['mesa'] ?? []);
        $observacao = (string)($c['observacao'] ?? '');
        $obsItensParts = (array)($c['obsItensParts'] ?? []);
        $clienteNome = (string)($c['clienteNome'] ?? '');
        $clienteTelefone = (string)($c['clienteTelefone'] ?? '');
        $total = (float)$c['total'];
        $temColObsItem = (bool)($c['temColObsItem'] ?? false);
        $itensNormalizados = (array)$c['itensNormalizados'];
        $temEstoqueAvancado = (bool)($c['temEstoqueAvancado'] ?? false);
        $token = (string)$c['token'];
        $idemKey = (string)$c['idemKey'];

    $numero = pedido_proximo_numero($db, $restauranteId);

    $stmtPedido = $db->prepare("
        INSERT INTO pedidos (numero_pedido, mesa_id, garcom_id, status, total, observacao, origem, criado_em, atualizado_em, restaurante_id)
        VALUES (:numero, :mesa_id, :garcom_id, 'PREPARANDO', :total, :obs, 'QR', NOW(), NOW(), :rid)
    ");
    $obsParts = [];
    if ($observacao !== '') $obsParts[] = $observacao;
    if (!empty($obsItensParts)) $obsParts[] = 'Itens: ' . implode('; ', $obsItensParts);
    if ($clienteNome !== '') $obsParts[] = 'Cliente: ' . $clienteNome;
    if ($clienteTelefone !== '') $obsParts[] = 'Tel: ' . $clienteTelefone;
    $obsFinal = trim(implode(' | ', $obsParts));
    $stmtPedido->execute([
        ':numero' => $numero,
        ':mesa_id' => $mesaId,
        ':garcom_id' => !empty($mesa['garcom_id']) ? (int)$mesa['garcom_id'] : null,
        ':total' => $total,
        ':obs' => $obsFinal !== '' ? $obsFinal : null,
        ':rid' => $restauranteId,
    ]);
    $pedidoId = (int)$db->lastInsertId();

    if ($temColObsItem) {
        $stmtItem = $db->prepare("
            INSERT INTO itens_pedido (pedido_id, produto_id, promocao_id, produto_nome, quantidade, destino, status, preco_unitario, subtotal, observacao)
            VALUES (:pedido_id, :produto_id, :promocao_id, :produto_nome, :qtd, :destino, 'em_preparo', :preco, :subtotal, :observacao)
        ");
    } else {
        $stmtItem = $db->prepare("
            INSERT INTO itens_pedido (pedido_id, produto_id, promocao_id, produto_nome, quantidade, destino, status, preco_unitario, subtotal)
            VALUES (:pedido_id, :produto_id, :promocao_id, :produto_nome, :qtd, :destino, 'em_preparo', :preco, :subtotal)
        ");
    }
    foreach ($itensNormalizados as $item) {
        $params = [
            ':pedido_id' => $pedidoId,
            ':produto_id' => $item['produto_id'],
            ':produto_nome' => $item['produto_nome'],
            ':promocao_id' => !empty($item['promocao_id']) ? $item['promocao_id'] : null,
            ':qtd' => $item['qtd'],
            ':destino' => $item['destino'],
            ':preco' => $item['preco'],
            ':subtotal' => $item['subtotal'],
        ];
        if ($temColObsItem) {
            $params[':observacao'] = $item['observacao'] !== '' ? $item['observacao'] : null;
        }
        $stmtItem->execute($params);
    }

    if (!$temEstoqueAvancado) {
        $stmtStock = $db->prepare("
            UPDATE produtos
            SET estoque = GREATEST(0, COALESCE(estoque, 0) - :qtd)
            WHERE id = :id AND restaurante_id = :rid
        ");
        foreach ($itensNormalizados as $item) {
            $stmtStock->execute([
                ':qtd' => (int)$item['qtd'],
                ':id' => (int)$item['produto_id'],
                ':rid' => $restauranteId,
            ]);
        }
    }

    $stmtMeta = $db->prepare("
        INSERT INTO pedido_qr_meta (pedido_id, restaurante_id, token, idem_key, cliente_nome, cliente_telefone)
        VALUES (:pedido_id, :rid, :token, :idem_key, :cliente_nome, :cliente_telefone)
    ");
    $stmtMeta->execute([
        ':pedido_id' => $pedidoId,
        ':rid' => $restauranteId,
        ':token' => $token,
        ':idem_key' => $idemKey,
        ':cliente_nome' => $clienteNome !== '' ? $clienteNome : null,
        ':cliente_telefone' => $clienteTelefone !== '' ? $clienteTelefone : null,
    ]);

        return ['pedido_id' => $pedidoId, 'numero' => (string)$numero];
    }
}

if (!function_exists('reserva_pre_pedido_tabela_existe')) {
    function reserva_pre_pedido_tabela_existe(PDO $db): bool
    {
        static $cache = [];
        $k = spl_object_id($db);
        if (!array_key_exists($k, $cache)) {
            $stmt = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reserva_pre_pedidos'");
            $cache[$k] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$k];
    }
}

if (!function_exists('reserva_pre_pedido_reserva_em_espera')) {
    /** Reserva desta sessao QR em que o cliente ainda nao chegou (pre-pedido fica em espera), ou 0. */
    function reserva_pre_pedido_reserva_em_espera(PDO $db, int $restauranteId, int $sessaoId): int
    {
        if ($sessaoId <= 0 || !reserva_pre_pedido_tabela_existe($db)
            || !function_exists('reserva_qr_coluna_existe') || !reserva_qr_coluna_existe($db, 'reservas', 'qr_sessao_id')) {
            return 0;
        }
        $stmt = $db->prepare("SELECT id FROM reservas WHERE restaurante_id = :rid AND qr_sessao_id = :s AND status IN ('pendente', 'confirmado') ORDER BY id DESC LIMIT 1");
        $stmt->execute([':rid' => $restauranteId, ':s' => $sessaoId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('reserva_pre_pedido_guardar')) {
    /** Guarda o carrinho validado ate ao check-in. Idempotente pela chave do cliente. */
    function reserva_pre_pedido_guardar(PDO $db, int $reservaId, array $c): array
    {
        $restauranteId = (int)$c['restauranteId'];
        $stmt = $db->prepare('SELECT id, total FROM reserva_pre_pedidos WHERE restaurante_id = :rid AND token = :token AND idem_key = :idem LIMIT 1');
        $stmt->execute([':rid' => $restauranteId, ':token' => (string)$c['token'], ':idem' => (string)$c['idemKey']]);
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existente) {
            unset($c['mesa']);
            $db->prepare('INSERT INTO reserva_pre_pedidos (restaurante_id, reserva_id, token, idem_key, dados, total, cliente_nome)
                          VALUES (:rid, :reserva, :token, :idem, :dados, :total, :nome)')
                ->execute([
                    ':rid' => $restauranteId,
                    ':reserva' => $reservaId,
                    ':token' => (string)$c['token'],
                    ':idem' => (string)$c['idemKey'],
                    ':dados' => json_encode($c, JSON_UNESCAPED_UNICODE),
                    ':total' => (float)$c['total'],
                    ':nome' => ($c['clienteNome'] ?? '') !== '' ? (string)$c['clienteNome'] : null,
                ]);
        }
        $total = $existente ? (float)$existente['total'] : (float)$c['total'];
        return [
            'success' => true,
            'message' => 'Pre-pedido guardado. Vai para a cozinha quando fizer o check-in no restaurante.',
            'em_espera' => true,
            'pre_pedido_id' => $existente ? (int)$existente['id'] : (int)$db->lastInsertId(),
            'numero_pedido' => '',
            'total' => number_format($total, 2, ',', '.'),
            'status' => 'AGUARDA_CHECKIN',
            'idempotent' => (bool)$existente,
            'http_code' => 200,
        ];
    }
}

if (!function_exists('reserva_pre_pedido_em_espera_do_token')) {
    /** Para o acompanhamento do cliente: pre-pedido guardado e ainda nao enviado. */
    function reserva_pre_pedido_em_espera_do_token(PDO $db, string $token): ?array
    {
        if ($token === '' || !reserva_pre_pedido_tabela_existe($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT COUNT(*) AS n, SUM(total) AS total FROM reserva_pre_pedidos WHERE token = :token AND enviado_em IS NULL');
        $stmt->execute([':token' => $token]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return ((int)($r['n'] ?? 0)) > 0 ? ['total' => (float)$r['total']] : null;
    }
}

if (!function_exists('reserva_pre_pedido_enviar')) {
    /**
     * Check-in: transforma os pre-pedidos guardados da reserva em pedidos reais na mesa
     * do check-in. Corre DENTRO da transacao do check-in (chamar pedido_sequencia_preparar antes).
     * @return array{id:int, numero_pedido:string}|null o ultimo pedido criado
     */
    function reserva_pre_pedido_enviar(PDO $db, int $restauranteId, int $reservaId, int $mesaId): ?array
    {
        if (!reserva_pre_pedido_tabela_existe($db)) {
            return null;
        }
        $stmt = $db->prepare('SELECT id, dados FROM reserva_pre_pedidos WHERE restaurante_id = :rid AND reserva_id = :reserva AND enviado_em IS NULL ORDER BY id FOR UPDATE');
        $stmt->execute([':rid' => $restauranteId, ':reserva' => $reservaId]);
        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$pendentes) {
            return null;
        }
        $stmtMesa = $db->prepare('SELECT id, numero, garcom_id FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1');
        $stmtMesa->execute([':id' => $mesaId, ':rid' => $restauranteId]);
        $mesa = $stmtMesa->fetch(PDO::FETCH_ASSOC) ?: [];

        $ultimo = null;
        foreach ($pendentes as $pre) {
            $c = json_decode((string)$pre['dados'], true);
            if (!is_array($c) || empty($c['itensNormalizados'])) {
                error_log('[PRE_PEDIDO] Dados invalidos no pre-pedido ' . (int)$pre['id']);
                continue;
            }
            $c['restauranteId'] = $restauranteId;
            $c['mesaId'] = $mesaId;
            $c['mesa'] = $mesa;
            $gravado = reserva_pre_pedido_gravar_pedido($db, $c);
            $db->prepare('UPDATE reserva_pre_pedidos SET enviado_em = NOW(), pedido_id = :p WHERE id = :id')
                ->execute([':p' => $gravado['pedido_id'], ':id' => (int)$pre['id']]);
            if (function_exists('reserva_qr_coluna_existe') && reserva_qr_coluna_existe($db, 'pedido_qr_meta', 'reserva_id')) {
                $db->prepare('UPDATE pedido_qr_meta SET reserva_id = :r WHERE pedido_id = :p AND restaurante_id = :rid')
                    ->execute([':r' => $reservaId, ':p' => $gravado['pedido_id'], ':rid' => $restauranteId]);
            }
            $ultimo = ['id' => $gravado['pedido_id'], 'numero_pedido' => $gravado['numero'], 'mesa_id' => $mesaId, 'total' => (float)$c['total']];
        }
        return $ultimo;
    }
}
