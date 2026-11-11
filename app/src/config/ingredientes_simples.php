<?php
/**
 * Ingredientes - versao simples (plano Basico).
 *
 * Usa as MESMAS tabelas do Estoque Inteligente (estoque_insumos, estoque_saldos,
 * estoque_movimentos, estoque_fichas_tecnicas/itens). Assim, quando o restaurante sobe
 * para Profissional, os ingredientes e receitas ja estao la.
 *
 * O Basico nao usa lotes, validades nem custos: so nome, unidade, quantidade, minimo
 * e a receita de cada prato. A venda baixa os ingredientes automaticamente.
 */

require_once __DIR__ . '/estoque_inteligente.php';
require_once __DIR__ . '/plano_check.php';
require_once __DIR__ . '/planos.php'; // plano_get_limites()

if (!function_exists('plano_tem_ingredientes')) {
    /** Controlo por ingrediente: versao simples (Basico) ou Estoque Inteligente (Pro/Emp). */
    function plano_tem_ingredientes(int $restauranteId): bool
    {
        if ($restauranteId <= 0) {
            return false;
        }
        return plano_tem_funcionalidade_db($restauranteId, 'estoque_avancado')
            || plano_tem_funcionalidade_db($restauranteId, 'estoque_ingredientes');
    }
}

if (!function_exists('ing_unidades')) {
    /** Unidades que o utilizador escolhe => unidade base guardada. */
    function ing_unidades(): array
    {
        return [
            'kg' => ['base' => 'g', 'rotulo' => 'quilos (kg)'],
            'g' => ['base' => 'g', 'rotulo' => 'gramas (g)'],
            'L' => ['base' => 'ml', 'rotulo' => 'litros (L)'],
            'ml' => ['base' => 'ml', 'rotulo' => 'mililitros (ml)'],
            'un' => ['base' => 'un', 'rotulo' => 'unidades'],
        ];
    }

    /** Quantidade escrita pelo utilizador (ex.: "1,5" kg) => unidade base (1500 g). */
    function ing_para_base(float $quantidade, string $unidade): float
    {
        return estoque_converter_para_base($quantidade, strtolower($unidade));
    }

    /** Mostra a quantidade base de forma legivel: 1500 g => "1,5 kg"; 12 un => "12 un". */
    function ing_formatar(float $base, string $unidadeBase): string
    {
        $fmt = static function (float $v): string {
            if (abs($v - round($v)) < 0.0005) {
                return number_format(round($v), 0, ',', '.');
            }
            // So corta zeros a direita da parte decimal: 1,50 -> 1,5 (nunca 20 -> 2).
            return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');
        };
        if ($unidadeBase === 'g' && (abs($base) >= 1000 || abs($base) < 0.0005)) {
            return $fmt($base / 1000) . ' kg';
        }
        if ($unidadeBase === 'ml' && (abs($base) >= 1000 || abs($base) < 0.0005)) {
            return $fmt($base / 1000) . ' L';
        }
        return $fmt($base) . ' ' . ($unidadeBase === 'un' ? 'un' : $unidadeBase);
    }

    /** Limite de ingredientes do plano (-1 = sem limite). */
    function ing_limite(int $planoRid): int
    {
        $dados = plano_get_dados($planoRid);
        $limites = plano_get_limites(plano_normalizar_nome($dados['plano_nome'] ?? 'BASICO'));
        return isset($limites['insumos']) ? (int)$limites['insumos'] : -1;
    }

    function ing_contar(PDO $db, int $restauranteId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM estoque_insumos WHERE restaurante_id = ? AND ativo = 1');
        $stmt->execute([$restauranteId]);
        return (int)$stmt->fetchColumn();
    }

    if (!defined('ING_DIAS_MIN_PREVISAO')) {
        define('ING_DIAS_MIN_PREVISAO', 5);
        define('ING_DIAS_MAX_PREVISAO', 60);
    }

    /**
     * @return array{estado:string, texto:string, dias?:int, consumo_txt?:string}
     *   estado: sem_vendas | a_aprender | ok
     */
    function ing_previsao(float $saldo, float $saida14d, int $diasComSaida, string $unidadeBase): array
    {
        if ($saida14d <= 0) {
            return ['estado' => 'sem_vendas', 'texto' => ''];
        }
        if ($diasComSaida < ING_DIAS_MIN_PREVISAO) {
            return ['estado' => 'a_aprender', 'texto' => 'ainda a aprender o consumo (' . $diasComSaida . ' de ' . ING_DIAS_MIN_PREVISAO . ' dias com vendas)'];
        }
        $porDia = $saida14d / 14;
        $dias = (int)floor(max(0, $saldo) / $porDia);
        $consumo = ing_formatar($porDia, $unidadeBase);
        if ($dias === 0) {
            $texto = 'não chega para hoje';
        } elseif ($dias > ING_DIAS_MAX_PREVISAO) {
            $texto = 'dá para mais de 2 meses';
        } else {
            $texto = 'dá para ~' . $dias . ($dias === 1 ? ' dia' : ' dias');
        }
        return ['estado' => 'ok', 'texto' => $texto . ' · gasta ~' . $consumo . '/dia', 'dias' => $dias, 'consumo_txt' => $consumo];
    }

    /** Lista de ingredientes com saldo e estado ("acabou", "a acabar", "ok"). */
    function ing_listar(PDO $db, int $restauranteId): array
    {
        $stmt = $db->prepare("
            SELECT i.id, i.nome, i.unidade_base, i.estoque_minimo_base,
                   COALESCE((SELECT SUM(s.saldo_base) FROM estoque_saldos s
                             WHERE s.restaurante_id = i.restaurante_id AND s.insumo_id = i.id), 0) AS saldo_base,
                   (SELECT COUNT(DISTINCT ft.produto_id) FROM estoque_ficha_itens fi
                      JOIN estoque_fichas_tecnicas ft ON ft.id = fi.ficha_id AND ft.ativo = 1
                     WHERE fi.insumo_id = i.id) AS usado_em,
                   COALESCE((SELECT SUM(m.quantidade_base) FROM estoque_movimentos m
                             WHERE m.restaurante_id = i.restaurante_id AND m.insumo_id = i.id
                               AND m.tipo = 'SAIDA' AND m.criado_em >= DATE_SUB(NOW(), INTERVAL 14 DAY)), 0) AS saida_14d,
                   (SELECT COUNT(DISTINCT DATE(m.criado_em)) FROM estoque_movimentos m
                     WHERE m.restaurante_id = i.restaurante_id AND m.insumo_id = i.id
                       AND m.tipo = 'SAIDA' AND m.criado_em >= DATE_SUB(NOW(), INTERVAL 14 DAY)) AS dias_com_saida
            FROM estoque_insumos i
            WHERE i.restaurante_id = ? AND i.ativo = 1
            ORDER BY i.nome
        ");
        $stmt->execute([$restauranteId]);
        $lista = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $saldo = (float)$r['saldo_base'];
            $minimo = (float)$r['estoque_minimo_base'];
            $estado = $saldo <= 0.0001 ? 'acabou' : ($minimo > 0 && $saldo <= $minimo ? 'a_acabar' : 'ok');
            $lista[] = [
                'id' => (int)$r['id'],
                'nome' => (string)$r['nome'],
                'unidade_base' => (string)$r['unidade_base'],
                'saldo_base' => round($saldo, 3),
                'minimo_base' => round($minimo, 3),
                'saldo_txt' => ing_formatar(max(0, $saldo), (string)$r['unidade_base']),
                'minimo_txt' => $minimo > 0 ? ing_formatar($minimo, (string)$r['unidade_base']) : '',
                'estado' => $estado,
                'usado_em' => (int)$r['usado_em'],
                // Previsao "da para X dias" (consumo medio dos ultimos 14 dias).
                // So com vendas em pelo menos ING_DIAS_MIN_PREVISAO dias diferentes; antes disso
                // a media engana (1 prato de teste daria "2786 dias").
                'previsao' => ing_previsao($saldo, (float)$r['saida_14d'], (int)$r['dias_com_saida'], (string)$r['unidade_base']),
            ];
        }
        // O que precisa de atencao primeiro: acabou, depois a acabar, depois por nome.
        $ordem = ['acabou' => 0, 'a_acabar' => 1, 'ok' => 2];
        usort($lista, static fn($a, $b) => [$ordem[$a['estado']], mb_strtolower($a['nome'])] <=> [$ordem[$b['estado']], mb_strtolower($b['nome'])]);
        return $lista;
    }

    function ing_buscar(PDO $db, int $restauranteId, int $insumoId): array
    {
        $stmt = $db->prepare('SELECT id, nome, unidade_base FROM estoque_insumos WHERE id = ? AND restaurante_id = ? AND ativo = 1');
        $stmt->execute([$insumoId, $restauranteId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new InvalidArgumentException('Ingrediente não encontrado.');
        }
        return $r;
    }

    function ing_validar_unidade(string $unidade, ?string $unidadeBase = null): string
    {
        $u = ing_unidades()[$unidade] ?? null;
        if ($u === null) {
            throw new InvalidArgumentException('Unidade inválida.');
        }
        if ($unidadeBase !== null && $u['base'] !== $unidadeBase) {
            throw new InvalidArgumentException('Unidade não combina com este ingrediente (é medido em ' . ($unidadeBase === 'g' ? 'peso' : ($unidadeBase === 'ml' ? 'volume' : 'unidades')) . ').');
        }
        return $u['base'];
    }

    /** Cria ingrediente; $quantidade e $minimo vem na unidade escolhida. */
    function ing_criar(PDO $db, int $restauranteId, int $planoRid, string $nome, string $unidade, float $quantidade, float $minimo, int $usuarioId): int
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        if ($nome === '' || mb_strlen($nome) > 140) {
            throw new InvalidArgumentException('Escreva o nome do ingrediente.');
        }
        if ($quantidade < 0 || $minimo < 0) {
            throw new InvalidArgumentException('As quantidades não podem ser negativas.');
        }
        $base = ing_validar_unidade($unidade);
        $limite = ing_limite($planoRid);
        if ($limite >= 0 && ing_contar($db, $restauranteId) >= $limite) {
            throw new InvalidArgumentException('O seu plano permite até ' . $limite . ' ingredientes. Arquive algum ou suba para o plano Profissional.');
        }
        $dup = $db->prepare('SELECT COUNT(*) FROM estoque_insumos WHERE restaurante_id = ? AND ativo = 1 AND LOWER(nome) = LOWER(?)');
        $dup->execute([$restauranteId, $nome]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new InvalidArgumentException('Já existe um ingrediente com esse nome.');
        }

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO estoque_insumos (restaurante_id, nome, unidade_base, estoque_minimo_base, ativo) VALUES (?, ?, ?, ?, 1)')
                ->execute([$restauranteId, $nome, $base, round(ing_para_base($minimo, $unidade), 3)]);
            $id = (int)$db->lastInsertId();
            $qtdBase = round(ing_para_base($quantidade, $unidade), 3);
            estoque_upsert_saldo($db, $restauranteId, null, $id, $qtdBase, null);
            if ($qtdBase > 0) {
                estoque_registrar_movimento($db, [
                    'restaurante_id' => $restauranteId, 'insumo_id' => $id, 'tipo' => 'ENTRADA', 'origem' => 'MANUAL',
                    'quantidade_base' => $qtdBase, 'unidade_origem' => $unidade, 'responsavel_id' => $usuarioId,
                    'motivo' => 'Quantidade inicial',
                ]);
            }
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** "Comprei mais": soma a quantidade. */
    function ing_entrada(PDO $db, int $restauranteId, int $insumoId, float $quantidade, string $unidade, int $usuarioId): void
    {
        $ins = ing_buscar($db, $restauranteId, $insumoId);
        ing_validar_unidade($unidade, (string)$ins['unidade_base']);
        if ($quantidade <= 0) {
            throw new InvalidArgumentException('Indique a quantidade que entrou.');
        }
        $qtdBase = round(ing_para_base($quantidade, $unidade), 3);
        $db->beginTransaction();
        try {
            estoque_upsert_saldo($db, $restauranteId, null, $insumoId, $qtdBase, null);
            estoque_registrar_movimento($db, [
                'restaurante_id' => $restauranteId, 'insumo_id' => $insumoId, 'tipo' => 'ENTRADA', 'origem' => 'COMPRA',
                'quantidade_base' => $qtdBase, 'unidade_origem' => $unidade, 'responsavel_id' => $usuarioId,
                'motivo' => 'Entrada (compra)',
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** "Contei e tenho X": acerta o saldo para o valor contado (e opcionalmente o minimo). */
    function ing_acertar(PDO $db, int $restauranteId, int $insumoId, float $contado, string $unidade, ?float $minimo, int $usuarioId): void
    {
        $ins = ing_buscar($db, $restauranteId, $insumoId);
        ing_validar_unidade($unidade, (string)$ins['unidade_base']);
        if ($contado < 0 || ($minimo !== null && $minimo < 0)) {
            throw new InvalidArgumentException('As quantidades não podem ser negativas.');
        }
        $stmt = $db->prepare('SELECT COALESCE(SUM(saldo_base), 0) FROM estoque_saldos WHERE restaurante_id = ? AND insumo_id = ?');
        $stmt->execute([$restauranteId, $insumoId]);
        $atual = (float)$stmt->fetchColumn();
        $novo = round(ing_para_base($contado, $unidade), 3);
        $delta = round($novo - $atual, 3);

        $db->beginTransaction();
        try {
            if (abs($delta) >= 0.001) {
                estoque_upsert_saldo($db, $restauranteId, null, $insumoId, $delta, null);
                estoque_registrar_movimento($db, [
                    'restaurante_id' => $restauranteId, 'insumo_id' => $insumoId, 'tipo' => 'AJUSTE', 'origem' => 'MANUAL',
                    'quantidade_base' => $delta, 'unidade_origem' => $unidade, 'responsavel_id' => $usuarioId,
                    'motivo' => 'Contagem: ' . ing_formatar($novo, (string)$ins['unidade_base']),
                ]);
            }
            if ($minimo !== null) {
                $db->prepare('UPDATE estoque_insumos SET estoque_minimo_base = ? WHERE id = ? AND restaurante_id = ?')
                    ->execute([round(ing_para_base($minimo, $unidade), 3), $insumoId, $restauranteId]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Arquiva (esconde) o ingrediente e tira-o das receitas. */
    function ing_arquivar(PDO $db, int $restauranteId, int $insumoId): void
    {
        ing_buscar($db, $restauranteId, $insumoId);
        $db->prepare('UPDATE estoque_insumos SET ativo = 0 WHERE id = ? AND restaurante_id = ?')->execute([$insumoId, $restauranteId]);
        $db->prepare('DELETE fi FROM estoque_ficha_itens fi JOIN estoque_fichas_tecnicas ft ON ft.id = fi.ficha_id WHERE fi.insumo_id = ? AND ft.restaurante_id = ?')
            ->execute([$insumoId, $restauranteId]);
    }

    /** Pratos do restaurante com o numero de ingredientes da receita. */
    function ing_pratos(PDO $db, int $restauranteId): array
    {
        $stmt = $db->prepare("
            SELECT p.id, p.nome,
                   (SELECT COUNT(*) FROM estoque_fichas_tecnicas ft JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
                     WHERE ft.produto_id = p.id AND ft.restaurante_id = p.restaurante_id AND ft.ativo = 1 AND ft.filial_id IS NULL) AS n_ingredientes
            FROM produtos p
            WHERE p.restaurante_id = ? AND COALESCE(p.ativo, 1) = 1
            ORDER BY p.nome
        ");
        $stmt->execute([$restauranteId]);
        // Resumo legivel da receita de cada prato: "1 un Pão · 150 g Carne".
        $itens = $db->prepare("
            SELECT ft.produto_id, fi.quantidade_base, i.nome, i.unidade_base
            FROM estoque_fichas_tecnicas ft
            JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            JOIN estoque_insumos i ON i.id = fi.insumo_id AND i.ativo = 1
            WHERE ft.restaurante_id = ? AND ft.ativo = 1 AND ft.filial_id IS NULL
            ORDER BY i.nome
        ");
        $itens->execute([$restauranteId]);
        $resumo = [];
        foreach ($itens->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
            $resumo[(int)$it['produto_id']][] = ing_formatar((float)$it['quantidade_base'], (string)$it['unidade_base']) . ' ' . $it['nome'];
        }
        return array_map(static fn($r) => [
            'id' => (int)$r['id'],
            'nome' => (string)$r['nome'],
            'n_ingredientes' => (int)$r['n_ingredientes'],
            'resumo' => $resumo[(int)$r['id']] ?? [],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Receita de um prato (quantidade por 1 prato, na unidade mais legivel). */
    function ing_receita(PDO $db, int $restauranteId, int $produtoId): array
    {
        $stmt = $db->prepare("
            SELECT fi.insumo_id, fi.quantidade_base, i.nome, i.unidade_base
            FROM estoque_fichas_tecnicas ft
            JOIN estoque_ficha_itens fi ON fi.ficha_id = ft.id
            JOIN estoque_insumos i ON i.id = fi.insumo_id AND i.ativo = 1
            WHERE ft.restaurante_id = ? AND ft.produto_id = ? AND ft.ativo = 1 AND ft.filial_id IS NULL
            ORDER BY i.nome
        ");
        $stmt->execute([$restauranteId, $produtoId]);
        $itens = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $base = (string)$r['unidade_base'];
            $q = (float)$r['quantidade_base'];
            $unidade = $base === 'un' ? 'un' : $base; // g / ml
            $itens[] = ['insumo_id' => (int)$r['insumo_id'], 'nome' => (string)$r['nome'], 'quantidade' => round($q, 3), 'unidade' => $unidade, 'unidade_base' => $base];
        }
        return $itens;
    }

    /**
     * Grava a receita de um prato. $itens: [['insumo_id'=>, 'quantidade'=>, 'unidade'=>'g'|'kg'|...], ...]
     * Lista vazia = o prato deixa de baixar ingredientes.
     */
    function ing_receita_salvar(PDO $db, int $restauranteId, int $produtoId, array $itens): void
    {
        $p = $db->prepare('SELECT id FROM produtos WHERE id = ? AND restaurante_id = ?');
        $p->execute([$produtoId, $restauranteId]);
        if (!$p->fetchColumn()) {
            throw new InvalidArgumentException('Prato não encontrado.');
        }
        $validos = [];
        foreach ($itens as $it) {
            $iid = (int)($it['insumo_id'] ?? 0);
            $q = (float)str_replace(',', '.', (string)($it['quantidade'] ?? 0));
            if ($iid <= 0 || $q <= 0) {
                continue;
            }
            if (isset($validos[$iid])) {
                throw new InvalidArgumentException('O mesmo ingrediente aparece duas vezes na receita.');
            }
            $ins = ing_buscar($db, $restauranteId, $iid);
            $unidade = (string)($it['unidade'] ?? $ins['unidade_base']);
            ing_validar_unidade($unidade, (string)$ins['unidade_base']);
            $validos[$iid] = round(ing_para_base($q, $unidade), 4);
        }

        $db->beginTransaction();
        try {
            $f = $db->prepare('SELECT id FROM estoque_fichas_tecnicas WHERE restaurante_id = ? AND produto_id = ? AND filial_id IS NULL ORDER BY id LIMIT 1');
            $f->execute([$restauranteId, $produtoId]);
            $fichaId = (int)$f->fetchColumn();
            if ($fichaId <= 0 && $validos) {
                $db->prepare('INSERT INTO estoque_fichas_tecnicas (restaurante_id, filial_id, produto_id, rendimento_por_produto, ativo) VALUES (?, NULL, ?, 1, 1)')
                    ->execute([$restauranteId, $produtoId]);
                $fichaId = (int)$db->lastInsertId();
            }
            if ($fichaId > 0) {
                $db->prepare('DELETE FROM estoque_ficha_itens WHERE ficha_id = ?')->execute([$fichaId]);
                $add = $db->prepare('INSERT INTO estoque_ficha_itens (ficha_id, insumo_id, quantidade_base) VALUES (?, ?, ?)');
                foreach ($validos as $iid => $qBase) {
                    $add->execute([$fichaId, $iid, $qBase]);
                }
                $db->prepare('UPDATE estoque_fichas_tecnicas SET ativo = ?, rendimento_por_produto = 1 WHERE id = ?')
                    ->execute([$validos ? 1 : 0, $fichaId]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
