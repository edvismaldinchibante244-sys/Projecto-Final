<?php

require_once __DIR__ . '/plano_check.php';
require_once __DIR__ . '/planos.php'; // plano_get_limites()
require_once __DIR__ . '/plan_security.php';
require_once __DIR__ . '/migration_state.php';

if (!defined('PLANO_DOWNGRADE_SCHEMA_MIGRATION_ID')) {
    define('PLANO_DOWNGRADE_SCHEMA_MIGRATION_ID', '20260814_1310_plano_downgrades');
}

if (!function_exists('plano_downgrade_nivel')) {
    function plano_downgrade_nivel(string $plano): int
    {
        return ['BASICO' => 1, 'PROFISSIONAL' => 2, 'EMPRESARIAL' => 3][plano_normalizar_nome($plano)] ?? 0;
    }
}

if (!function_exists('plano_downgrade_schema_garantir')) {
    function plano_downgrade_schema_garantir(PDO $db): void
    {
        static $done = [];
        $key = spl_object_id($db);
        if (isset($done[$key])) return;
        if (migration_esta_aplicada($db, PLANO_DOWNGRADE_SCHEMA_MIGRATION_ID)) {
            $done[$key] = true;
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS plano_downgrades (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            plano_origem VARCHAR(30) NOT NULL,
            plano_destino VARCHAR(30) NOT NULL,
            aplicar_em DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'AGENDADO',
            limites_json LONGTEXT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            aplicado_em DATETIME NULL,
            cancelado_em DATETIME NULL,
            INDEX idx_downgrade_fila (status, aplicar_em),
            INDEX idx_downgrade_restaurante (restaurante_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done[$key] = true;
    }
}

if (!function_exists('plano_downgrade_tabela_existe')) {
    function plano_downgrade_tabela_existe(PDO $db, string $tabela): bool
    {
        if (!preg_match('/^[a-z_]+$/', $tabela)) return false;
        return (bool)$db->query('SHOW TABLES LIKE ' . $db->quote($tabela))->fetchColumn();
    }
}

if (!function_exists('plano_downgrade_contar')) {
    function plano_downgrade_contar(PDO $db, string $tabela, int $restauranteId): int
    {
        if (!plano_downgrade_tabela_existe($db, $tabela)) return 0;
        $cols = plan_security_table_columns($db, $tabela);
        if (!in_array('restaurante_id', $cols, true)) return 0;
        $where = 'restaurante_id = :rid';
        if (in_array('ativo', $cols, true)) $where .= ' AND COALESCE(ativo, 1) = 1';
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$tabela}` WHERE {$where}");
        $stmt->execute(['rid' => $restauranteId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('plano_downgrade_avaliar_limites')) {
    function plano_downgrade_avaliar_limites(PDO $db, int $restauranteId, string $planoDestino): array
    {
        $limites = plano_get_limites($planoDestino);
        $uso = [
            'produtos' => plano_downgrade_contar($db, 'produtos', $restauranteId),
            'usuarios' => plano_downgrade_contar($db, 'usuarios', $restauranteId),
            'mesas' => plano_downgrade_contar($db, 'mesas', $restauranteId),
            'categorias' => plano_downgrade_contar($db, 'categorias', $restauranteId),
            'subcategorias' => plano_downgrade_contar($db, 'subcategorias', $restauranteId),
            'filiais' => plano_downgrade_contar($db, 'restaurantes', $restauranteId),
        ];
        if (plano_downgrade_tabela_existe($db, 'restaurantes') && in_array('filial_id', plan_security_table_columns($db, 'restaurantes'), true)) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM restaurantes WHERE filial_id = :rid');
            $stmt->execute(['rid' => $restauranteId]);
            $uso['filiais'] = (int)$stmt->fetchColumn();
        }

        $labels = ['produtos' => 'produtos', 'usuarios' => 'utilizadores', 'mesas' => 'mesas', 'categorias' => 'categorias', 'subcategorias' => 'subcategorias', 'filiais' => 'filiais'];
        $excessos = [];
        foreach ($labels as $chave => $label) {
            $limite = (int)($limites[$chave] ?? -1);
            if ($limite >= 0 && (int)$uso[$chave] > $limite) {
                $excessos[] = ['recurso' => $chave, 'label' => $label, 'uso' => (int)$uso[$chave], 'limite' => $limite];
            }
        }
        return ['uso' => $uso, 'limites' => $limites, 'excessos' => $excessos, 'compativel' => !$excessos];
    }
}

if (!function_exists('plano_downgrade_data_aplicacao')) {
    function plano_downgrade_data_aplicacao(PDO $db, int $restauranteId): ?string
    {
        $dados = plano_get_dados($restauranteId);
        $fim = trim((string)($dados['data_fim'] ?? ''));
        if ($fim === '') return null;
        try {
            return (new DateTimeImmutable($fim))->modify('+1 day')->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('plano_downgrade_processar_pendentes')) {
    function plano_downgrade_processar_pendentes(PDO $db, ?int $restauranteId = null): array
    {
        plano_downgrade_schema_garantir($db);
        $sql = "SELECT * FROM plano_downgrades WHERE status IN ('AGENDADO','BLOQUEADO_LIMITE') AND aplicar_em <= CURDATE()";
        $params = [];
        if ($restauranteId !== null) {
            $sql .= ' AND restaurante_id = :rid';
            $params['rid'] = $restauranteId;
        }
        $sql .= ' ORDER BY aplicar_em ASC, id ASC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $resultado = ['aplicados' => 0, 'bloqueados' => 0, 'cancelados' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pedido) {
            $rid = (int)$pedido['restaurante_id'];
            $atual = plano_normalizar_nome(plano_get_dados($rid)['plano_nome'] ?? 'BASICO');
            $destino = plano_normalizar_nome($pedido['plano_destino'] ?? '');
            if (plano_downgrade_nivel($atual) <= plano_downgrade_nivel($destino)) {
                $db->prepare("UPDATE plano_downgrades SET status = 'CANCELADO', cancelado_em = NOW() WHERE id = ?")->execute([(int)$pedido['id']]);
                $resultado['cancelados']++;
                continue;
            }
            $avaliacao = plano_downgrade_avaliar_limites($db, $rid, $destino);
            if (!$avaliacao['compativel']) {
                $db->prepare("UPDATE plano_downgrades SET status = 'BLOQUEADO_LIMITE', limites_json = ? WHERE id = ?")
                    ->execute([json_encode($avaliacao, JSON_UNESCAPED_UNICODE), (int)$pedido['id']]);
                $resultado['bloqueados']++;
                continue;
            }
            if (plano_sincronizar_restaurante_plano($rid, $destino, date('Y-m-d'), 'DOWNGRADE', 'Downgrade agendado aplicado.', $db)) {
                $db->prepare("UPDATE plano_downgrades SET status = 'APLICADO', aplicado_em = NOW(), limites_json = ? WHERE id = ?")
                    ->execute([json_encode($avaliacao, JSON_UNESCAPED_UNICODE), (int)$pedido['id']]);
                $resultado['aplicados']++;
            }
        }
        return $resultado;
    }
}
