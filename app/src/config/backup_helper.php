<?php

/*
  Helpers compartilhados para backup manual, automatico, historico e cron.
*/

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email_helper.php';

if (!function_exists('backup_timezone')) {
    function backup_timezone(): DateTimeZone
    {
        $tz = getenv('APP_TIMEZONE') ?: getenv('TZ') ?: 'Africa/Maputo';
        try {
            return new DateTimeZone($tz);
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }
}

if (!function_exists('backup_tabelas_principais')) {
    function backup_tabelas_principais(): array
    {
        return [
            'usuarios',
            'categorias',
            'clientes',
            'produtos',
            'mesas',
            'reservas',
            'pedidos',
            'vendas',
            'caixas',
        ];
    }
}

if (!function_exists('backup_tabelas_filhas')) {
    function backup_tabelas_filhas(): array
    {
        return [
            'itens_pedido' => [
                'parent' => 'pedidos',
                'parent_key' => 'pedido_id',
            ],
            'itens_venda' => [
                'parent' => 'vendas',
                'parent_key' => 'venda_id',
            ],
        ];
    }
}

if (!function_exists('backup_storage_dir')) {
    function backup_storage_dir(int $restauranteId): string
    {
        // Permite definir um caminho fora da webroot (ex.: /home/usuario/backups)
        $customBase = getenv('BACKUP_BASE_DIR');
        $baseRoot = $customBase && is_string($customBase) && $customBase !== ''
            ? rtrim($customBase, "/\\")
            : __DIR__ . '/../../storage/backups';

        $baseDir = $baseRoot . '/restaurante_' . $restauranteId;
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        return $baseDir;
    }
}

if (!function_exists('backup_tabela_existe')) {
    function backup_tabela_existe(PDO $db, string $table): bool
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table
        ");
        $stmt->execute(['table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('backup_coluna_existe')) {
    function backup_coluna_existe(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
        ");
        $stmt->execute([
            'table' => $table,
            'column' => $column,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('backup_formatar_valor_sql')) {
    function backup_formatar_valor_sql($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof DateTimeInterface) {
            return "'" . $value->format('Y-m-d H:i:s') . "'";
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $value = (string)$value;
        $value = str_replace(["\\", "\r", "\n", "'"], ["\\\\", "\\r", "\\n", "\\'"], $value);

        return "'" . $value . "'";
    }
}

if (!function_exists('backup_ensure_tables_exist')) {
    function backup_ensure_tables_exist(?PDO $db = null): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        try {
            if (!$db instanceof PDO) {
                $database = new Database();
                $db = $database->getConnection();
            }

            if (!$db instanceof PDO) {
                return;
            }

            $db->exec("
                CREATE TABLE IF NOT EXISTS backup_configuracoes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurante_id INT NOT NULL,
                    automatico TINYINT(1) NOT NULL DEFAULT 0,
                    frequencia VARCHAR(20) NOT NULL DEFAULT 'DIARIO',
                    hora_execucao TIME NOT NULL DEFAULT '00:00:00',
                    retencao_dias INT NOT NULL DEFAULT 30,
                    notificar_email TINYINT(1) NOT NULL DEFAULT 0,
                    ultimo_backup_em DATETIME NULL,
                    proximo_backup_em DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_backup_config_restaurante (restaurante_id),
                    INDEX idx_backup_config_proximo (proximo_backup_em),
                    CONSTRAINT fk_backup_config_restaurante
                        FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS backup_historico (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    restaurante_id INT NOT NULL,
                    tipo VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
                    status VARCHAR(20) NOT NULL DEFAULT 'SUCESSO',
                    arquivo_nome VARCHAR(255) NOT NULL,
                    arquivo_caminho VARCHAR(500) NOT NULL,
                    tamanho_bytes INT NOT NULL DEFAULT 0,
                    hash_arquivo CHAR(32) NULL,
                    total_tabelas INT NOT NULL DEFAULT 0,
                    total_registros INT NOT NULL DEFAULT 0,
                    origem VARCHAR(50) NOT NULL DEFAULT 'web',
                    mensagem TEXT NULL,
                    executado_em DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_backup_hist_restaurante (restaurante_id),
                    INDEX idx_backup_hist_executado (executado_em),
                    CONSTRAINT fk_backup_hist_restaurante
                        FOREIGN KEY (restaurante_id) REFERENCES restaurantes(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('[BACKUP][INIT] ' . $e->getMessage());
        }
    }
}

if (!function_exists('backup_normalizar_config')) {
    function backup_normalizar_config(array $config): array
    {
        $automatico = !empty($config['automatico']) ? 1 : 0;
        $frequencia = strtoupper(trim((string)($config['frequencia'] ?? 'DIARIO')));
        if (!in_array($frequencia, ['DIARIO', 'HORARIO'], true)) {
            $frequencia = 'DIARIO';
        }

        $horaExecucao = trim((string)($config['hora_execucao'] ?? '00:00:00'));
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horaExecucao)) {
            $horaExecucao = '00:00:00';
        } elseif (strlen($horaExecucao) === 5) {
            $horaExecucao .= ':00';
        }

        $retencaoDias = max(1, (int)($config['retencao_dias'] ?? 30));
        $notificarEmail = !empty($config['notificar_email']) ? 1 : 0;

        return [
            'automatico' => $automatico,
            'frequencia' => $frequencia,
            'hora_execucao' => $horaExecucao,
            'retencao_dias' => $retencaoDias,
            'notificar_email' => $notificarEmail,
            'ultimo_backup_em' => $config['ultimo_backup_em'] ?? null,
            'proximo_backup_em' => $config['proximo_backup_em'] ?? null,
        ];
    }
}

if (!function_exists('backup_obter_configuracao')) {
    function backup_obter_configuracao(PDO $db, int $restauranteId): array
    {
        backup_ensure_tables_exist($db);

        $defaults = [
            'id' => null,
            'restaurante_id' => $restauranteId,
            'automatico' => 0,
            'frequencia' => 'DIARIO',
            'hora_execucao' => '00:00:00',
            'retencao_dias' => 30,
            'notificar_email' => 0,
            'ultimo_backup_em' => null,
            'proximo_backup_em' => null,
        ];

        $stmt = $db->prepare("SELECT * FROM backup_configuracoes WHERE restaurante_id = :restaurante_id LIMIT 1");
        $stmt->execute(['restaurante_id' => $restauranteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $defaults;
        }

        return array_merge($defaults, [
            'id' => (int)$row['id'],
            'restaurante_id' => (int)$row['restaurante_id'],
            'automatico' => (int)$row['automatico'],
            'frequencia' => strtoupper((string)($row['frequencia'] ?? 'DIARIO')),
            'hora_execucao' => (string)($row['hora_execucao'] ?? '00:00:00'),
            'retencao_dias' => (int)($row['retencao_dias'] ?? 30),
            'notificar_email' => (int)$row['notificar_email'],
            'ultimo_backup_em' => $row['ultimo_backup_em'] ?? null,
            'proximo_backup_em' => $row['proximo_backup_em'] ?? null,
        ]);
    }
}

if (!function_exists('backup_parse_datetime')) {
    function backup_parse_datetime(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, backup_timezone());
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('backup_calcular_proxima_execucao')) {
    function backup_calcular_proxima_execucao(array $config, ?DateTimeImmutable $base = null): ?DateTimeImmutable
    {
        if (empty($config['automatico'])) {
            return null;
        }

        $tz = backup_timezone();
        $base = $base ?? new DateTimeImmutable('now', $tz);
        if ($base->getTimezone()->getName() !== $tz->getName()) {
            $base = $base->setTimezone($tz);
        }

        $frequencia = strtoupper(trim((string)($config['frequencia'] ?? 'DIARIO')));

        if ($frequencia === 'HORARIO') {
            return $base->modify('+1 hour');
        }

        $hora = trim((string)($config['hora_execucao'] ?? '00:00:00'));
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) {
            $hora = '00:00:00';
        }

        [$h, $m, $s] = array_map('intval', explode(':', $hora));
        $agendada = $base->setTime($h, $m, $s);

        if ($agendada <= $base) {
            $agendada = $agendada->modify('+1 day');
        }

        return $agendada;
    }
}

if (!function_exists('backup_esta_pendente')) {
    /**
     * True quando o backup automatico deve correr agora.
     */
    function backup_esta_pendente(array $config, ?DateTimeImmutable $agora = null): bool
    {
        if (empty($config['automatico'])) {
            return false;
        }

        // Nunca executou: corre ja (mesmo que proximo esteja no futuro por bug antigo).
        if (empty($config['ultimo_backup_em'])) {
            return true;
        }

        $agora = $agora ?? new DateTimeImmutable('now', backup_timezone());
        $proxima = backup_parse_datetime($config['proximo_backup_em'] ?? null);

        // Sem agenda: devido imediatamente.
        if ($proxima === null) {
            return true;
        }

        return $proxima <= $agora;
    }
}

if (!function_exists('backup_salvar_configuracao')) {
    function backup_salvar_configuracao(PDO $db, int $restauranteId, array $dados): array
    {
        backup_ensure_tables_exist($db);

        $atual = backup_obter_configuracao($db, $restauranteId);
        $config = backup_normalizar_config(array_merge($atual, $dados, ['restaurante_id' => $restauranteId]));
        $agora = new DateTimeImmutable('now', backup_timezone());

        if (empty($config['automatico'])) {
            $config['proximo_backup_em'] = null;
        } elseif (array_key_exists('proximo_backup_em', $dados)) {
            // Permite o caller definir a agenda (ex.: apos geracao bem-sucedida).
            $config['proximo_backup_em'] = $dados['proximo_backup_em'] ?: null;
        } elseif (empty($atual['id']) || empty($config['ultimo_backup_em'])) {
            // Primeira activacao: devido imediatamente (evita saltar o 1.o cron).
            $config['proximo_backup_em'] = $agora->format('Y-m-d H:i:s');
        } else {
            $proxima = backup_calcular_proxima_execucao($config, $agora);
            $config['proximo_backup_em'] = $proxima ? $proxima->format('Y-m-d H:i:s') : null;
        }

        $sql = "
            INSERT INTO backup_configuracoes
                (restaurante_id, automatico, frequencia, hora_execucao, retencao_dias, notificar_email, ultimo_backup_em, proximo_backup_em)
            VALUES
                (:restaurante_id, :automatico, :frequencia, :hora_execucao, :retencao_dias, :notificar_email, :ultimo_backup_em, :proximo_backup_em)
            ON DUPLICATE KEY UPDATE
                automatico = VALUES(automatico),
                frequencia = VALUES(frequencia),
                hora_execucao = VALUES(hora_execucao),
                retencao_dias = VALUES(retencao_dias),
                notificar_email = VALUES(notificar_email),
                ultimo_backup_em = VALUES(ultimo_backup_em),
                proximo_backup_em = VALUES(proximo_backup_em)
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'restaurante_id' => $restauranteId,
            'automatico' => $config['automatico'],
            'frequencia' => $config['frequencia'],
            'hora_execucao' => $config['hora_execucao'],
            'retencao_dias' => $config['retencao_dias'],
            'notificar_email' => $config['notificar_email'],
            'ultimo_backup_em' => $config['ultimo_backup_em'] ?: null,
            'proximo_backup_em' => $config['proximo_backup_em'] ?: null,
        ]);

        return backup_obter_configuracao($db, $restauranteId);
    }
}

if (!function_exists('backup_formatar_tamanho')) {
    function backup_formatar_tamanho(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $valor = (float)$bytes;
        while ($valor >= 1024 && $i < count($unidades) - 1) {
            $valor /= 1024;
            $i++;
        }

        return number_format($valor, $i === 0 ? 0 : 2, ',', '.') . ' ' . $unidades[$i];
    }
}

if (!function_exists('backup_listar_historico')) {
    function backup_listar_historico(PDO $db, int $restauranteId, int $limite = 20): array
    {
        backup_ensure_tables_exist($db);

        $stmt = $db->prepare("
            SELECT *
            FROM backup_historico
            WHERE restaurante_id = :restaurante_id
            ORDER BY executado_em DESC, id DESC
            LIMIT :limite
        ");
        $stmt->bindValue(':restaurante_id', $restauranteId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
        $stmt->execute();

        $historico = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $historico[] = [
                'id' => (int)$row['id'],
                'restaurante_id' => (int)$row['restaurante_id'],
                'tipo' => strtoupper((string)$row['tipo']),
                'status' => strtoupper((string)$row['status']),
                'arquivo_nome' => (string)$row['arquivo_nome'],
                'arquivo_caminho' => (string)$row['arquivo_caminho'],
                'tamanho_bytes' => (int)$row['tamanho_bytes'],
                'tamanho_formatado' => backup_formatar_tamanho((int)$row['tamanho_bytes']),
                'hash_arquivo' => (string)($row['hash_arquivo'] ?? ''),
                'total_tabelas' => (int)$row['total_tabelas'],
                'total_registros' => (int)$row['total_registros'],
                'origem' => (string)$row['origem'],
                'mensagem' => (string)($row['mensagem'] ?? ''),
                'executado_em' => (string)$row['executado_em'],
                // caminho resolvido para minimizar "IndisponÃ­vel" se realpath mudar
                'arquivo_path_resolved' => backup_resolver_caminho($row['arquivo_caminho'] ?? '', $row['arquivo_nome'] ?? '', $restauranteId),
            ];
        }

        return $historico;
    }
}

if (!function_exists('backup_resolver_caminho')) {
    /**
     * Tenta resolver o caminho absoluto do arquivo de backup.
     * Se o valor gravado nÃ£o existir, tenta reconstruir a partir do storage local.
     */
    function backup_resolver_caminho(string $caminho, string $nome, int $restauranteId): string
    {
        // se jÃ¡ existe, retorna
        if ($caminho !== '' && is_file($caminho)) {
            return $caminho;
        }

        // tenta realpath do informado
        if ($caminho !== '') {
            $real = realpath($caminho);
            if ($real && is_file($real)) {
                return $real;
            }
        }

        // reconstrÃ³i a partir do diretÃ³rio padrÃ£o
        if ($nome !== '') {
            $fallback = backup_storage_dir($restauranteId) . DIRECTORY_SEPARATOR . $nome;
            if (is_file($fallback)) {
                return $fallback;
            }
            $realFallback = realpath($fallback);
            if ($realFallback && is_file($realFallback)) {
                return $realFallback;
            }
        }

        return $caminho;
    }
}

if (!function_exists('backup_limpar_antigos')) {
    function backup_limpar_antigos(PDO $db, int $restauranteId, int $retencaoDias): int
    {
        $retencaoDias = max(1, $retencaoDias);
        $limite = (new DateTimeImmutable('now', backup_timezone()))->modify('-' . $retencaoDias . ' days')->format('Y-m-d H:i:s');

        $stmt = $db->prepare("
            SELECT id, arquivo_caminho
            FROM backup_historico
            WHERE restaurante_id = :restaurante_id
              AND executado_em < :limite
        ");
        $stmt->execute([
            'restaurante_id' => $restauranteId,
            'limite' => $limite,
        ]);

        $ids = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids[] = (int)$row['id'];
            $arquivo = (string)($row['arquivo_caminho'] ?? '');
            if ($arquivo !== '' && is_file($arquivo)) {
                @unlink($arquivo);
            }
        }

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $db->prepare("DELETE FROM backup_historico WHERE id IN ($placeholders)");
        $delete->execute($ids);

        return count($ids);
    }
}

if (!function_exists('backup_gerar_dump_sql')) {
    function backup_gerar_dump_sql(PDO $db, int $restauranteId): array
    {
        $sql = "-- Backup do Banco de Dados - Restaurante SaaS\n";
        $sql .= "-- Gerado em: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        $tabelasExportadas = [];
        $totalRegistros = 0;

        foreach (backup_tabelas_principais() as $table) {
            if (!backup_tabela_existe($db, $table) || !backup_coluna_existe($db, $table, 'restaurante_id')) {
                continue;
            }

            $stmtCreate = $db->query("SHOW CREATE TABLE `$table`");
            $create = $stmtCreate ? $stmtCreate->fetch(PDO::FETCH_ASSOC) : null;
            if (!$create || empty($create['Create Table'])) {
                continue;
            }

            $tabelasExportadas[] = $table;
            $sql .= "\n-- Tabela: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            $stmtData = $db->prepare("SELECT * FROM `$table` WHERE restaurante_id = :restaurante_id");
            $stmtData->execute(['restaurante_id' => $restauranteId]);

            while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $quotedColumns = array_map(static function ($column) {
                    return '`' . str_replace('`', '``', $column) . '`';
                }, $columns);
                $values = array_map('backup_formatar_valor_sql', array_values($row));
                $sql .= "INSERT INTO `$table` (" . implode(', ', $quotedColumns) . ") VALUES (" . implode(', ', $values) . ");\n";
                $totalRegistros++;
            }
        }

        foreach (backup_tabelas_filhas() as $table => $meta) {
            $parent = $meta['parent'] ?? '';
            $parentKey = $meta['parent_key'] ?? '';
            if ($table === '' || $parent === '' || $parentKey === '') {
                continue;
            }

            if (!backup_tabela_existe($db, $table) || !backup_tabela_existe($db, $parent)) {
                continue;
            }

            $stmtCreate = $db->query("SHOW CREATE TABLE `$table`");
            $create = $stmtCreate ? $stmtCreate->fetch(PDO::FETCH_ASSOC) : null;
            if (!$create || empty($create['Create Table'])) {
                continue;
            }

            $tabelasExportadas[] = $table;
            $sql .= "\n-- Tabela: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            $stmtData = $db->prepare("
                SELECT child.*
                FROM `$table` child
                INNER JOIN `$parent` parent ON parent.id = child.`$parentKey`
                WHERE parent.restaurante_id = :restaurante_id
            ");
            $stmtData->execute(['restaurante_id' => $restauranteId]);

            while ($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $quotedColumns = array_map(static function ($column) {
                    return '`' . str_replace('`', '``', $column) . '`';
                }, $columns);
                $values = array_map('backup_formatar_valor_sql', array_values($row));
                $sql .= "INSERT INTO `$table` (" . implode(', ', $quotedColumns) . ") VALUES (" . implode(', ', $values) . ");\n";
                $totalRegistros++;
            }
        }

        $sql .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

        return [
            'sql' => $sql,
            'total_tabelas' => count(array_unique($tabelasExportadas)),
            'total_registros' => $totalRegistros,
            'tabelas' => array_values(array_unique($tabelasExportadas)),
        ];
    }
}

if (!function_exists('backup_executar_geracao')) {
    function backup_executar_geracao(PDO $db, int $restauranteId, array $config = [], string $tipo = 'MANUAL', string $origem = 'web'): array
    {
        backup_ensure_tables_exist($db);

        $configAtual = empty($config) ? backup_obter_configuracao($db, $restauranteId) : backup_normalizar_config($config);
        $dump = backup_gerar_dump_sql($db, $restauranteId);

        if (empty($dump['sql'])) {
            return [
                'success' => false,
                'message' => 'Nao foi possivel gerar o backup.',
            ];
        }

        $storageDir = backup_storage_dir($restauranteId);
        $timestamp = date('Ymd_His');
        $arquivoNome = sprintf('backup_restaurante_%d_%s_%s.sql', $restauranteId, strtoupper($tipo), $timestamp);
        $arquivoPath = $storageDir . DIRECTORY_SEPARATOR . $arquivoNome;

        if (file_put_contents($arquivoPath, $dump['sql']) === false) {
            // Diz porque falhou: sem permissao de escrita ou sem espaco no disco.
            $motivo = 'Falha ao gravar o ficheiro de backup na pasta ' . $storageDir . '.';
            if (!is_writable($storageDir)) {
                $motivo .= ' A pasta nao tem permissao de escrita.';
            } else {
                $livre = @disk_free_space($storageDir);
                $preciso = strlen((string)$dump['sql']);
                if ($livre !== false && $livre < $preciso) {
                    $motivo .= ' Nao ha espaco livre no disco (precisa de cerca de '
                        . backup_formatar_tamanho($preciso) . ' e ha ' . backup_formatar_tamanho((int)$livre) . ').';
                }
            }
            return [
                'success' => false,
                'message' => $motivo,
                'pasta' => $storageDir,
            ];
        }

        // Resolve caminho absoluto (realpath pode falhar se permissÃµes nÃ£o permitirem)
        $arquivoPathReal = realpath($arquivoPath);
        if ($arquivoPathReal === false) {
            $arquivoPathReal = $arquivoPath;
        }

        $tamanhoBytes = (int)@filesize($arquivoPath);
        $hashArquivo = is_file($arquivoPath) ? (string)@md5_file($arquivoPath) : '';
        $agora = (new DateTimeImmutable('now', backup_timezone()))->format('Y-m-d H:i:s');
        $mensagem = sprintf('%d tabelas, %d registros exportados.', (int)$dump['total_tabelas'], (int)$dump['total_registros']);

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO backup_historico
                    (restaurante_id, tipo, status, arquivo_nome, arquivo_caminho, tamanho_bytes, hash_arquivo, total_tabelas, total_registros, origem, mensagem, executado_em)
                VALUES
                    (:restaurante_id, :tipo, :status, :arquivo_nome, :arquivo_caminho, :tamanho_bytes, :hash_arquivo, :total_tabelas, :total_registros, :origem, :mensagem, :executado_em)
            ");
            $stmt->execute([
                'restaurante_id' => $restauranteId,
                'tipo' => strtoupper($tipo) ?: 'MANUAL',
                'status' => 'SUCESSO',
                'arquivo_nome' => $arquivoNome,
                'arquivo_caminho' => $arquivoPathReal,
                'tamanho_bytes' => $tamanhoBytes,
                'hash_arquivo' => $hashArquivo ?: null,
                'total_tabelas' => (int)$dump['total_tabelas'],
                'total_registros' => (int)$dump['total_registros'],
                'origem' => $origem ?: 'web',
                'mensagem' => $mensagem,
                'executado_em' => $agora,
            ]);
            $historicoId = (int)$db->lastInsertId();

            $configAtual['automatico'] = !empty($configAtual['automatico']) ? 1 : 0;
            $configAtual['ultimo_backup_em'] = $agora;
            if ($configAtual['automatico']) {
                $proxima = backup_calcular_proxima_execucao(
                    $configAtual,
                    backup_parse_datetime($agora) ?? new DateTimeImmutable('now', backup_timezone())
                );
                $configAtual['proximo_backup_em'] = $proxima ? $proxima->format('Y-m-d H:i:s') : null;
            } else {
                $configAtual['proximo_backup_em'] = null;
            }
            backup_salvar_configuracao($db, $restauranteId, $configAtual);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_file($arquivoPath)) {
                @unlink($arquivoPath);
            }
            error_log('[BACKUP][EXEC] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro ao registrar o backup: ' . $e->getMessage(),
            ];
        }

        $retencaoDias = (int)($configAtual['retencao_dias'] ?? 30);
        if ($retencaoDias > 0) {
            backup_limpar_antigos($db, $restauranteId, $retencaoDias);
        }

        $totalTabelas = (int)$dump['total_tabelas'];
        $totalRegistros = (int)$dump['total_registros'];
        unset($dump);

        // CÃ³pia externa criptografada opcional
        $externalDir = getenv('BACKUP_EXTERNAL_DIR');
        $externalKey = getenv('BACKUP_EXTERNAL_KEY'); // 32 chars recomendado
        if ($externalDir && is_dir($externalDir)) {
            $logPath = dirname(__DIR__, 2) . '/storage/logs/backup.log';
            $target = rtrim($externalDir, "/\\") . DIRECTORY_SEPARATOR . $arquivoNome . ($externalKey ? '.enc' : '');

            try {
                if ($externalKey) {
                    $plain = file_get_contents($arquivoPath);
                    if ($plain === false) {
                        throw new RuntimeException('Falha ao ler backup para criptografar.');
                    }
                    $iv = random_bytes(16);
                    $cipher = openssl_encrypt($plain, 'AES-256-CTR', substr(hash('sha256', $externalKey, true), 0, 32), OPENSSL_RAW_DATA, $iv);
                    if ($cipher === false) {
                        throw new RuntimeException('Falha ao criptografar backup.');
                    }
                    $payload = base64_encode($iv . $cipher);
                    if (file_put_contents($target, $payload) === false) {
                        throw new RuntimeException('Falha ao gravar backup criptografado em destino externo.');
                    }
                } else {
                    if (!@copy($arquivoPath, $target)) {
                        throw new RuntimeException('Falha ao copiar backup para destino externo.');
                    }
                }
            } catch (Throwable $e) {
                @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . "] ERRO copia externa: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }

        // NotificaÃ§Ã£o por email (opcional)
        if (!empty($configAtual['notificar_email'])) {
            backup_enviar_notificacao_email(
                $db,
                $restauranteId,
                $configAtual,
                [
                    'arquivo_nome' => $arquivoNome,
                    'arquivo_path' => $arquivoPath,
                    'tamanho_bytes' => $tamanhoBytes,
                    'total_tabelas' => $totalTabelas,
                    'total_registros' => $totalRegistros,
                    'tipo' => $tipo,
                    'origem' => $origem,
                ]
            );
        }

        return [
            'success' => true,
            'message' => $mensagem,
            'arquivo_nome' => $arquivoNome,
            'arquivo_path' => $arquivoPath,
            'arquivo_url_name' => $arquivoNome,
            'historico_id' => $historicoId,
            'tamanho_bytes' => $tamanhoBytes,
            'total_tabelas' => $totalTabelas,
            'total_registros' => $totalRegistros,
        ];
    }
}

if (!function_exists('backup_processar_pendentes')) {
    /**
     * Processa backups automaticos em atraso.
     * Usado pelo cron CLI e pelo tick oportunista na web (quando o agendador falha).
     *
     * @return array{executados:int,falhas:int,ignorados:int,detalhes:array<int,array>}
     */
    function backup_processar_pendentes(PDO $db, ?int $apenasRestauranteId = null, string $origem = 'cron'): array
    {
        backup_ensure_tables_exist($db);

        $params = [];
        // Inclui ATIVO e BLOQUEADO (filiais podem ficar BLOQUEADO e ainda operar via matriz).
        // Exclui apenas INATIVO/EXCLUIDO.
        $sql = "
            SELECT r.id, r.nome, r.plano, r.status, r.is_matriz, r.filial_id
            FROM restaurantes r
            WHERE UPPER(COALESCE(r.status, 'ATIVO')) NOT IN ('INATIVO', 'EXCLUIDO', 'CANCELADO')
        ";
        if ($apenasRestauranteId !== null && $apenasRestauranteId > 0) {
            $sql .= ' AND r.id = :rid';
            $params['rid'] = $apenasRestauranteId;
        }
        $sql .= ' ORDER BY r.id ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $restaurantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resultado = [
            'executados' => 0,
            'falhas' => 0,
            'ignorados' => 0,
            'detalhes' => [],
        ];

        foreach ($restaurantes as $restaurante) {
            $restauranteId = (int)($restaurante['id'] ?? 0);
            if ($restauranteId <= 0) {
                continue;
            }

            // Filiais herdam o plano da matriz para gates de backup.
            $planoRid = $restauranteId;
            if ((int)($restaurante['is_matriz'] ?? 1) === 0) {
                $matrizId = (int)($restaurante['filial_id'] ?? 0);
                if ($matrizId > 0) {
                    $planoRid = $matrizId;
                }
            }

            $temAuto = function_exists('plano_tem_funcionalidade_db') && (
                plano_tem_funcionalidade_db($planoRid, 'backup_automatico')
                || plano_tem_funcionalidade_db($planoRid, 'backup_diario')
                || plano_tem_funcionalidade_db($planoRid, 'backup_hora')
            );

            if (!$temAuto) {
                $resultado['ignorados']++;
                continue;
            }

            $config = backup_obter_configuracao($db, $restauranteId);
            if (empty($config['id'])) {
                $defaultFreq = plano_tem_funcionalidade_db($planoRid, 'backup_hora') ? 'HORARIO' : 'DIARIO';
                $config = backup_salvar_configuracao($db, $restauranteId, [
                    'automatico' => 1,
                    'frequencia' => $defaultFreq,
                    'hora_execucao' => '00:00:00',
                    'retencao_dias' => 30,
                    'notificar_email' => 0,
                ]);
            }

            if (!backup_esta_pendente($config)) {
                $resultado['ignorados']++;
                $resultado['detalhes'][] = [
                    'restaurante_id' => $restauranteId,
                    'status' => 'skip',
                    'proximo' => $config['proximo_backup_em'] ?? null,
                ];
                continue;
            }

            $run = backup_executar_geracao($db, $restauranteId, $config, 'AUTOMATICO', $origem);
            if (!empty($run['success'])) {
                $resultado['executados']++;
                $resultado['detalhes'][] = [
                    'restaurante_id' => $restauranteId,
                    'status' => 'ok',
                    'historico_id' => $run['historico_id'] ?? null,
                ];
            } else {
                $resultado['falhas']++;
                $resultado['detalhes'][] = [
                    'restaurante_id' => $restauranteId,
                    'status' => 'erro',
                    'message' => $run['message'] ?? 'Erro desconhecido',
                ];
            }
        }

        return $resultado;
    }
}

if (!function_exists('backup_enviar_notificacao_email')) {
    /**
     * Envia email de confirmaÃ§Ã£o de backup para o restaurante, se houver email cadastrado.
     */
    function backup_enviar_notificacao_email(PDO $db, int $restauranteId, array $configAtual, array $dadosArquivo): void
    {
        $stmt = $db->prepare("SELECT nome, email FROM restaurantes WHERE id = ? LIMIT 1");
        $stmt->execute([$restauranteId]);
        $rest = $stmt->fetch(PDO::FETCH_ASSOC);
        $emailDestino = trim((string)($rest['email'] ?? ''));
        $restNome = trim((string)($rest['nome'] ?? 'Restaurante'));

        if ($emailDestino === '') {
            return;
        }

        $assunto = 'Backup concluÃ­do - ' . $restNome;
        $tamanho = backup_formatar_tamanho((int)($dadosArquivo['tamanho_bytes'] ?? 0));
        $quando = date('d/m/Y H:i');
        $tipo = strtoupper((string)($dadosArquivo['tipo'] ?? 'MANUAL'));
        $origem = strtoupper((string)($dadosArquivo['origem'] ?? 'WEB'));
        $arquivoNome = htmlspecialchars((string)($dadosArquivo['arquivo_nome'] ?? ''), ENT_QUOTES, 'UTF-8');
        $totalTabelas = (int)($dadosArquivo['total_tabelas'] ?? 0);
        $totalRegistros = (int)($dadosArquivo['total_registros'] ?? 0);


        $restNomeEsc = htmlspecialchars($restNome, ENT_QUOTES, 'UTF-8');
        $tipoEsc = htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8');
        $origemEsc = htmlspecialchars($origem, ENT_QUOTES, 'UTF-8');
        $quandoEsc = htmlspecialchars($quando, ENT_QUOTES, 'UTF-8');
        $tamanhoEsc = htmlspecialchars($tamanho, ENT_QUOTES, 'UTF-8');
        $retencaoDias = max(1, (int)($configAtual['retencao_dias'] ?? 30));
        $year = date('Y');

        $body = '<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>Backup concluido</title>
  <style>
    @media only screen and (max-width:620px) {
      .nivora-outer { padding:12px 8px !important; }
      .nivora-wrap { width:calc(100vw - 16px) !important; max-width:calc(100vw - 16px) !important; }
      .nivora-shell { width:100% !important; max-width:100% !important; border-radius:12px !important; }
      .nivora-pad { padding-left:20px !important; padding-right:20px !important; }
      .nivora-title { font-size:23px !important; }
      .backup-grid td { display:block !important; width:100% !important; box-sizing:border-box !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#292524;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Backup ' . $tipoEsc . ' concluido com sucesso para ' . $restNomeEsc . '.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f4f6;">
    <tr>
      <td align="center" class="nivora-outer" style="padding:32px 12px;">
        <div class="nivora-wrap" style="width:100%;max-width:620px;margin:0 auto;">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="nivora-shell" style="width:100%;table-layout:fixed;background:#ffffff;border:1px solid #e7e5e4;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(28,25,23,0.08);">
            <tr>
              <td class="nivora-pad" style="padding:22px 28px;background:#1c1917;border-bottom:4px solid #16a34a;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;">
                  <tr>
                    <td width="48" valign="middle">
                      <div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:10px;background:#16a34a;color:#ffffff;font-size:20px;font-weight:800;">N</div>
                    </td>
                    <td valign="middle" style="overflow-wrap:anywhere;">
                      <p style="margin:0;font-size:13px;line-height:1.2;letter-spacing:0.13em;text-transform:uppercase;color:#fff7ed;font-weight:800;">NIVORA RESTAURANT</p>
                      <p style="margin:5px 0 0;font-size:12px;line-height:1.3;color:#a8a29e;">Backup e continuidade operacional</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td class="nivora-pad" style="padding:32px 32px 14px;overflow-wrap:anywhere;">
                <p style="margin:0 0 10px;font-size:12px;line-height:1.3;letter-spacing:0.12em;text-transform:uppercase;color:#15803d;font-weight:800;">Backup concluido</p>
                <h1 class="nivora-title" style="margin:0 0 14px;font-size:26px;line-height:1.25;color:#1c1917;font-weight:800;">Os dados do restaurante foram protegidos</h1>
                <p style="margin:0;font-size:15px;line-height:1.65;color:#57534e;">O backup <strong style="color:#1c1917;">' . $tipoEsc . '</strong> do <strong style="color:#1c1917;">' . $restNomeEsc . '</strong> foi concluido com sucesso em <strong style="color:#1c1917;">' . $quandoEsc . '</strong>.</p>
              </td>
            </tr>
            <tr>
              <td class="nivora-pad" style="padding:18px 32px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:14px;">
                  <tr>
                    <td width="74" valign="middle" align="center" style="padding:18px 0 18px 18px;">
                      <div style="width:46px;height:46px;line-height:46px;text-align:center;border-radius:23px;background:#16a34a;color:#ffffff;font-size:18px;font-weight:800;">OK</div>
                    </td>
                    <td valign="middle" style="padding:18px;overflow-wrap:anywhere;">
                      <p style="margin:0 0 4px;font-size:15px;line-height:1.4;color:#14532d;font-weight:800;">Copia guardada com sucesso</p>
                      <p style="margin:0;font-size:13px;line-height:1.5;color:#166534;">Retencao configurada: ' . $retencaoDias . ' dia(s). Use o painel de Backup para descarregar ou restaurar quando necessario.</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td class="nivora-pad" style="padding:4px 32px 24px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="backup-grid" style="width:100%;border-collapse:separate;border-spacing:0 8px;">
                  <tr><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#78716c;font-size:12px;font-weight:700;">Arquivo</td><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#1c1917;font-size:13px;font-weight:700;overflow-wrap:anywhere;">' . $arquivoNome . '</td></tr>
                  <tr><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#78716c;font-size:12px;font-weight:700;">Tamanho</td><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#1c1917;font-size:13px;font-weight:700;">' . $tamanhoEsc . '</td></tr>
                  <tr><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#78716c;font-size:12px;font-weight:700;">Conteudo</td><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#1c1917;font-size:13px;font-weight:700;">' . $totalTabelas . ' tabela(s) e ' . $totalRegistros . ' registro(s)</td></tr>
                  <tr><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#78716c;font-size:12px;font-weight:700;">Origem</td><td style="padding:12px 14px;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;color:#1c1917;font-size:13px;font-weight:700;">' . $origemEsc . '</td></tr>
                </table>
                <p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:#78716c;"><strong style="color:#57534e;">Nota de seguranca:</strong> por protecao, o email nao mostra o caminho interno do servidor. O arquivo pode ser consultado em Configuracoes &gt; Backup.</p>
              </td>
            </tr>
            <tr>
              <td class="nivora-pad" style="padding:20px 32px;background:#1c1917;border-top:1px solid #292524;overflow-wrap:anywhere;">
                <p style="margin:0 0 5px;font-size:12px;line-height:1.4;color:#d6d3d1;font-weight:700;">Nivora Restaurant Â· Backup automatico</p>
                <p style="margin:0;font-size:11px;line-height:1.5;color:#78716c;">Email automatico. Nao responda a esta mensagem.<br>&copy; ' . $year . ' Nivora Restaurant. Todos os direitos reservados.</p>
              </td>
            </tr>
          </table>
        </div>
      </td>
    </tr>
  </table>
</body>
</html>';

        // Tentativa de envio; se falhar, apenas loga.
        if (!saas_enviar_email($emailDestino, $assunto, $body)) {
            $logPath = dirname(__DIR__, 2) . '/storage/logs/backup.log';
            @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . "] ERRO email backup restaurante #{$restauranteId} ({$emailDestino})\n", FILE_APPEND);
        }
    }
}

backup_ensure_tables_exist();
