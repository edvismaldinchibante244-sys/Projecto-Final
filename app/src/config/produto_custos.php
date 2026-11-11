<?php
declare(strict_types=1);

/*
 * Custo de cada produto pela ficha técnica: soma(quantidade do insumo / rendimento × custo médio).
 * Devolve [produto_id => custo] só para produtos em que TODOS os insumos têm custo registado.
 */
if (!function_exists('produto_custos_ficha')) {
    function produto_custos_ficha(PDO $db, int $restauranteId, ?int $filialId = null): array
    {
        $out = [];
        try {
            $st = $db->prepare("
                SELECT ft.produto_id,
                       SUM((fi.quantidade_base / GREATEST(ft.rendimento_por_produto, 0.001)) * COALESCE(s.custo_medio, 0)) AS custo,
                       SUM(CASE WHEN COALESCE(s.custo_medio, 0) <= 0 THEN 1 ELSE 0 END) AS sem_custo
                FROM estoque_fichas_tecnicas ft
                JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
                LEFT JOIN estoque_saldos s ON s.insumo_id = fi.insumo_id AND s.restaurante_id = ft.restaurante_id
                     AND ((? IS NULL AND s.filial_id IS NULL) OR s.filial_id = ?)
                WHERE ft.restaurante_id = ? AND ft.ativo = 1
                  AND ((? IS NULL AND ft.filial_id IS NULL) OR ft.filial_id = ?)
                GROUP BY ft.produto_id
            ");
            $st->execute([$filialId, $filialId, $restauranteId, $filialId, $filialId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $c = (float)$r['custo'];
                if ((int)$r['sem_custo'] === 0 && $c > 0) {
                    $out[(int)$r['produto_id']] = round($c, 2);
                }
            }
        } catch (Throwable $e) {
            // sem estoque inteligente
        }
        return $out;
    }
}
