<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/plano_check.php';
require_once APP_BASE . '/src/config/restaurante_context.php';
require_once APP_BASE . '/src/config/estoque_inteligente.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'estoque_transferir',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;

if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')
    || !plano_tem_funcionalidade_db($planoRid, 'transferencia_estoque')) {
    security_json([
        'success' => false,
        'message' => 'Transferencia de estoque disponivel apenas no plano Empresarial.',
        'funcionalidade' => 'transferencia_estoque',
    ], 403);
}

$insumoId = (int)($_POST['insumo_id'] ?? 0);
$quantidade = (float)str_replace(',', '.', (string)($_POST['quantidade'] ?? 0));
$unidade = trim((string)($_POST['unidade'] ?? 'un'));
$motivo = trim((string)($_POST['motivo'] ?? ''));
$filialOrigemPost = (int)($_POST['filial_origem_id'] ?? 0);
$filialDestinoPost = (int)($_POST['filial_destino_id'] ?? 0);

if ($insumoId <= 0 || $quantidade <= 0) {
    security_json(['success' => false, 'message' => 'Insumo e quantidade obrigatorios.'], 422);
}

if ($filialOrigemPost === $filialDestinoPost) {
    security_json(['success' => false, 'message' => 'Origem e destino devem ser diferentes.'], 422);
}

$matrizId = $planoRid;
$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

/**
 * 0 = stock central da matriz (restaurante_id = matriz).
 * >0 = restaurante filial (stock no proprio restaurante_id da filial).
 */
$resolverRestauranteStock = static function (int $idPost) use ($db, $matrizId): array {
    if ($idPost <= 0) {
        $stmt = $db->prepare('SELECT id, nome FROM restaurantes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $matrizId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Matriz nao encontrada.');
        }
        return [
            'restaurante_id' => $matrizId,
            'nome' => (string)$row['nome'],
            'tipo' => 'matriz',
        ];
    }

    $stmt = $db->prepare("
        SELECT id, nome, filial_id, COALESCE(is_matriz, 0) AS is_matriz
        FROM restaurantes
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $idPost]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new DomainException('Restaurante origem/destino invalido.');
    }

    $ok = ((int)$row['id'] === $matrizId)
        || ((int)$row['filial_id'] === $matrizId);
    if (!$ok) {
        throw new DomainException('Filial nao pertence a esta matriz.');
    }

    return [
        'restaurante_id' => (int)$row['id'],
        'nome' => (string)$row['nome'],
        'tipo' => ((int)$row['id'] === $matrizId) ? 'matriz' : 'filial',
    ];
};

try {
    $origem = $resolverRestauranteStock($filialOrigemPost);
    $destino = $resolverRestauranteStock($filialDestinoPost);
} catch (Throwable $e) {
    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Origem ou destino invalido.',
            'estoque_transferir_destino_invalido'
        ),
    ], 422);
}

if ((int)$origem['restaurante_id'] === (int)$destino['restaurante_id']) {
    security_json(['success' => false, 'message' => 'Origem e destino devem ser restaurantes diferentes.'], 422);
}

// Validar que o insumo pertence a origem (ou a matriz, se veio da lista da matriz)
$stmtIns = $db->prepare('SELECT id, restaurante_id, nome, unidade_base FROM estoque_insumos WHERE id = :id LIMIT 1');
$stmtIns->execute([':id' => $insumoId]);
$insumoOrigem = $stmtIns->fetch(PDO::FETCH_ASSOC);
if (!$insumoOrigem) {
    security_json(['success' => false, 'message' => 'Insumo nao encontrado.'], 404);
}

$insumoOwner = (int)$insumoOrigem['restaurante_id'];
$origemRid = (int)$origem['restaurante_id'];
$destinoRid = (int)$destino['restaurante_id'];

// Se o insumo listado e da matriz mas a origem e uma filial, espelhar primeiro na filial origem
if ($insumoOwner !== $origemRid) {
    if ($insumoOwner !== $matrizId) {
        security_json(['success' => false, 'message' => 'Insumo nao pertence a origem selecionada.'], 422);
    }
    // Permitir usar catalogo da matriz: cria/espelha o insumo na origem se for filial
    try {
        $insumoId = estoque_obter_ou_criar_insumo_no_restaurante($db, $insumoId, $origemRid);
    } catch (Throwable $e) {
        security_json([
            'success' => false,
            'message' => security_exception_message(
                $e,
                'Nao foi possivel validar o insumo da origem.',
                'estoque_transferir_insumo_failed'
            ),
        ], 422);
    }
}

$unidade = estoque_resolver_unidade_movimento($unidade, (string)($insumoOrigem['unidade_base'] ?? 'un'));
$baseQtd = abs(estoque_converter_para_base($quantidade, $unidade));
if ($baseQtd <= 0) {
    security_json(['success' => false, 'message' => 'Quantidade invalida.'], 422);
}

$ref = 'TRF-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
$db->beginTransaction();

try {
    // Stock por restaurante (filial_id NULL) — assim a filial ve o saldo no proprio Estoque Inteligente
    estoque_validar_saldo_para_consumo($db, $origemRid, null, [$insumoId => $baseQtd]);

    $insumoDestinoId = estoque_obter_ou_criar_insumo_no_restaurante($db, $insumoId, $destinoRid);

    estoque_upsert_saldo($db, $origemRid, null, $insumoId, -$baseQtd, null);
    estoque_consumir_fifo_lotes($db, $origemRid, null, $insumoId, $baseQtd);
    estoque_registrar_movimento($db, [
        'restaurante_id' => $origemRid,
        'filial_id' => null,
        'insumo_id' => $insumoId,
        'tipo' => 'TRANSFERENCIA',
        'origem' => 'MANUAL',
        'quantidade_base' => $baseQtd,
        'unidade_origem' => $unidade,
        'motivo' => $motivo !== '' ? $motivo : ('Transferencia para ' . $destino['nome'] . ' | ' . $ref),
        'responsavel_id' => (int)$ctx['usuario_id'],
        'meta_json' => [
            'ref' => $ref,
            'sentido' => 'SAIDA',
            'origem_restaurante_id' => $origemRid,
            'destino_restaurante_id' => $destinoRid,
            'origem_nome' => $origem['nome'],
            'destino_nome' => $destino['nome'],
        ],
    ]);

    estoque_upsert_saldo($db, $destinoRid, null, $insumoDestinoId, $baseQtd, null);
    $stmtLote = $db->prepare("
        INSERT INTO estoque_lotes
        (restaurante_id, filial_id, insumo_id, quantidade_base, saldo_base, custo_unitario_base, validade, referencia_compra)
        VALUES
        (:rid, NULL, :insumo_id, :qtd, :saldo, 0, NULL, :ref)
    ");
    $stmtLote->execute([
        ':rid' => $destinoRid,
        ':insumo_id' => $insumoDestinoId,
        ':qtd' => $baseQtd,
        ':saldo' => $baseQtd,
        ':ref' => $ref,
    ]);
    estoque_registrar_movimento($db, [
        'restaurante_id' => $destinoRid,
        'filial_id' => null,
        'insumo_id' => $insumoDestinoId,
        'tipo' => 'TRANSFERENCIA',
        'origem' => 'MANUAL',
        'quantidade_base' => $baseQtd,
        'unidade_origem' => $unidade,
        'motivo' => $motivo !== '' ? $motivo : ('Transferencia de ' . $origem['nome'] . ' | ' . $ref),
        'responsavel_id' => (int)$ctx['usuario_id'],
        'meta_json' => [
            'ref' => $ref,
            'sentido' => 'ENTRADA',
            'origem_restaurante_id' => $origemRid,
            'destino_restaurante_id' => $destinoRid,
            'origem_nome' => $origem['nome'],
            'destino_nome' => $destino['nome'],
            'insumo_origem_id' => $insumoId,
        ],
    ]);

    estoque_gerar_alertas_automaticos($db, $origemRid, null);
    estoque_gerar_alertas_automaticos($db, $destinoRid, null);

    $cardapioSync = null;
    if ($destino['tipo'] === 'filial' && $destinoRid !== $matrizId) {
        require_once APP_BASE . '/src/config/filiais_helper.php';
        try {
            $cardapioSync = filiais_sincronizar_cardapio_da_matriz($db, $matrizId, $destinoRid);
        } catch (Throwable $syncErr) {
            error_log('estoque_transferir cardapio sync: ' . $syncErr->getMessage());
        }
    }

    $db->commit();

    estoque_inteligente_log('estoque_transferencia', [
        'origem_restaurante_id' => $origemRid,
        'destino_restaurante_id' => $destinoRid,
        'insumo_origem_id' => $insumoId,
        'insumo_destino_id' => $insumoDestinoId,
        'ref' => $ref,
        'quantidade_base' => $baseQtd,
        'cardapio_sync' => $cardapioSync,
    ]);

    $msg = 'Transferencia concluida: ' . $origem['nome'] . ' → ' . $destino['nome'] . '.';
    if (is_array($cardapioSync) && ((int)($cardapioSync['produtos_criados'] ?? 0) > 0)) {
        $msg .= ' Cardapio sincronizado (' . (int)$cardapioSync['produtos_criados'] . ' produtos).';
    }

    security_json([
        'success' => true,
        'message' => $msg,
        'referencia' => $ref,
        'origem' => $origem,
        'destino' => $destino,
        'insumo_destino_id' => $insumoDestinoId,
        'cardapio_sync' => $cardapioSync,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $msg = $e->getMessage();
    if (str_starts_with($msg, 'ESTOQUE_INSUFICIENTE:')) {
        security_json(['success' => false, 'message' => trim(substr($msg, strlen('ESTOQUE_INSUFICIENTE:')))], 422);
    }
    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Erro ao transferir estoque.',
            'estoque_transferir_failed'
        ),
    ], 500);
}
