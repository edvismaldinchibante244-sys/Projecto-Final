<?php

require_once __DIR__ . '/plano_check.php';
require_once __DIR__ . '/migration_state.php';
require_once __DIR__ . '/crypto.php';

if (!defined('RESERVA_QR_SCHEMA_MIGRATION_ID')) {
    define('RESERVA_QR_SCHEMA_MIGRATION_ID', '20260814_1420_reserva_qr');
}

if (!function_exists('reserva_qr_ensure_tables')) {
    function reserva_qr_ensure_tables(PDO $db): void
    {
        static $initialized = [];
        $key = spl_object_id($db);
        if (isset($initialized[$key])) {
            return;
        }

        if (!migration_esta_aplicada($db, RESERVA_QR_SCHEMA_MIGRATION_ID)) {
            $db->exec("
            CREATE TABLE IF NOT EXISTS reserva_qr_sessoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                mesa_id INT NULL,
                token VARCHAR(96) NULL UNIQUE,
                token_hash VARCHAR(128) NULL,
                token_cipher TEXT NULL,
                nome_atendente VARCHAR(120) NULL,
                telefone_cliente VARCHAR(40) NULL,
                observacoes VARCHAR(255) NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_restaurante_expires (restaurante_id, expires_at),
                INDEX idx_token_hash_expires (token_hash, expires_at),
                INDEX idx_token_expires (token, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

            $db->exec("
            CREATE TABLE IF NOT EXISTS reserva_pre_bloqueios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                mesa_id INT NOT NULL,
                token_sessao VARCHAR(96) NOT NULL,
                data_reserva DATE NOT NULL,
                hora_reserva TIME NOT NULL,
                quantidade_pessoas INT NOT NULL DEFAULT 1,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_restaurante_data_hora (restaurante_id, data_reserva, hora_reserva),
                INDEX idx_token_sessao (token_sessao),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        reserva_ensure_status_chegou($db);
        reserva_qr_ensure_secure_columns($db);
        $initialized[$key] = true;
    }
}

if (!function_exists('reserva_qr_ensure_secure_columns')) {
    function reserva_qr_ensure_secure_columns(PDO $db): void
    {
        static $done = [];
        $key = spl_object_id($db);
        if (isset($done[$key])) {
            return;
        }

        try {
            $stmt = $db->query("SHOW COLUMNS FROM reserva_qr_sessoes");
            $cols = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $col) {
                $cols[strtolower((string)($col['Field'] ?? ''))] = true;
            }

            if (empty($cols['token_hash'])) {
                $db->exec("ALTER TABLE reserva_qr_sessoes ADD COLUMN token_hash VARCHAR(128) NULL AFTER token");
            }
            if (empty($cols['token_cipher'])) {
                $db->exec("ALTER TABLE reserva_qr_sessoes ADD COLUMN token_cipher TEXT NULL AFTER token_hash");
            }
            try {
                $db->exec("ALTER TABLE reserva_qr_sessoes MODIFY token VARCHAR(96) NULL");
            } catch (Throwable $eToken) {
                // Older MySQL variants may reject MODIFY when constraints differ; keep legacy fallback working.
            }
            if (empty($cols['revoked_at'])) {
                $db->exec("ALTER TABLE reserva_qr_sessoes ADD COLUMN revoked_at DATETIME NULL AFTER consumed_at");
            }
            try {
                $db->exec("ALTER TABLE reserva_qr_sessoes ADD INDEX idx_token_hash_expires (token_hash, expires_at)");
            } catch (Throwable $eIdx) {
                // Index may already exist in newer installs.
            }
        } catch (Throwable $e) {
            error_log('[RESERVA_QR_SECURE_COLUMNS] ' . $e->getMessage());
        }

        $done[$key] = true;
    }
}

if (!function_exists('reserva_ensure_status_chegou')) {
    /**
     * Garante status do ciclo: pendente → confirmado → chegou → concluido.
     * A reserva só sai da lista ativa depois do pagamento (concluido).
     */
    function reserva_ensure_status_chegou(PDO $db): void
    {
        static $done = [];
        $key = spl_object_id($db);
        if (isset($done[$key])) {
            return;
        }
        $done[$key] = true;

        try {
            if (!migration_esta_aplicada($db, RESERVA_QR_SCHEMA_MIGRATION_ID)) {
                $col = $db->query("SHOW COLUMNS FROM reservas LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
                $type = strtolower((string)($col['Type'] ?? ''));
                if ($type !== '' && str_contains($type, 'enum(')) {
                    $needsChegou = !str_contains($type, "'chegou'");
                    $needsConcluido = !str_contains($type, "'concluido'");
                    if ($needsChegou || $needsConcluido) {
                        $db->exec("
                            ALTER TABLE reservas
                            MODIFY COLUMN status ENUM('pendente','confirmado','chegou','concluido','no-show','cancelado')
                            NULL DEFAULT 'pendente'
                        ");
                    }
                }
            }

            // Só corrige check-in antigo (confirmado + mesa ocupada), sem mexer em atualizado_em
            // para não conflitar com a deteção de pagamento pós check-in.
            $db->exec("
                UPDATE reservas r
                INNER JOIN mesas m ON m.id = r.mesa_atribuida AND m.restaurante_id = r.restaurante_id
                SET r.status = 'chegou'
                WHERE r.status = 'confirmado'
                  AND r.data_reserva = CURDATE()
                  AND UPPER(COALESCE(m.status, '')) = 'OCUPADA'
            ");

            // Só sincroniza reservas já em "No salão" (nunca fecha só por estar Confirmado).
            reserva_sincronizar_reservas_pagas($db);
        } catch (Throwable $e) {
            // Schema legado: não bloquear a página.
        }
    }
}

if (!function_exists('reserva_mesa_tem_pedido_aberto')) {
    function reserva_mesa_tem_pedido_aberto(PDO $db, int $restauranteId, int $mesaId): bool
    {
        if ($restauranteId <= 0 || $mesaId <= 0) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM pedidos
            WHERE restaurante_id = :rid
              AND mesa_id = :mesa_id
              AND UPPER(TRIM(COALESCE(status, ''))) NOT IN ('PAGO', 'CANCELADO')
        ");
        $stmt->execute([
            ':rid' => $restauranteId,
            ':mesa_id' => $mesaId,
        ]);

        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('reserva_concluir_por_mesa')) {
    /**
     * Conclui reserva ativa da mesa após pagamento / liberação.
     * A reserva só deve sair da lista ativa neste momento.
     */
    function reserva_concluir_por_mesa(PDO $db, int $restauranteId, int $mesaId): int
    {
        if ($restauranteId <= 0 || $mesaId <= 0) {
            return 0;
        }

        reserva_ensure_status_chegou($db);

        try {
            // Apenas "chegou": Confirmado (cliente ainda não chegou) não pode ser fechado aqui.
            $stmt = $db->prepare("
                UPDATE reservas
                SET status = 'concluido', atualizado_em = NOW()
                WHERE restaurante_id = :rid
                  AND mesa_atribuida = :mesa_id
                  AND data_reserva BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                  AND status = 'chegou'
            ");
            $stmt->execute([
                ':rid' => $restauranteId,
                ':mesa_id' => $mesaId,
            ]);
            return (int)$stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[reserva_concluir_por_mesa] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('reserva_liberar_mesa_apos_pagamento')) {
    /**
     * Libera mesa (se sem pedidos abertos) e conclui a reserva vinculada.
     */
    function reserva_liberar_mesa_apos_pagamento(PDO $db, int $restauranteId, int $mesaId): void
    {
        if ($restauranteId <= 0 || $mesaId <= 0) {
            return;
        }

        if (reserva_mesa_tem_pedido_aberto($db, $restauranteId, $mesaId)) {
            return;
        }

        try {
            $stmt = $db->prepare("
                UPDATE mesas
                SET status = 'LIVRE'
                WHERE id = :mesa_id
                  AND restaurante_id = :rid
                  AND UPPER(TRIM(COALESCE(status, ''))) <> 'LIVRE'
            ");
            $stmt->execute([
                ':mesa_id' => $mesaId,
                ':rid' => $restauranteId,
            ]);
        } catch (Throwable $e) {
            error_log('[reserva_liberar_mesa_apos_pagamento] ' . $e->getMessage());
        }

        reserva_concluir_por_mesa($db, $restauranteId, $mesaId);
    }
}

if (!function_exists('reserva_sincronizar_reservas_pagas')) {
    /**
     * Corrige estados presos:
     * - mesa já LIVRE com reserva ainda "No salão"
     * - pagamento (pedido/venda PAGO) depois do check-in, sem pedido aberto
     */
    function reserva_sincronizar_reservas_pagas(PDO $db, ?int $restauranteId = null): void
    {
        static $synced = false;
        if ($synced && $restauranteId === null) {
            return;
        }
        if ($restauranteId === null) {
            $synced = true;
        }

        try {
            $sql = "
                SELECT
                    r.id,
                    r.restaurante_id,
                    r.mesa_atribuida AS mesa_id,
                    COALESCE(r.atualizado_em, r.criado_em) AS checkin_em,
                    UPPER(TRIM(COALESCE(m.status, ''))) AS mesa_status
                FROM reservas r
                LEFT JOIN mesas m ON m.id = r.mesa_atribuida AND m.restaurante_id = r.restaurante_id
                WHERE r.status = 'chegou'
                  AND r.mesa_atribuida IS NOT NULL
                  AND r.data_reserva BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ";
            $params = [];
            if ($restauranteId !== null && $restauranteId > 0) {
                $sql .= " AND r.restaurante_id = :rid";
                $params[':rid'] = $restauranteId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtUltimoPagamento = $db->prepare("
                SELECT MAX(ts) FROM (
                    SELECT COALESCE(atualizado_em, criado_em) AS ts
                    FROM pedidos
                    WHERE restaurante_id = :rid AND mesa_id = :mesa_id
                      AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                    UNION ALL
                    SELECT criado_em AS ts
                    FROM vendas
                    WHERE restaurante_id = :rid2 AND mesa_id = :mesa_id2
                      AND UPPER(TRIM(COALESCE(status, ''))) = 'PAGO'
                ) pagamentos
            ");

            foreach ($rows as $row) {
                $rid = (int)($row['restaurante_id'] ?? 0);
                $mesaId = (int)($row['mesa_id'] ?? 0);
                if ($rid <= 0 || $mesaId <= 0) {
                    continue;
                }
                if (reserva_mesa_tem_pedido_aberto($db, $rid, $mesaId)) {
                    continue;
                }

                $mesaLivre = ((string)($row['mesa_status'] ?? '')) === 'LIVRE';
                $checkinEm = (string)($row['checkin_em'] ?? '');

                $stmtUltimoPagamento->execute([
                    ':rid' => $rid,
                    ':mesa_id' => $mesaId,
                    ':rid2' => $rid,
                    ':mesa_id2' => $mesaId,
                ]);
                $ultimoPagamento = (string)($stmtUltimoPagamento->fetchColumn() ?: '');

                $pagouDepoisDoCheckin = $ultimoPagamento !== ''
                    && $checkinEm !== ''
                    && strtotime($ultimoPagamento) >= strtotime($checkinEm);

                if ($mesaLivre || $pagouDepoisDoCheckin) {
                    reserva_liberar_mesa_apos_pagamento($db, $rid, $mesaId);
                }
            }
        } catch (Throwable $e) {
            error_log('[reserva_sincronizar_reservas_pagas] ' . $e->getMessage());
        }
    }
}

if (!function_exists('reserva_qr_cleanup')) {
    function reserva_qr_cleanup(PDO $db): void
    {
        reserva_qr_ensure_tables($db);
        $agora = reserva_qr_now_string();

        $stmtDeleteHolds = $db->prepare("DELETE FROM reserva_pre_bloqueios WHERE expires_at <= :agora");
        $stmtDeleteHolds->execute([':agora' => $agora]);

        $stmtDeleteSessions = $db->prepare("DELETE FROM reserva_qr_sessoes WHERE expires_at <= :agora AND consumed_at IS NULL");
        $stmtDeleteSessions->execute([':agora' => $agora]);
    }
}

if (!function_exists('reserva_qr_now_string')) {
    function reserva_qr_now_string(): string
    {
        // Os DATETIME das sessoes QR sao persistidos em UTC. Nao dependa do
        // timezone do php.ini/hosting, pois isso pode expirar um QR antes da hora.
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}

if (!function_exists('reserva_qr_coluna_existe')) {
    function reserva_qr_coluna_existe(PDO $db, string $tabela, string $coluna): bool
    {
        static $cache = [];
        $k = spl_object_id($db) . ':' . $tabela . '.' . $coluna;
        if (!array_key_exists($k, $cache)) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
            $stmt->execute([':t' => $tabela, ':c' => $coluna]);
            $cache[$k] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$k];
    }
}

if (!function_exists('reserva_qr_ligar_reserva_sessao')) {
    /**
     * Guarda que a reserva veio desta sessao QR e liga-lhe os pre-pedidos ja feitos
     * com o mesmo QR (migracao 20261101_1000_reserva_pre_pedido; sem ela nao faz nada).
     */
    function reserva_qr_ligar_reserva_sessao(PDO $db, int $restauranteId, int $reservaId, int $sessaoId, string $token): void
    {
        if ($reservaId <= 0 || $sessaoId <= 0) {
            return;
        }
        if (reserva_qr_coluna_existe($db, 'reservas', 'qr_sessao_id')) {
            $db->prepare('UPDATE reservas SET qr_sessao_id = :s WHERE id = :id AND restaurante_id = :rid')
                ->execute([':s' => $sessaoId, ':id' => $reservaId, ':rid' => $restauranteId]);
        }
        if ($token !== '' && reserva_qr_coluna_existe($db, 'pedido_qr_meta', 'reserva_id')) {
            $db->prepare('UPDATE pedido_qr_meta SET reserva_id = :r WHERE restaurante_id = :rid AND token = :token AND reserva_id IS NULL')
                ->execute([':r' => $reservaId, ':rid' => $restauranteId, ':token' => $token]);
        }
    }
}

if (!function_exists('reserva_qr_reserva_da_sessao')) {
    /** Reserva ativa criada por esta sessao QR (para ligar o pre-pedido), ou 0. */
    function reserva_qr_reserva_da_sessao(PDO $db, int $restauranteId, int $sessaoId): int
    {
        if ($sessaoId <= 0 || !reserva_qr_coluna_existe($db, 'reservas', 'qr_sessao_id')) {
            return 0;
        }
        $stmt = $db->prepare("SELECT id FROM reservas WHERE restaurante_id = :rid AND qr_sessao_id = :s AND status IN ('pendente', 'confirmado', 'chegou') ORDER BY id DESC LIMIT 1");
        $stmt->execute([':rid' => $restauranteId, ':s' => $sessaoId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('reserva_qr_utc_para_local')) {
    /**
     * As datas do QR (expires_at, consumed_at, pre-bloqueios) estao em UTC.
     * Para mostrar ao cliente converte-se para o fuso da aplicacao (APP_TIMEZONE, TZ ou Africa/Maputo).
     */
    function reserva_qr_utc_para_local($valor, string $formato = 'd/m/Y H:i'): string
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }
        try {
            $nome = trim((string)(getenv('APP_TIMEZONE') ?: getenv('TZ') ?: 'Africa/Maputo'));
            try {
                $local = new DateTimeZone($nome);
            } catch (Throwable $e) {
                $local = new DateTimeZone('Africa/Maputo');
            }
            $dt = new DateTimeImmutable($valor, new DateTimeZone('UTC'));
            return $dt->setTimezone($local)->format($formato);
        } catch (Throwable $e) {
            return $valor;
        }
    }
}

if (!function_exists('reserva_qr_generate_token')) {
    function reserva_qr_generate_token(): string
    {
        return bin2hex(random_bytes(24));
    }
}

if (!function_exists('reserva_qr_legacy_plaintext_compat_enabled')) {
    /**
     * Compatibilidade temporária e opt-in. Nunca habilitar permanentemente:
     * permite somente leitura/consulta de registros legados já existentes.
     */
    function reserva_qr_legacy_plaintext_compat_enabled(): bool
    {
        return in_array(
            strtolower(trim((string)(getenv('RESERVA_QR_ALLOW_LEGACY_PLAINTEXT_TOKEN') ?: '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}

if (!function_exists('reserva_qr_hold_legacy_plaintext_compat_enabled')) {
    /**
     * Fallback temporário para pré-bloqueios criados antes do hashing.
     * Novas gravações permanecem sempre em hash, independentemente desta flag.
     */
    function reserva_qr_hold_legacy_plaintext_compat_enabled(): bool
    {
        return in_array(
            strtolower(trim((string)(getenv('RESERVA_QR_ALLOW_LEGACY_HOLD_TOKEN') ?: '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}

if (!function_exists('reserva_qr_hold_token_hash')) {
    function reserva_qr_hold_token_hash(string $token): string
    {
        return security_hash_secret('reserva_qr_hold:' . trim($token));
    }
}

if (!function_exists('reserva_qr_session_plain_token')) {
    function reserva_qr_session_plain_token(array $sessao): string
    {
        $cipher = trim((string)($sessao['token_cipher'] ?? ''));
        if ($cipher !== '' && function_exists('security_decrypt_value')) {
            try {
                $decrypted = security_decrypt_value($cipher);
                return trim((string)$decrypted);
            } catch (\Throwable $e) {
                error_log('[RESERVA_QR_TOKEN_DECRYPT][ERROR] ' . $e->getMessage());
            }
        }

        if (reserva_qr_legacy_plaintext_compat_enabled()) {
            return trim((string)($sessao['token'] ?? ''));
        }

        return '';
    }
}

if (!function_exists('reserva_qr_base_public_url')) {
    function reserva_qr_base_public_url(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $baseDir = preg_replace('#/api$#', '', rtrim(dirname($scriptName), '/'));

        return $scheme . '://' . $host . $baseDir;
    }
}

if (!function_exists('reserva_qr_build_public_url')) {
    function reserva_qr_build_public_url(string $token): string
    {
        return reserva_qr_base_public_url() . '/reserva_qr.php?token=' . urlencode($token);
    }
}

if (!function_exists('reserva_qr_build_cardapio_url')) {
    function reserva_qr_build_cardapio_url(string $token): string
    {
        return reserva_qr_base_public_url() . '/cardapio.php?token=' . urlencode($token);
    }
}

if (!function_exists('reserva_qr_create_session')) {
    function reserva_qr_create_session(PDO $db, int $restauranteId, array $dados = []): array
    {
        reserva_qr_cleanup($db);

        if ($restauranteId <= 0) {
            return [
                'success' => false,
                'message' => 'Restaurante invalido para gerar a sessao QR.',
            ];
        }

        $mesaId = !empty($dados['mesa_id']) ? (int)$dados['mesa_id'] : null;

        if (function_exists('plano_get_dados') && function_exists('plano_tem_funcionalidade_db')) {
            $dadosPlano = plano_get_dados($restauranteId);
            $planoNome = strtoupper((string)($dadosPlano['plano_nome'] ?? 'BASICO'));
            // Sessões QR de cardápio/pedidos: pedidos_online (PRO+)
            // Reservas públicas: reservas_qr (EMP)
            $temPedidosOnline = plano_tem_funcionalidade_db($restauranteId, 'pedidos_online');
            $temReservaPublica = plano_tem_funcionalidade_db($restauranteId, 'reservas_qr');
            $obs = strtolower(trim((string)($dados['observacoes'] ?? '')));
            $modoReserva = (strpos($obs, 'reserva') !== false) && (strpos($obs, 'cardapio') === false);
            if ($modoReserva) {
                if (!$temReservaPublica) {
                    return [
                        'success' => false,
                        'message' => 'As reservas públicas só estão disponíveis para o plano Empresarial.',
                    ];
                }
            } elseif (!$temPedidosOnline && !$temReservaPublica) {
                return [
                    'success' => false,
                    'message' => 'Pedidos online por QR Code disponíveis a partir do plano Profissional.',
                ];
            }
        }

        $nomeAtendente = trim((string)($dados['nome_atendente'] ?? ''));
        $telefoneCliente = trim((string)($dados['telefone_cliente'] ?? ''));
        $observacoes = trim((string)($dados['observacoes'] ?? ''));
        $minutos = (int)($dados['expira_em_minutos'] ?? 1440);
        $obsModo = strtolower($observacoes);
        $isCardapioMesa = strpos($obsModo, 'cardapio_publico') !== false;
        $isReservaCheckin = strpos($obsModo, 'reserva_qr_checkin') !== false;
        $maxMinutos = $isCardapioMesa ? 5256000 : ($isReservaCheckin ? 1440 : 10080);
        $minutos = max(3, min($maxMinutos, $minutos));
        $token = reserva_qr_generate_token();
        $tokenHash = security_hash_secret('reserva_qr:' . $token);
        $tokenCipher = security_encrypt_value($token);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $minutos . ' minutes')
            ->format('Y-m-d H:i:s');

        try {
            $stmt = $db->prepare("
                INSERT INTO reserva_qr_sessoes (
                    restaurante_id, mesa_id, token, token_hash, token_cipher, nome_atendente, telefone_cliente, observacoes, expires_at
                ) VALUES (
                    :restaurante_id, :mesa_id, :token, :token_hash, :token_cipher, :nome_atendente, :telefone_cliente, :observacoes, :expires_at
                )
            ");
            $stmt->execute([
                ':restaurante_id' => $restauranteId,
                ':mesa_id' => $mesaId,
                ':token' => null,
                ':token_hash' => $tokenHash,
                ':token_cipher' => $tokenCipher,
                ':nome_atendente' => $nomeAtendente !== '' ? $nomeAtendente : null,
                ':telefone_cliente' => $telefoneCliente !== '' ? $telefoneCliente : null,
                ':observacoes' => $observacoes !== '' ? $observacoes : null,
                ':expires_at' => $expiresAt,
            ]);

            return [
                'success' => true,
                'id' => (int)$db->lastInsertId(),
                'token' => $token,
                'expires_at' => $expiresAt,
                'url' => strpos($obsModo, 'cardapio_publico') !== false
                    ? reserva_qr_build_cardapio_url($token)
                    : reserva_qr_build_public_url($token),
                'mesa_id' => $mesaId,
            ];
        } catch (\Throwable $e) {
            error_log('[RESERVA_QR_CREATE_SESSION][ERROR] ' . $e->getMessage() . ' | Dados: ' . json_encode([
                'restaurante_id' => $restauranteId,
                'mesa_id' => $mesaId,
                'nome_atendente' => $nomeAtendente,
                'telefone_cliente' => $telefoneCliente,
                'observacoes' => $observacoes,
                'expira_em_minutos' => $minutos,
                'token_hash_prefix' => substr($tokenHash, 0, 12),
                'expires_at' => $expiresAt,
            ]));
            return [
                'success' => false,
                'message' => 'Erro ao criar a sessao QR. Consulte o log para detalhes.',
            ];
        }
    }
}

if (!function_exists('reserva_qr_get_or_create_cardapio_geral_url')) {
    function reserva_qr_get_or_create_cardapio_geral_url(PDO $db, int $restauranteId): ?string
    {
        if ($restauranteId <= 0) {
            return null;
        }

        reserva_qr_cleanup($db);
        $agora = reserva_qr_now_string();

        try {
            $stmt = $db->prepare("
                SELECT token, token_cipher
                FROM reserva_qr_sessoes
                WHERE restaurante_id = :rid
                  AND mesa_id IS NULL
                  AND observacoes = 'cardapio_publico_geral'
                  AND expires_at > :agora
                  AND consumed_at IS NULL
                  AND revoked_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':rid' => $restauranteId,
                ':agora' => $agora,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $token = reserva_qr_session_plain_token($row);
                if ($token !== '') {
                    return reserva_qr_build_cardapio_url($token);
                }
            }
        } catch (Throwable $e) {
            error_log('[RESERVA_QR_CARDAPIO_GERAL_REUSE] ' . $e->getMessage());
        }

        $sessao = reserva_qr_create_session($db, $restauranteId, [
            'expira_em_minutos' => 5256000,
            'observacoes' => 'cardapio_publico_geral',
        ]);

        return !empty($sessao['success']) && !empty($sessao['url']) ? (string)$sessao['url'] : null;
    }
}

if (!function_exists('reserva_qr_get_or_create_cardapio_mesa_url')) {
    function reserva_qr_get_or_create_cardapio_mesa_url(PDO $db, int $restauranteId, int $mesaId): ?string
    {
        if ($restauranteId <= 0 || $mesaId <= 0) {
            return null;
        }

        reserva_qr_cleanup($db);
        $agora = reserva_qr_now_string();

        try {
            $stmt = $db->prepare("
                SELECT token, token_cipher
                FROM reserva_qr_sessoes
                WHERE restaurante_id = :rid
                  AND mesa_id = :mesa_id
                  AND observacoes = 'cardapio_publico_mesa'
                  AND expires_at > :agora
                  AND consumed_at IS NULL
                  AND revoked_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':rid' => $restauranteId,
                ':mesa_id' => $mesaId,
                ':agora' => $agora,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $token = reserva_qr_session_plain_token($row);
                if ($token !== '') {
                    return reserva_qr_build_cardapio_url($token);
                }
            }
        } catch (Throwable $e) {
            error_log('[RESERVA_QR_CARDAPIO_REUSE] ' . $e->getMessage());
        }

        $sessao = reserva_qr_create_session($db, $restauranteId, [
            'mesa_id' => $mesaId,
            'expira_em_minutos' => 5256000,
            'observacoes' => 'cardapio_publico_mesa',
        ]);

        return !empty($sessao['success']) && !empty($sessao['url']) ? (string)$sessao['url'] : null;
    }
}

if (!function_exists('reserva_qr_revoke_cardapio_mesa')) {
    function reserva_qr_revoke_cardapio_mesa(PDO $db, int $restauranteId, int $mesaId): bool
    {
        if ($restauranteId <= 0 || $mesaId <= 0) {
            return false;
        }
        $stmt = $db->prepare("
            UPDATE reserva_qr_sessoes
            SET revoked_at = NOW(), updated_at = CURRENT_TIMESTAMP
            WHERE restaurante_id = :rid
              AND mesa_id = :mesa_id
              AND observacoes = 'cardapio_publico_mesa'
              AND revoked_at IS NULL
        ");
        $stmt->execute([':rid' => $restauranteId, ':mesa_id' => $mesaId]);
        return $stmt->rowCount() >= 0;
    }
}

if (!function_exists('reserva_qr_get_session_by_token')) {
    function reserva_qr_get_session_by_token(PDO $db, string $token, bool $allowConsumed = false): ?array
    {
        reserva_qr_cleanup($db);

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $agora = reserva_qr_now_string();
        $tokenHash = security_hash_secret('reserva_qr:' . $token);
        $legacySql = reserva_qr_legacy_plaintext_compat_enabled() ? " OR s.token = :token" : "";

        $stmt = $db->prepare("
            SELECT
                s.*,
                r.nome AS restaurante_nome,
                r.cidade AS restaurante_cidade,
                r.logo AS restaurante_logo,
                m.numero AS mesa_numero,
                m.capacidade AS mesa_capacidade
            FROM reserva_qr_sessoes s
            INNER JOIN restaurantes r ON r.id = s.restaurante_id
            LEFT JOIN mesas m ON m.id = s.mesa_id AND m.restaurante_id = s.restaurante_id
            WHERE (s.token_hash = :token_hash{$legacySql})
              AND s.expires_at > :agora
              AND s.revoked_at IS NULL
              AND (:allow_consumed = 1 OR s.consumed_at IS NULL)
            LIMIT 1
        ");
        $params = [
            ':token_hash' => $tokenHash,
            ':agora' => $agora,
            ':allow_consumed' => $allowConsumed ? 1 : 0,
        ];
        if (reserva_qr_legacy_plaintext_compat_enabled()) {
            $params[':token'] = $token;
        }
        $stmt->execute($params);
        $sessao = $stmt->fetch(PDO::FETCH_ASSOC);

        return $sessao ?: null;
    }
}

if (!function_exists('reserva_qr_get_hold_by_token')) {
    function reserva_qr_get_hold_by_token(PDO $db, string $token): ?array
    {
        reserva_qr_cleanup($db);

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $agora = reserva_qr_now_string();
        $tokenHash = reserva_qr_hold_token_hash($token);
        $legacySql = reserva_qr_hold_legacy_plaintext_compat_enabled() ? " OR pb.token_sessao = :token" : "";

        $stmt = $db->prepare("
            SELECT pb.*, m.numero AS mesa_numero, m.capacidade AS mesa_capacidade
            FROM reserva_pre_bloqueios pb
            INNER JOIN mesas m ON m.id = pb.mesa_id
            WHERE (pb.token_sessao = :token_hash{$legacySql})
              AND pb.expires_at > :agora
            ORDER BY pb.id DESC
            LIMIT 1
        ");
        $params = [
            ':token_hash' => $tokenHash,
            ':agora' => $agora,
        ];
        if (reserva_qr_hold_legacy_plaintext_compat_enabled()) {
            $params[':token'] = $token;
        }
        $stmt->execute($params);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        return $hold ?: null;
    }
}

if (!function_exists('reserva_qr_mark_session_consumed')) {
    function reserva_qr_mark_session_consumed(PDO $db, string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        $tokenHash = security_hash_secret('reserva_qr:' . $token);
        $legacySql = reserva_qr_legacy_plaintext_compat_enabled() ? " OR token = :token" : "";
        $stmt = $db->prepare("
            UPDATE reserva_qr_sessoes
            SET consumed_at = NOW(), updated_at = CURRENT_TIMESTAMP
            WHERE (token_hash = :token_hash{$legacySql})
              AND consumed_at IS NULL
        ");
        $params = [':token_hash' => $tokenHash];
        if (reserva_qr_legacy_plaintext_compat_enabled()) {
            $params[':token'] = $token;
        }
        $stmt->execute($params);
    }
}
