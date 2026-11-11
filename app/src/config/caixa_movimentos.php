<?php
declare(strict_types=1);

/*
 * Sangria / reforco de caixa. Funciona mesmo antes da migracao 20261108_1900 (devolve zeros).
 */
if (!function_exists('caixa_mov_tabela_existe')) {
    function caixa_mov_tabela_existe(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $cache = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caixa_movimentos'")->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }

    /** @return array{sangria: float, reforco: float, lista: array<int, array<string, mixed>>} */
    function caixa_mov_resumo(PDO $db, int $caixaId, int $restauranteId): array
    {
        $out = ['sangria' => 0.0, 'reforco' => 0.0, 'lista' => []];
        if ($caixaId <= 0 || !caixa_mov_tabela_existe($db)) {
            return $out;
        }
        try {
            $st = $db->prepare("SELECT m.id, m.tipo, m.valor, m.motivo, m.criado_em, u.nome AS usuario_nome, a.nome AS autorizado_nome
                FROM caixa_movimentos m
                LEFT JOIN usuarios u ON u.id = m.usuario_id
                LEFT JOIN usuarios a ON a.id = m.autorizado_por
                WHERE m.caixa_id = ? AND m.restaurante_id = ?
                ORDER BY m.criado_em DESC, m.id DESC");
            $st->execute([$caixaId, $restauranteId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $v = (float)$r['valor'];
                if (strtoupper((string)$r['tipo']) === 'SANGRIA') {
                    $out['sangria'] += $v;
                } else {
                    $out['reforco'] += $v;
                }
                $out['lista'][] = $r;
            }
        } catch (Throwable $e) {
            // sem movimentos
        }
        $out['sangria'] = round($out['sangria'], 2);
        $out['reforco'] = round($out['reforco'], 2);
        return $out;
    }

    function caixa_fecho_cego_ativo(PDO $db, int $restauranteId): bool
    {
        try {
            $st = $db->prepare("SELECT caixa_fecho_cego FROM restaurantes WHERE id = ?");
            $st->execute([$restauranteId]);
            return (int)$st->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false; // coluna ainda nao existe
        }
    }

    function caixa_forma_label(string $forma): string
    {
        $f = strtoupper(trim($forma));
        $map = ['DINHEIRO' => 'Dinheiro', 'MPESA' => 'M-Pesa', 'EMOLA' => 'e-Mola', 'CARTAO' => 'Cartão',
            'TRANSFERENCIA' => 'Transferência', 'OUTRO' => 'Outro'];
        return $map[$f] ?? ucfirst(strtolower($f !== '' ? $f : 'Outro'));
    }

    /**
     * Percentagens inteiras que somam exatamente 100 (metodo do maior resto).
     * @param float[] $valores
     * @return int[]
     */
    function caixa_percentagens(array $valores): array
    {
        $total = array_sum($valores);
        if ($total <= 0) {
            return array_map(static fn() => 0, $valores);
        }
        $base = [];
        $restos = [];
        foreach ($valores as $k => $v) {
            $exato = $v / $total * 100;
            $base[$k] = (int)floor($exato);
            $restos[$k] = $exato - $base[$k];
        }
        $falta = 100 - array_sum($base);
        arsort($restos);
        foreach (array_keys($restos) as $k) {
            if ($falta <= 0) {
                break;
            }
            $base[$k]++;
            $falta--;
        }
        return $base;
    }
}
