<?php
/**
 * Segundo canal de balcão (ex.: "Take-away"), disponível nos planos cujo
 * limite 'balcoes' é 2 ou mais (hoje só o Empresarial).
 *
 * Modelo: o segundo balcão é uma mesa normal com número de balcão (B2),
 * tal como o B1 canónico. O estado vive na tabela config:
 *   - balcao2_ativo: '1' quando o canal está ligado
 *   - balcao2_nome:  nome mostrado nas páginas (ex.: "Take-away")
 * Desligar o canal não apaga nada: a mesa B2 fica, os pedidos antigos
 * continuam no histórico, e voltar a ligar reaproveita a mesma mesa.
 */

if (!function_exists('balcao2_max_permitido')) {
    /**
     * Quantos balcões o plano do restaurante permite (1 por omissão).
     */
    function balcao2_max_permitido($restauranteCapabilityId): int
    {
        try {
            $planos = require __DIR__ . '/planos.php';
            $dados = function_exists('plano_get_dados') ? plano_get_dados($restauranteCapabilityId) : [];
            $nome = function_exists('plano_normalizar_nome')
                ? plano_normalizar_nome($dados['plano_nome'] ?? 'BASICO')
                : 'BASICO';
            $limite = $planos[$nome]['limites']['balcoes'] ?? 1;
            return max(1, (int)$limite);
        } catch (Throwable $e) {
            return 1;
        }
    }
}

if (!function_exists('balcao2_config')) {
    /**
     * Estado do segundo balcão deste restaurante.
     *
     * @return array{ativo: bool, nome: string, mesa_id: int, numero: string}
     */
    function balcao2_config(PDO $db, int $restauranteId, $capabilityId = null): array
    {
        $saida = ['ativo' => false, 'nome' => '', 'mesa_id' => 0, 'numero' => ''];
        if ($restauranteId <= 0) {
            return $saida;
        }
        // O plano manda: se o restaurante deixar de ter direito ao segundo canal
        // (por exemplo ao descer de Empresarial), o canal deixa de aparecer,
        // sem se apagar nada. Volta a aparecer se o plano voltar a permitir.
        $alvoPlano = ($capabilityId !== null && (int)$capabilityId > 0) ? (int)$capabilityId : $restauranteId;
        if (balcao2_max_permitido($alvoPlano) < 2) {
            return $saida;
        }
        try {
            $st = $db->prepare("SELECT chave, valor FROM config
                WHERE restaurante_id = :rid AND chave IN ('balcao2_ativo', 'balcao2_nome')");
            $st->execute([':rid' => $restauranteId]);
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [] as $chave => $valor) {
                if ($chave === 'balcao2_ativo') {
                    $saida['ativo'] = ((string)$valor === '1');
                } elseif ($chave === 'balcao2_nome') {
                    $saida['nome'] = trim((string)$valor);
                }
            }
        } catch (Throwable $e) {
            return $saida;
        }
        if ($saida['nome'] === '') {
            $saida['nome'] = 'Take-away';
        }
        if (!$saida['ativo']) {
            return $saida;
        }

        $mesaB2 = balcao2_obter_mesa($db, $restauranteId);
        if ($mesaB2 === null) {
            // Flag ligada mas sem mesa: trata como desligado em vez de rebentar.
            $saida['ativo'] = false;
            return $saida;
        }
        $saida['mesa_id'] = (int)$mesaB2['id'];
        $saida['numero'] = (string)$mesaB2['numero'];
        return $saida;
    }
}

if (!function_exists('balcao2_obter_mesa')) {
    /**
     * A mesa do segundo balcão: o primeiro balcão depois do canónico (por id).
     */
    function balcao2_obter_mesa(PDO $db, int $restauranteId): ?array
    {
        $st = $db->prepare("SELECT id, restaurante_id, numero, capacidade, status
            FROM mesas WHERE restaurante_id = :rid ORDER BY id ASC");
        $st->execute([':rid' => $restauranteId]);
        $vistos = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mesa) {
            if (!mesa_eh_balcao((string)($mesa['numero'] ?? ''))) {
                continue;
            }
            $vistos++;
            if ($vistos === 2) {
                return $mesa;
            }
        }
        return null;
    }
}

if (!function_exists('balcao2_guardar_config')) {
    function balcao2_guardar_config(PDO $db, int $restauranteId, string $chave, string $valor): void
    {
        $st = $db->prepare("INSERT INTO config (restaurante_id, chave, valor, updated_at)
            VALUES (:rid, :chave, :valor, NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_at = NOW()");
        $st->execute([':rid' => $restauranteId, ':chave' => $chave, ':valor' => $valor]);
    }
}

if (!function_exists('balcao2_ativar')) {
    /**
     * Liga o segundo balcão (cria a mesa B2 na primeira vez) e guarda o nome.
     */
    function balcao2_ativar(PDO $db, int $restauranteId, string $nome): array
    {
        $nome = trim($nome);
        if ($nome === '') {
            $nome = 'Take-away';
        }
        if (function_exists('mb_substr')) {
            $nome = mb_substr($nome, 0, 40, 'UTF-8');
        } else {
            $nome = substr($nome, 0, 40);
        }

        // Garante o B1 primeiro, para o B2 ser sempre o segundo balcão.
        mesa_garantir_balcao_canonico($db, $restauranteId);

        $mesaB2 = balcao2_obter_mesa($db, $restauranteId);
        if ($mesaB2 === null) {
            $st = $db->prepare("INSERT INTO mesas (restaurante_id, numero, capacidade, status)
                VALUES (:rid, 'B2', 1, 'LIVRE')");
            $st->execute([':rid' => $restauranteId]);
            $mesaB2 = balcao2_obter_mesa($db, $restauranteId);
        }

        balcao2_guardar_config($db, $restauranteId, 'balcao2_ativo', '1');
        balcao2_guardar_config($db, $restauranteId, 'balcao2_nome', $nome);

        return [
            'success' => true,
            'mesa_id' => (int)($mesaB2['id'] ?? 0),
            'nome' => $nome,
        ];
    }
}

if (!function_exists('balcao2_desativar')) {
    /**
     * Desliga o canal. Recusa quando o B2 tem pedidos por terminar, para não
     * esconder trabalho a meio. Nada é apagado.
     */
    function balcao2_desativar(PDO $db, int $restauranteId): array
    {
        $mesaB2 = balcao2_obter_mesa($db, $restauranteId);
        if ($mesaB2 !== null) {
            try {
                $st = $db->prepare("SELECT COUNT(*) FROM pedidos
                    WHERE restaurante_id = :rid AND mesa_id = :mid
                      AND UPPER(status) NOT IN ('PAGO', 'CANCELADO', 'FECHADO', 'FINALIZADO')");
                $st->execute([':rid' => $restauranteId, ':mid' => (int)$mesaB2['id']]);
                $abertos = (int)$st->fetchColumn();
                if ($abertos > 0) {
                    return [
                        'success' => false,
                        'message' => 'O canal ainda tem ' . $abertos . ' pedido(s) por terminar. Feche-os antes de o desligar.',
                    ];
                }
            } catch (Throwable $e) {
                // Em caso de dúvida, não bloqueia o desligar.
                error_log('[balcao2] verificação de pedidos: ' . $e->getMessage());
            }
        }

        balcao2_guardar_config($db, $restauranteId, 'balcao2_ativo', '0');
        return ['success' => true];
    }
}
