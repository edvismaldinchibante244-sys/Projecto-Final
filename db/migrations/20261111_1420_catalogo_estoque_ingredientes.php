<?php
declare(strict_types=1);

/*
 * Catálogo de planos: acrescenta a funcionalidade 'estoque_ingredientes' que existia
 * no ficheiro planos.php mas não na tabela plano_funcionalidades. O código consulta
 * primeiro o ficheiro, por isso nada muda no comportamento; isto mantém o catálogo
 * da base de dados completo para quem o repovoar ou consultar directamente.
 * Idempotente.
 */
return [
    'id' => '20261111_1420_catalogo_estoque_ingredientes',
    'description' => "Acrescenta 'estoque_ingredientes' ao catálogo plano_funcionalidades (Básico, Profissional e Empresarial).",
    'transactional' => true,
    'up' => static function (PDO $db): void {
        $temTabela = (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plano_funcionalidades'")->fetchColumn();
        if ($temTabela === 0) {
            return;
        }

        $stSel = $db->prepare("SELECT COUNT(*) FROM plano_funcionalidades
            WHERE plano_id = :pid AND funcionalidade = 'estoque_ingredientes'");
        $stIns = $db->prepare("INSERT INTO plano_funcionalidades (plano_id, funcionalidade, permitido, descricao)
            VALUES (:pid, 'estoque_ingredientes', 1, :descricao)");

        $descricoes = [
            1 => 'Ingredientes com receita e baixa automática (versão simples, até ao limite de insumos do plano).',
            2 => 'Ingredientes com receita e baixa automática.',
            3 => 'Ingredientes com receita e baixa automática.',
        ];

        foreach ($descricoes as $planoId => $descricao) {
            $stSel->execute([':pid' => $planoId]);
            if ((int)$stSel->fetchColumn() === 0) {
                $stIns->execute([':pid' => $planoId, ':descricao' => $descricao]);
            }
        }
    },
];
