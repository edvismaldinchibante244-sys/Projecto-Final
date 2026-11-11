<?php
declare(strict_types=1);

return [
    'id' => '20260814_1330_plano_catalogo',
    'description' => 'Cria o catalogo central de planos, funcionalidades e historico de utilizacao.',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        $base = $db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'restaurantes'"
        );
        if ((int)$base->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela base restaurantes inexistente; migracao interrompida.');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS planos_disponiveis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(50) NOT NULL UNIQUE,
            nome_display VARCHAR(100) NOT NULL,
            descricao TEXT,
            cor VARCHAR(20) DEFAULT '#6c757d',
            icone VARCHAR(50) DEFAULT 'fa-star',
            preco_mensal DECIMAL(10,2) DEFAULT 0,
            preco_trimestral DECIMAL(10,2) DEFAULT 0,
            preco_anual DECIMAL(10,2) DEFAULT 0,
            limite_produtos INT DEFAULT -1,
            limite_usuarios INT DEFAULT -1,
            limite_mesas INT DEFAULT -1,
            limite_filiais INT DEFAULT 0,
            ordem INT DEFAULT 0,
            ativo TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS restaurante_planos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            plano_id INT NOT NULL,
            data_inicio DATE NOT NULL,
            data_fim DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'ATIVO',
            tipo_plano VARCHAR(20) DEFAULT 'PAGO',
            valor_pago DECIMAL(10,2) DEFAULT 0,
            observacoes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_restaurante_plano (restaurante_id, status),
            FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
            FOREIGN KEY (plano_id) REFERENCES planos_disponiveis(id) ON DELETE RESTRICT,
            INDEX idx_status (status),
            INDEX idx_data_fim (data_fim)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS plano_funcionalidades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plano_id INT NOT NULL,
            funcionalidade VARCHAR(100) NOT NULL,
            permitido TINYINT(1) DEFAULT 1,
            descricao TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_plano_func (plano_id, funcionalidade),
            FOREIGN KEY (plano_id) REFERENCES planos_disponiveis(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS plano_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurante_id INT NOT NULL,
            funcionalidade VARCHAR(100),
            recurso VARCHAR(50),
            acao VARCHAR(50),
            quantidade INT DEFAULT 1,
            limite_anterior INT,
            limite_restante INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE,
            INDEX idx_restaurante (restaurante_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $plans = [
            ['BASICO', 'Básico', 'Operação para lanchonetes pequenas e médias.', '#6c757d', 'fa-user', 799, 2157, 7670, 150, 6, 30, 0, 1],
            ['PROFISSIONAL', 'Profissional', 'Ideal para restaurantes médios.', '#17a2b8', 'fa-star', 1999, 5397, 19190, 500, 10, 50, 0, 2],
            ['EMPRESARIAL', 'Empresarial', 'Para grandes restaurantes.', '#6f42c1', 'fa-crown', 3999, 10797, 38390, -1, -1, -1, 5, 3],
        ];
        $planStmt = $db->prepare(
            'INSERT INTO planos_disponiveis '
            . '(nome, nome_display, descricao, cor, icone, preco_mensal, preco_trimestral, preco_anual, '
            . 'limite_produtos, limite_usuarios, limite_mesas, limite_filiais, ordem) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE nome_display = VALUES(nome_display), descricao = VALUES(descricao), '
            . 'cor = VALUES(cor), icone = VALUES(icone), preco_mensal = VALUES(preco_mensal), '
            . 'preco_trimestral = VALUES(preco_trimestral), preco_anual = VALUES(preco_anual), '
            . 'limite_produtos = VALUES(limite_produtos), limite_usuarios = VALUES(limite_usuarios), '
            . 'limite_mesas = VALUES(limite_mesas), limite_filiais = VALUES(limite_filiais), ordem = VALUES(ordem)'
        );
        foreach ($plans as $plan) {
            $planStmt->execute($plan);
        }

        $features = [
            'pdv', 'caixa', 'contas_abertas', 'garcons', 'estoque_simples', 'produtos_basico',
            'estoque_avancado', 'transferencia_estoque', 'mesas', 'pedidos_online', 'reservas_qr',
            'cardapio_digital', 'fluxo_garcom', 'fluxo_cozinha', 'telas_bar_cozinha', 'subcategorias',
            'relatorio_diario', 'relatorios_avancados', 'estatisticas_produtos', 'relatorios_inteligentes',
            'relatorio_personalizado', 'relatorio_financeiro', 'export_pdf', 'export_excel', 'export_csv',
            'exportacao_relatorios', 'dashboard_executivo', 'comparativo_filiais', 'dashboard_inteligente',
            'analise_abc_vendas', 'recomendacoes_automaticas', 'previsao_demanda', 'clientes',
            'historico_clientes', 'gestao_funcionarios', 'impressao_recibos', 'logo_recibo', 'backup_manual',
            'backup_automatico', 'backup_diario', 'backup_hora', 'download_banco', 'recuperacao_automatica',
            'multi_filial', 'api_integracao', 'notificacoes', 'reservas_notificacao_email', 'suporte_email',
            'suporte_whatsapp', 'suporte_24h', 'suporte_prioritario',
        ];
        $enabled = [
            'BASICO' => [
                'pdv', 'caixa', 'estoque_simples', 'produtos_basico', 'mesas', 'cardapio_digital',
                'fluxo_garcom', 'fluxo_cozinha', 'relatorio_diario', 'clientes', 'impressao_recibos',
                'logo_recibo', 'backup_manual', 'suporte_email',
            ],
            'PROFISSIONAL' => [
                'pdv', 'caixa', 'contas_abertas', 'garcons', 'estoque_simples', 'produtos_basico',
                'estoque_avancado', 'mesas', 'pedidos_online', 'cardapio_digital', 'fluxo_garcom',
                'fluxo_cozinha', 'telas_bar_cozinha', 'subcategorias', 'relatorio_diario',
                'relatorios_avancados', 'estatisticas_produtos', 'relatorio_personalizado',
                'relatorio_financeiro', 'export_pdf', 'export_excel', 'export_csv', 'exportacao_relatorios',
                'clientes', 'historico_clientes', 'gestao_funcionarios', 'impressao_recibos', 'logo_recibo',
                'backup_manual', 'backup_diario', 'reservas_notificacao_email', 'suporte_email',
                'suporte_whatsapp',
            ],
            'EMPRESARIAL' => [
                'pdv', 'caixa', 'contas_abertas', 'garcons', 'estoque_simples', 'produtos_basico',
                'estoque_avancado', 'transferencia_estoque', 'mesas', 'pedidos_online', 'reservas_qr',
                'cardapio_digital', 'fluxo_garcom', 'fluxo_cozinha', 'telas_bar_cozinha', 'subcategorias',
                'relatorio_diario', 'relatorios_avancados', 'estatisticas_produtos', 'relatorios_inteligentes',
                'relatorio_personalizado', 'relatorio_financeiro', 'export_pdf', 'export_excel', 'export_csv',
                'exportacao_relatorios', 'dashboard_executivo', 'comparativo_filiais', 'dashboard_inteligente',
                'analise_abc_vendas', 'recomendacoes_automaticas', 'previsao_demanda', 'clientes',
                'historico_clientes', 'gestao_funcionarios', 'impressao_recibos', 'logo_recibo', 'backup_manual',
                'backup_automatico', 'backup_diario', 'backup_hora', 'download_banco', 'recuperacao_automatica',
                'multi_filial', 'notificacoes', 'reservas_notificacao_email', 'suporte_email',
                'suporte_whatsapp', 'suporte_24h', 'suporte_prioritario',
            ],
        ];
        $absent = ['BASICO' => ['reservas_notificacao_email']];
        $ids = $db->query(
            "SELECT nome, id FROM planos_disponiveis WHERE nome IN ('BASICO','PROFISSIONAL','EMPRESARIAL')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $featureStmt = $db->prepare(
            'INSERT INTO plano_funcionalidades (plano_id, funcionalidade, permitido) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE permitido = VALUES(permitido)'
        );
        foreach ($enabled as $planName => $enabledFeatures) {
            $planId = (int)($ids[$planName] ?? 0);
            if ($planId <= 0) {
                throw new RuntimeException('Plano obrigatorio nao encontrado: ' . $planName);
            }
            foreach ($features as $feature) {
                if (in_array($feature, $absent[$planName] ?? [], true)) {
                    continue;
                }
                $featureStmt->execute([$planId, $feature, in_array($feature, $enabledFeatures, true) ? 1 : 0]);
            }
        }

        $required = [
            'planos_disponiveis' => ['id', 'nome', 'nome_display', 'preco_mensal', 'limite_produtos', 'ativo'],
            'restaurante_planos' => ['id', 'restaurante_id', 'plano_id', 'data_inicio', 'data_fim', 'status'],
            'plano_funcionalidades' => ['id', 'plano_id', 'funcionalidade', 'permitido'],
            'plano_logs' => ['id', 'restaurante_id', 'funcionalidade', 'acao', 'created_at'],
        ];
        foreach ($required as $table => $columns) {
            $stmt = $db->prepare(
                'SELECT column_name FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);
            $found = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $missing = array_diff($columns, $found);
            if ($missing !== []) {
                throw new RuntimeException($table . ' existe com colunas em falta: ' . implode(', ', $missing));
            }
        }

        $indexes = [
            // O historico pode conter varios registros EXPIRADO para o mesmo restaurante.
            // A unicidade de ATIVO/PENDENTE e garantida nas rotinas de ciclo/aprovacao,
            // por isso nao exigimos um UNIQUE (restaurante_id,status) que destruiria o historico.
            'restaurante_planos' => ['idx_status', 'idx_data_fim'],
            'plano_funcionalidades' => ['unique_plano_func'],
            'plano_logs' => ['idx_restaurante', 'idx_created'],
        ];
        foreach ($indexes as $table => $names) {
            foreach ($names as $index) {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM information_schema.statistics '
                    . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
                );
                $stmt->execute([$table, $index]);
                if ((int)$stmt->fetchColumn() === 0) {
                    throw new RuntimeException('Indice obrigatorio ausente: ' . $table . '.' . $index);
                }
            }
        }

        $planCount = $db->query(
            "SELECT COUNT(*) FROM planos_disponiveis WHERE nome IN ('BASICO','PROFISSIONAL','EMPRESARIAL')"
        );
        if ((int)$planCount->fetchColumn() !== 3) {
            throw new RuntimeException('Catalogo obrigatorio de planos incompleto.');
        }
    },
];
