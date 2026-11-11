<?php
/**
 * Resumo diario de vendas (tabela vendas_resumo_diario).
 *
 * Os dias ja fechados (antes de hoje) ficam somados uma vez por dia pela rotina de manutencao
 * (src/public/cron/manutencao.php). O painel e os relatorios leem esses totais em vez de somarem
 * meses de vendas a cada visita. Hoje e sempre calculado na hora.
 * Se a rotina ainda nao correu para algum dia do periodo, esse periodo e calculado diretamente
 * nas vendas (o resultado e sempre o mesmo; so muda a rapidez).
 */

if (!function_exists('vendas_resumo_disponivel')) {
    function vendas_resumo_disponivel(PDO $db): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $db->query('SELECT 1 FROM vendas_resumo_diario LIMIT 1');
                $db->query('SELECT 1 FROM vendas_resumo_estado LIMIT 1');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Recalcula um dia (todos os restaurantes) e marca-o como calculado. */
    function vendas_resumo_calcular_dia(PDO $db, string $dia): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
            throw new InvalidArgumentException('Dia invalido.');
        }
        $db->prepare('DELETE FROM vendas_resumo_diario WHERE dia = ?')->execute([$dia]);
        $st = $db->prepare("INSERT INTO vendas_resumo_diario (restaurante_id, dia, vendas, canceladas, total, descontos)
            SELECT restaurante_id, ?,
                   SUM(UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'),
                   SUM(UPPER(TRIM(COALESCE(status, ''))) = 'CANCELADO'),
                   COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(status, ''))) = 'PAGO' THEN COALESCE(total_final, 0) ELSE 0 END), 0),
                   COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(status, ''))) = 'PAGO' THEN COALESCE(desconto, 0) ELSE 0 END), 0)
            FROM vendas
            WHERE criado_em >= ? AND criado_em < ? + INTERVAL 1 DAY
            GROUP BY restaurante_id");
        $st->execute([$dia, $dia, $dia]);
        $n = $st->rowCount();
        $db->prepare('INSERT INTO vendas_resumo_estado (dia) VALUES (?) ON DUPLICATE KEY UPDATE calculado_em = CURRENT_TIMESTAMP')->execute([$dia]);
        return $n;
    }

    /**
     * Totais de vendas PAGAS de um restaurante entre $inicio (inclusive) e $fim (exclusivo), datas Y-m-d.
     * @return array{total: float, vendas: int, por_dia: array<string, float>}
     */
    function vendas_resumo_periodo(PDO $db, int $rid, string $inicio, string $fim): array
    {
        $hoje = date('Y-m-d', strtotime((string)$db->query('SELECT CURDATE()')->fetchColumn()));
        $res = ['total' => 0.0, 'vendas' => 0, 'por_dia' => []];
        $somar = static function (array $linhas) use (&$res): void {
            foreach ($linhas as $l) {
                $dia = date('Y-m-d', strtotime((string)$l['dia']));
                $res['por_dia'][$dia] = ($res['por_dia'][$dia] ?? 0) + (float)$l['total'];
                $res['total'] += (float)$l['total'];
                $res['vendas'] += (int)$l['vendas'];
            }
        };
        $diretamente = static function (string $de, string $ate) use ($db, $rid): array {
            $st = $db->prepare("SELECT DATE(criado_em) AS dia, COUNT(*) AS vendas, COALESCE(SUM(COALESCE(total_final, 0)), 0) AS total
                FROM vendas
                WHERE restaurante_id = ? AND criado_em >= ? AND criado_em < ?
                  AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                GROUP BY DATE(criado_em)");
            $st->execute([$rid, $de, $ate]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };

        $fimPassado = min($fim, $hoje);
        if ($inicio < $fimPassado) {
            $dias = (int)round((strtotime($fimPassado) - strtotime($inicio)) / 86400);
            $completo = false;
            if (vendas_resumo_disponivel($db)) {
                $st = $db->prepare('SELECT COUNT(*) FROM vendas_resumo_estado WHERE dia >= ? AND dia < ?');
                $st->execute([$inicio, $fimPassado]);
                $completo = (int)$st->fetchColumn() >= $dias;
            }
            if ($completo) {
                $st = $db->prepare('SELECT dia, vendas, total FROM vendas_resumo_diario WHERE restaurante_id = ? AND dia >= ? AND dia < ?');
                $st->execute([$rid, $inicio, $fimPassado]);
                $somar($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
            } else {
                $somar($diretamente($inicio, $fimPassado));
            }
        }
        if ($fim > $hoje && $inicio <= $hoje) {
            $somar($diretamente(max($inicio, $hoje), $fim));
        }
        return $res;
    }
}
