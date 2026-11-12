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
    'rate_key' => 'estoque_ficha_tecnica_salvar',
    'rate_max' => 60,
    'rate_window' => 60,
    'skip_turno' => true,
]);

$rid = (int)$ctx['restaurante_id'];
$planoRid = function_exists('session_restaurante_capability_id') ? (int)session_restaurante_capability_id() : $rid;
$planoRid = $planoRid > 0 ? $planoRid : $rid;
if (!plano_tem_funcionalidade_db($planoRid, 'estoque_avancado')) {
    security_json(['success' => false, 'message' => 'Funcionalidade disponivel nos planos Profissional e Empresarial'], 403);
}

$produtoId = (int)($_POST['produto_id'] ?? 0);
$rendimento = (float)str_replace(',', '.', (string)($_POST['rendimento'] ?? 1));
$filialId = (int)($_POST['filial_id'] ?? 0);
$filialId = $filialId > 0 ? $filialId : null;
$itensJson = (string)($_POST['itens'] ?? '[]');
$itens = json_decode($itensJson, true);
$itens = is_array($itens) ? $itens : [];

if ($produtoId <= 0 || empty($itens)) {
    security_json(['success' => false, 'message' => 'Produto e itens sao obrigatorios'], 422);
}

$db = (new Database())->getConnection();
estoque_inteligente_schema_garantir($db);

// Valida produto do restaurante antes da transação.
$stmtProd = $db->prepare("
    SELECT id FROM produtos
    WHERE id = :id AND restaurante_id = :rid
    LIMIT 1
");
$stmtProd->execute([':id' => $produtoId, ':rid' => $rid]);
if (!(int)$stmtProd->fetchColumn()) {
    security_json(['success' => false, 'message' => 'Produto nao encontrado neste restaurante'], 404);
}

$itensValidos = [];
foreach ($itens as $item) {
    $insumoId = (int)($item['insumo_id'] ?? 0);
    $quantidade = (float)str_replace(',', '.', (string)($item['quantidade'] ?? 0));
    $unidade = (string)($item['unidade'] ?? 'un');
    if ($insumoId <= 0 || $quantidade <= 0) {
        continue;
    }
    $itensValidos[] = [
        'insumo_id' => $insumoId,
        'quantidade' => $quantidade,
        'unidade' => $unidade,
    ];
}

if (empty($itensValidos)) {
    security_json(['success' => false, 'message' => 'Informe ao menos um insumo valido'], 422);
}

$ids = array_values(array_unique(array_map(static function ($it) {
    return (int)$it['insumo_id'];
}, $itensValidos)));

if (count($ids) !== count($itensValidos)) {
    security_json(['success' => false, 'message' => 'Ficha tecnica nao pode repetir o mesmo insumo'], 422);
}

$in = implode(',', array_fill(0, count($ids), '?'));
if ($filialId === null) {
    // Matriz: aceita insumos sem filial (e legado com filial preenchida no mesmo restaurante).
    $sqlInsumos = "
        SELECT id, nome, unidade_base
        FROM estoque_insumos
        WHERE restaurante_id = ?
          AND ativo = 1
          AND id IN ($in)
    ";
    $paramsInsumos = array_merge([$rid], $ids);
} else {
    $sqlInsumos = "
        SELECT id, nome, unidade_base
        FROM estoque_insumos
        WHERE restaurante_id = ?
          AND filial_id = ?
          AND ativo = 1
          AND id IN ($in)
    ";
    $paramsInsumos = array_merge([$rid, $filialId], $ids);
}
$stmtInsumos = $db->prepare($sqlInsumos);
$stmtInsumos->execute($paramsInsumos);
$insumosRows = $stmtInsumos->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($insumosRows) !== count($ids)) {
    security_json(['success' => false, 'message' => 'Existe insumo invalido ou fora do restaurante'], 422);
}

$insumoMeta = [];
$nomeNormUsados = [];
foreach ($insumosRows as $row) {
    $insumoMeta[(int)$row['id']] = $row;
    $nomeNorm = estoque_normalizar_nome_chave((string)($row['nome'] ?? ''));
    if ($nomeNorm === '') {
        continue;
    }
    if (isset($nomeNormUsados[$nomeNorm])) {
        security_json(['success' => false, 'message' => 'Ficha tecnica nao pode conter insumos duplicados por nome'], 422);
    }
    $nomeNormUsados[$nomeNorm] = true;
}

$db->beginTransaction();

try {
    // UNIQUE com filial_id NULL não faz ON DUPLICATE KEY — select + update/insert.
    if ($filialId === null) {
        $stmtFicha = $db->prepare("
            SELECT id
            FROM estoque_fichas_tecnicas
            WHERE restaurante_id = :rid
              AND produto_id = :produto_id
              AND filial_id IS NULL
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtFicha->execute([
            ':rid' => $rid,
            ':produto_id' => $produtoId,
        ]);
    } else {
        $stmtFicha = $db->prepare("
            SELECT id
            FROM estoque_fichas_tecnicas
            WHERE restaurante_id = :rid
              AND produto_id = :produto_id
              AND filial_id = :filial_id
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmtFicha->execute([
            ':rid' => $rid,
            ':produto_id' => $produtoId,
            ':filial_id' => $filialId,
        ]);
    }
    $fichaId = (int)$stmtFicha->fetchColumn();
    $rendimentoSafe = max(0.001, $rendimento);

    if ($fichaId > 0) {
        $upd = $db->prepare("
            UPDATE estoque_fichas_tecnicas
            SET rendimento_por_produto = :rendimento, ativo = 1
            WHERE id = :id AND restaurante_id = :rid
        ");
        $upd->execute([
            ':rendimento' => $rendimentoSafe,
            ':id' => $fichaId,
            ':rid' => $rid,
        ]);
    } else {
        $ins = $db->prepare("
            INSERT INTO estoque_fichas_tecnicas (restaurante_id, filial_id, produto_id, rendimento_por_produto, ativo)
            VALUES (:rid, :filial_id, :produto_id, :rendimento, 1)
        ");
        $ins->bindValue(':rid', $rid, PDO::PARAM_INT);
        if ($filialId === null) {
            $ins->bindValue(':filial_id', null, PDO::PARAM_NULL);
        } else {
            $ins->bindValue(':filial_id', $filialId, PDO::PARAM_INT);
        }
        $ins->bindValue(':produto_id', $produtoId, PDO::PARAM_INT);
        $ins->bindValue(':rendimento', $rendimentoSafe);
        $ins->execute();
        $fichaId = (int)$db->lastInsertId();
    }

    if ($fichaId <= 0) {
        throw new RuntimeException('Ficha tecnica nao criada');
    }

    $db->prepare("DELETE FROM estoque_ficha_itens WHERE ficha_id = :ficha_id")->execute([':ficha_id' => $fichaId]);

    $stmtItem = $db->prepare("
        INSERT INTO estoque_ficha_itens (ficha_id, insumo_id, quantidade_base)
        VALUES (:ficha_id, :insumo_id, :quantidade_base)
    ");

    foreach ($itensValidos as $item) {
        $insumoId = (int)$item['insumo_id'];
        $quantidade = (float)$item['quantidade'];
        $meta = $insumoMeta[$insumoId] ?? null;
        $unidadeBase = (string)($meta['unidade_base'] ?? 'un');
        $unidade = estoque_resolver_unidade_movimento((string)($item['unidade'] ?? ''), $unidadeBase);
        $qtdBase = estoque_converter_para_base($quantidade, $unidade);
        if ($qtdBase <= 0) {
            throw new RuntimeException('Quantidade invalida para o insumo ' . ($meta['nome'] ?? $insumoId));
        }
        $stmtItem->execute([
            ':ficha_id' => $fichaId,
            ':insumo_id' => $insumoId,
            ':quantidade_base' => $qtdBase,
        ]);
    }

    $db->commit();
    estoque_inteligente_log('ficha_tecnica_salva', ['restaurante_id' => $rid, 'produto_id' => $produtoId, 'ficha_id' => $fichaId]);
    security_json(['success' => true, 'message' => 'Ficha tecnica salva com sucesso']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('estoque_ficha_tecnica_salvar: ' . $e->getMessage());

    try {
        $debugDir = nv_storage();
        if (!is_dir($debugDir)) {
            @mkdir($debugDir, 0755, true);
        }
        $logFile = $debugDir . '/est_ficha_errors.log';
        $entry = [
            'ts' => date('c'),
            'message' => $e->getMessage(),
            'usuario_id' => $ctx['usuario_id'] ?? null,
            'restaurante_id' => $rid,
            'produto_id' => $produtoId,
            'post' => [
                'produto_id' => $_POST['produto_id'] ?? null,
                'rendimento' => $_POST['rendimento'] ?? null,
                'itens' => $_POST['itens'] ?? null,
            ],
        ];
        @file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $ee) {
        // ignore
    }

    security_json([
        'success' => false,
        'message' => security_exception_message(
            $e,
            'Erro ao salvar ficha tecnica',
            'estoque_ficha_tecnica_salvar_failed'
        ),
    ], 500);
}
