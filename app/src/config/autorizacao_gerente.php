<?php
/**
 * Autorizacao do gerente para acoes sensiveis (cancelar pedido, cancelar pagamento,
 * remover item ja enviado, fechar caixa com diferenca).
 *
 * Quem faz a acao com perfil ADMIN ou GERENTE nao precisa de autorizacao.
 * Garcom/caixa: o servidor responde HTTP 428 {requer_autorizacao:true}; o browser
 * (js/autorizacao_gerente.js) pede ao gerente o PIN (ou ao administrador a palavra-passe),
 * obtem um codigo de uso unico ligado a ESTA acao e a ESTE alvo, valido 2 minutos,
 * e repete o pedido com o cabecalho X-Autorizacao-Gerente.
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/terminal_pin.php';

if (!defined('AUTORIZACAO_GERENTE_VALIDADE')) {
    define('AUTORIZACAO_GERENTE_VALIDADE', 120);
    define('AUTORIZACAO_GERENTE_ACOES', [
        'cancelar_pedido' => 'Cancelar pedido',
        'cancelar_pagamento' => 'Cancelar pagamento',
        'remover_item' => 'Remover item já enviado',
        'fechar_caixa_diferenca' => 'Fechar caixa com diferença',
        'sangria_caixa' => 'Retirar dinheiro da gaveta (sangria)',
    ]);
}

if (!function_exists('autorizacao_gerente_dispensada')) {
    function autorizacao_gerente_dispensada(?string $perfil = null): bool
    {
        if (!empty($_SESSION['super_admin'])) {
            return true;
        }
        $p = terminal_normalizar_perfil($perfil ?? (string)($_SESSION['perfil'] ?? ''));
        return in_array($p, ['ADMIN', 'GERENTE'], true);
    }

    /** Quem pode autorizar: gerentes com PIN e administradores do restaurante. */
    function autorizacao_gerente_autorizadores(PDO $db, int $restauranteId): array
    {
        $stmt = $db->prepare("
            SELECT id, nome, perfil, pin_hash IS NOT NULL AS tem_pin
            FROM usuarios
            WHERE restaurante_id = ? AND COALESCE(ativo, 1) = 1 AND COALESCE(super_admin, 0) = 0
              AND (UPPER(perfil) = 'ADMIN' OR (UPPER(perfil) = 'GERENTE' AND pin_hash IS NOT NULL))
            ORDER BY FIELD(UPPER(perfil), 'GERENTE', 'ADMIN'), nome
        ");
        $stmt->execute([$restauranteId]);
        $lista = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $nome = function_exists('personal_data_decrypt_result') ? personal_data_decrypt_result($u['nome'], 'nome') : $u['nome'];
            $perfil = strtoupper((string)$u['perfil']);
            $lista[] = [
                'id' => (int)$u['id'],
                'nome' => (string)$nome,
                'perfil' => $perfil,
                'credencial' => $perfil === 'ADMIN' ? 'senha' : 'pin',
            ];
        }
        return $lista;
    }

    /**
     * Verifica a credencial do gerente/administrador e emite um codigo de uso unico.
     * @return array{ok:bool, mensagem?:string, codigo?:string, autorizador?:array}
     */
    function autorizacao_gerente_emitir(PDO $db, int $restauranteId, string $acao, int $alvo, int $autorizadorId, string $credencial): array
    {
        if (!isset(AUTORIZACAO_GERENTE_ACOES[$acao])) {
            return ['ok' => false, 'mensagem' => 'Ação desconhecida.'];
        }
        if (!security_rate_limit('autorizacao_gerente', 20, 300)) {
            return ['ok' => false, 'mensagem' => 'Demasiadas tentativas. Aguarde uns minutos.'];
        }
        $stmt = $db->prepare('SELECT id, nome, perfil, senha FROM usuarios WHERE id = ? AND restaurante_id = ? AND COALESCE(ativo, 1) = 1 AND COALESCE(super_admin, 0) = 0');
        $stmt->execute([$autorizadorId, $restauranteId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        $perfil = strtoupper((string)($a['perfil'] ?? ''));
        $usuarioAtual = (int)($_SESSION['usuario_id'] ?? 0);

        if ($a && $perfil === 'ADMIN') {
            if (!password_verify($credencial, (string)$a['senha'])) {
                security_log_event('autorizacao_gerente_falha', ['autorizador_id' => $autorizadorId, 'acao' => $acao]);
                return ['ok' => false, 'mensagem' => 'Palavra-passe incorreta.'];
            }
        } elseif ($a && $perfil === 'GERENTE') {
            $r = pin_verificar($db, $restauranteId, $autorizadorId, $credencial,
                static fn(array $u): bool => strtoupper((string)($u['perfil'] ?? '')) === 'GERENTE',
                ['acao' => $acao, 'pedido_por' => $usuarioAtual]);
            if (!$r['ok']) {
                return $r;
            }
        } else {
            password_verify($credencial, '$2y$10$usesomesillystringforsalt$');
            return ['ok' => false, 'mensagem' => 'Escolha um gerente ou administrador.'];
        }

        $codigo = bin2hex(random_bytes(16));
        $_SESSION['autorizacoes_gerente'] = array_filter(
            (array)($_SESSION['autorizacoes_gerente'] ?? []),
            static fn($x) => is_array($x) && (int)($x['exp'] ?? 0) > time()
        );
        $_SESSION['autorizacoes_gerente'][hash('sha256', $codigo)] = [
            'acao' => $acao,
            'alvo' => $alvo,
            'autorizador_id' => (int)$a['id'],
            'autorizador_nome' => function_exists('personal_data_decrypt_result') ? (string)personal_data_decrypt_result($a['nome'], 'nome') : (string)$a['nome'],
            'exp' => time() + AUTORIZACAO_GERENTE_VALIDADE,
        ];
        return ['ok' => true, 'codigo' => $codigo];
    }

    /** Le e gasta o codigo (uso unico). Devolve os dados da autorizacao ou null. */
    function autorizacao_gerente_consumir(string $acao, int $alvo): ?array
    {
        $codigo = (string)($_SERVER['HTTP_X_AUTORIZACAO_GERENTE'] ?? ($_POST['autorizacao_gerente'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/', $codigo)) {
            return null;
        }
        $chave = hash('sha256', $codigo);
        $aut = $_SESSION['autorizacoes_gerente'][$chave] ?? null;
        if (!is_array($aut)) {
            // Aprovado a distancia pelo gerente/administrador (tabela autorizacoes_remotas).
            return autorizacao_remota_consumir($chave, $acao, $alvo);
        }
        unset($_SESSION['autorizacoes_gerente'][$chave]);
        if ((int)$aut['exp'] < time() || $aut['acao'] !== $acao || (int)$aut['alvo'] !== $alvo) {
            return null;
        }
        return $aut;
    }

    /**
     * Garante a autorizacao antes de uma acao sensivel. Termina o pedido com HTTP 428 se faltar.
     * Devolve null (perfil dispensado) ou os dados da autorizacao usada, ja registada na auditoria.
     */
    function autorizacao_gerente_exigir(string $acao, int $alvo, string $detalhe = ''): ?array
    {
        if (autorizacao_gerente_dispensada()) {
            return null;
        }
        $aut = autorizacao_gerente_consumir($acao, $alvo);
        if ($aut === null) {
            security_json([
                'success' => false,
                'requer_autorizacao' => true,
                'acao' => $acao,
                'alvo' => $alvo,
                'titulo' => AUTORIZACAO_GERENTE_ACOES[$acao] ?? $acao,
                'detalhe' => $detalhe,
                'message' => 'Esta ação precisa da autorização de um gerente.',
            ], 428);
        }
        $ctx = [
            'acao' => $acao,
            'alvo' => $alvo,
            'detalhe' => $detalhe,
            'autorizado_por' => (int)$aut['autorizador_id'],
            'pedido_por' => (int)($_SESSION['usuario_id'] ?? 0),
            'restaurante_id' => (int)($_SESSION['restaurante_id'] ?? 0),
        ];
        security_log_event('autorizacao_gerente_usada', $ctx);
        if (function_exists('audit_log')) {
            @audit_log(null, 'autorizacao_gerente', $ctx);
        }
        return $aut;
    }
}

if (!function_exists('autorizacao_remota_consumir')) {
    define('AUTORIZACAO_REMOTA_MINUTOS', 10);

    function autorizacao_remota_db(): ?PDO
    {
        try {
            if (!class_exists('Database')) {
                require_once __DIR__ . '/database.php';
            }
            $db = Database::infra();
            $db->query('SELECT 1 FROM autorizacoes_remotas LIMIT 1');
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Gasta (uma vez) um codigo aprovado a distancia. So quem pediu, para esta acao e este alvo. */
    function autorizacao_remota_consumir(string $codigoHash, string $acao, int $alvo): ?array
    {
        $db = autorizacao_remota_db();
        if (!$db) {
            return null;
        }
        $st = $db->prepare("SELECT a.id, a.decidido_por, u.nome FROM autorizacoes_remotas a LEFT JOIN usuarios u ON u.id = a.decidido_por
            WHERE a.codigo_hash = ? AND a.estado = 'APROVADA' AND a.usado_em IS NULL AND a.acao = ? AND a.alvo = ?
              AND a.restaurante_id = ? AND a.solicitado_por = ? AND a.decidido_em > NOW() - INTERVAL 5 MINUTE LIMIT 1");
        $st->execute([$codigoHash, $acao, $alvo, (int)($_SESSION['restaurante_id'] ?? 0), (int)($_SESSION['usuario_id'] ?? 0)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $up = $db->prepare("UPDATE autorizacoes_remotas SET usado_em = NOW(), estado = 'USADA' WHERE id = ? AND usado_em IS NULL");
        $up->execute([(int)$r['id']]);
        if ($up->rowCount() !== 1) {
            return null;
        }
        return [
            'acao' => $acao,
            'alvo' => $alvo,
            'autorizador_id' => (int)$r['decidido_por'],
            'autorizador_nome' => function_exists('personal_data_decrypt_result') ? (string)personal_data_decrypt_result((string)$r['nome'], 'nome') : (string)$r['nome'],
            'remota' => true,
            'exp' => time() + 60,
        ];
    }

    /** Descricao legivel do alvo (ex.: "Pedido #0002 · Mesa 2"), calculada no servidor. */
    function autorizacao_remota_descricao(PDO $db, int $rid, string $acao, int $alvo): string
    {
        try {
            if (in_array($acao, ['cancelar_pedido', 'cancelar_pagamento'], true)) {
                $st = $db->prepare('SELECT p.numero_pedido, m.numero AS mesa FROM pedidos p LEFT JOIN mesas m ON m.id = p.mesa_id WHERE p.id = ? AND p.restaurante_id = ?');
                $st->execute([$alvo, $rid]);
                if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
                    return 'Pedido #' . ($p['numero_pedido'] ?: $alvo) . ($p['mesa'] !== null && $p['mesa'] !== '' ? ' · Mesa ' . $p['mesa'] : '');
                }
            }
            if ($acao === 'remover_item') {
                $st = $db->prepare('SELECT p.numero_pedido, pr.nome FROM itens_pedido i JOIN pedidos p ON p.id = i.pedido_id LEFT JOIN produtos pr ON pr.id = i.produto_id WHERE i.id = ? AND p.restaurante_id = ?');
                $st->execute([$alvo, $rid]);
                if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
                    return ($p['nome'] ?: 'Item') . ' · Pedido #' . $p['numero_pedido'];
                }
            }
        } catch (Throwable $e) {
        }
        return '#' . $alvo;
    }

    /** Pede a distancia. Devolve [id, codigo] (o codigo so vai para quem pediu). */
    function autorizacao_remota_pedir(PDO $db, int $rid, int $uid, string $acao, int $alvo): array
    {
        if (!isset(AUTORIZACAO_GERENTE_ACOES[$acao]) || $alvo <= 0) {
            return ['ok' => false, 'mensagem' => 'Ação desconhecida.'];
        }
        // Um pedido de cada vez para a mesma acao/alvo: os anteriores deixam de valer.
        $db->prepare("UPDATE autorizacoes_remotas SET estado = 'CANCELADA' WHERE restaurante_id = ? AND solicitado_por = ? AND acao = ? AND alvo = ? AND estado = 'PENDENTE'")
            ->execute([$rid, $uid, $acao, $alvo]);
        $codigo = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO autorizacoes_remotas (restaurante_id, acao, alvo, descricao, codigo_hash, solicitado_por, expira_em) VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL ' . AUTORIZACAO_REMOTA_MINUTOS . ' MINUTE)')
            ->execute([$rid, $acao, $alvo, autorizacao_remota_descricao($db, $rid, $acao, $alvo), hash('sha256', $codigo), $uid]);
        $id = (int)$db->lastInsertId();
        security_log_event('autorizacao_remota_pedida', ['id' => $id, 'acao' => $acao, 'alvo' => $alvo]);
        if (function_exists('realtime_avisar_mudanca')) {
            @realtime_avisar_mudanca($rid);
        }
        return ['ok' => true, 'id' => $id, 'codigo' => $codigo];
    }
}
