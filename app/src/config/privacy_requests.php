<?php

require_once __DIR__ . '/legal_documents.php';

if (!function_exists('privacy_request_operational_deadline_days')) {
    function privacy_request_operational_deadline_days(): int
    {
        $configured = (int)(getenv('PRIVACY_OPERATIONAL_DEADLINE_DAYS') ?: 30);
        return min(90, max(7, $configured));
    }
}

if (!function_exists('privacy_request_operational_warning_days')) {
    function privacy_request_operational_warning_days(): int
    {
        $configured = (int)(getenv('PRIVACY_OPERATIONAL_WARNING_DAYS') ?: 7);
        return min(30, max(1, $configured));
    }
}

if (!function_exists('privacy_request_operational_timing')) {
    function privacy_request_operational_timing(array $request, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $deadlineDays = privacy_request_operational_deadline_days();
        $warningDays = privacy_request_operational_warning_days();
        $closed = in_array(strtoupper((string)($request['status'] ?? '')), ['CONCLUIDO', 'RECUSADO'], true);

        try {
            $createdAt = new DateTimeImmutable((string)($request['created_at'] ?? 'now'));
        } catch (Throwable $e) {
            $createdAt = $now;
        }

        $dueAt = $createdAt->modify('+' . $deadlineDays . ' days');
        $secondsRemaining = $dueAt->getTimestamp() - $now->getTimestamp();
        $daysRemaining = (int)ceil($secondsRemaining / 86400);

        if ($closed) {
            $state = 'ENCERRADO';
        } elseif ($secondsRemaining < 0) {
            $state = 'VENCIDO';
        } elseif ($daysRemaining <= $warningDays) {
            $state = 'PROXIMO_PRAZO';
        } else {
            $state = 'NO_PRAZO';
        }

        $labels = [
            'ENCERRADO' => 'Encerrado',
            'VENCIDO' => 'Prazo operacional vencido',
            'PROXIMO_PRAZO' => 'Proximo do prazo operacional',
            'NO_PRAZO' => 'Dentro do prazo operacional',
        ];

        return [
            'deadline_days' => $deadlineDays,
            'warning_days' => $warningDays,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'days_remaining' => $daysRemaining,
            'state' => $state,
            'label' => $labels[$state] ?? $state,
        ];
    }
}

if (!function_exists('privacy_request_ensure_schema')) {
    function privacy_request_ensure_schema(PDO $db): void
    {
        legal_ensure_schema($db);

        $columns = [];
        foreach ($db->query('SHOW COLUMNS FROM data_subject_requests')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string)$column['Field']] = true;
        }

        $requiredColumns = [
            'identity_verified_at' => 'DATETIME NULL AFTER handled_by',
            'identity_verified_by' => 'INT NULL AFTER identity_verified_at',
            'export_generated_at' => 'DATETIME NULL AFTER receipt_sent_at',
            // Confirmacao da identidade pelo proprio titular (link enviado para o email da conta).
            'verify_token_hash' => 'CHAR(64) NULL',
            'verify_sent_at' => 'DATETIME NULL',
            'verify_expires_at' => 'DATETIME NULL',
            'verified_via' => 'VARCHAR(20) NULL',
            // Entrega dos dados ao titular por link temporario.
            'delivery_token_hash' => 'CHAR(64) NULL',
            'delivery_sent_at' => 'DATETIME NULL',
            'delivery_expires_at' => 'DATETIME NULL',
            'delivery_opened_at' => 'DATETIME NULL',
        ];
        foreach ($requiredColumns as $column => $definition) {
            if (!isset($columns[$column])) {
                $db->exec('ALTER TABLE data_subject_requests ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS data_subject_request_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id BIGINT UNSIGNED NOT NULL,
            actor_user_id INT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_detail TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dsr_event_request (request_id, created_at),
            INDEX idx_dsr_event_type (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('privacy_request_record_event')) {
    function privacy_request_record_event(PDO $db, int $requestId, ?int $actorUserId, string $eventType, string $detail = ''): void
    {
        $eventType = strtoupper(trim($eventType));
        if ($requestId <= 0 || $eventType === '') {
            throw new InvalidArgumentException('Evento de privacidade invalido.');
        }

        $stmt = $db->prepare('INSERT INTO data_subject_request_events (request_id, actor_user_id, event_type, event_detail, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([
            $requestId,
            $actorUserId ?: null,
            substr($eventType, 0, 50),
            $detail === '' ? null : personal_data_encrypt(substr($detail, 0, 3000)),
        ]);
    }
}

if (!function_exists('privacy_request_find_subjects')) {
    function privacy_request_find_subjects(PDO $db, string $emailLookup): array
    {
        $matches = ['users' => [], 'restaurants' => []];
        if (!preg_match('/^[a-f0-9]{64}$/', $emailLookup)) {
            return $matches;
        }

        $users = $db->prepare('SELECT id, restaurante_id, nome, email, perfil, ativo, criado_em, created_at, ultimo_acesso FROM usuarios WHERE email_lookup = ? ORDER BY id');
        $users->execute([$emailLookup]);
        $matches['users'] = $users->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $restaurants = $db->prepare('SELECT id, nome, email, telefone, endereco, cidade, nuit, plano, status, criado_em, created_at FROM restaurantes WHERE email_lookup = ? ORDER BY id');
        $restaurants->execute([$emailLookup]);
        $matches['restaurants'] = $restaurants->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $matches;
    }
}

if (!function_exists('privacy_request_table_exists')) {
    function privacy_request_table_exists(PDO $db, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $cache[$table] = (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('privacy_request_activity_summary')) {
    function privacy_request_activity_summary(PDO $db, array $users): array
    {
        $summary = [];
        foreach ($users as $user) {
            $userId = (int)($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $row = [
                'usuario_id' => $userId,
                'pedidos_como_garcom' => 0,
                'vendas_registadas' => 0,
                'turnos_registados' => 0,
            ];

            if (privacy_request_table_exists($db, 'pedidos')) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM pedidos WHERE garcom_id = ?');
                $stmt->execute([$userId]);
                $row['pedidos_como_garcom'] = (int)$stmt->fetchColumn();
            }
            if (privacy_request_table_exists($db, 'vendas')) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM vendas WHERE usuario_id = ?');
                $stmt->execute([$userId]);
                $row['vendas_registadas'] = (int)$stmt->fetchColumn();
            }
            if (privacy_request_table_exists($db, 'funcionarios_turnos')) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM funcionarios_turnos WHERE usuario_id = ?');
                $stmt->execute([$userId]);
                $row['turnos_registados'] = (int)$stmt->fetchColumn();
            }

            $summary[] = $row;
        }

        return $summary;
    }
}

if (!function_exists('privacy_request_build_export')) {
    function privacy_request_build_export(PDO $db, array $request): array
    {
        $emailLookup = (string)($request['email_lookup'] ?? '');
        $subjects = privacy_request_find_subjects($db, $emailLookup);

        $acceptanceStmt = $db->prepare('SELECT contexto, terms_version, privacy_version, cookie_version, accepted_at FROM legal_acceptances WHERE email_lookup = ? ORDER BY accepted_at DESC');
        $acceptanceStmt->execute([$emailLookup]);

        return [
            'schema_version' => '1.0',
            'generated_at_utc' => gmdate('c'),
            'request' => [
                'reference' => (string)($request['public_id'] ?? ''),
                'type' => (string)($request['request_type'] ?? ''),
                'status' => (string)($request['status'] ?? ''),
                'submitted_at' => (string)($request['created_at'] ?? ''),
            ],
            'data_subject' => [
                'name_supplied' => (string)($request['nome'] ?? ''),
                'email_supplied' => (string)($request['email'] ?? ''),
                'restaurant_supplied' => (string)($request['restaurante'] ?? ''),
            ],
            'accounts' => [
                'users' => array_map(static fn(array $user): array => [
                    'id' => (int)($user['id'] ?? 0),
                    'restaurant_id' => (int)($user['restaurante_id'] ?? 0),
                    'name' => (string)($user['nome'] ?? ''),
                    'email' => (string)($user['email'] ?? ''),
                    'role' => (string)($user['perfil'] ?? ''),
                    'active' => (bool)($user['ativo'] ?? false),
                    'created_at' => (string)($user['criado_em'] ?? $user['created_at'] ?? ''),
                    'last_access_at' => (string)($user['ultimo_acesso'] ?? ''),
                ], $subjects['users']),
                'restaurants' => array_map(static fn(array $restaurant): array => [
                    'id' => (int)($restaurant['id'] ?? 0),
                    'name' => (string)($restaurant['nome'] ?? ''),
                    'email' => (string)($restaurant['email'] ?? ''),
                    'phone' => (string)($restaurant['telefone'] ?? ''),
                    'address' => (string)($restaurant['endereco'] ?? ''),
                    'city' => (string)($restaurant['cidade'] ?? ''),
                    'nuit' => (string)($restaurant['nuit'] ?? ''),
                    'plan' => (string)($restaurant['plano'] ?? ''),
                    'status' => (string)($restaurant['status'] ?? ''),
                    'created_at' => (string)($restaurant['criado_em'] ?? $restaurant['created_at'] ?? ''),
                ], $subjects['restaurants']),
            ],
            'activity_summary' => privacy_request_activity_summary($db, $subjects['users']),
            'legal_acceptances' => $acceptanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'retention_notice' => 'Credenciais, hashes, palavras-passe, tokens e segredos de seguranca nao fazem parte deste ficheiro. Registos financeiros e operacionais podem estar sujeitos a obrigacoes de conservacao.',
        ];
    }
}

if (!function_exists('privacy_request_base_url')) {
    /** Endereco publico do site (APP_URL no .env, ou o endereco atual). */
    function privacy_request_base_url(): string
    {
        $env = trim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')));
        if ($env !== '') {
            return rtrim($env, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return $https . '://' . $host . $dir;
    }

    /** Cria um token aleatorio, guarda so o hash e devolve o token (vai no link). */
    function privacy_request_new_token(PDO $db, int $requestId, string $tipo, int $horas): string
    {
        $token = bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);
        if ($tipo === 'verify') {
            $db->prepare('UPDATE data_subject_requests SET verify_token_hash = ?, verify_sent_at = NOW(), verify_expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?')
                ->execute([$hash, $horas, $requestId]);
        } else {
            $db->prepare('UPDATE data_subject_requests SET delivery_token_hash = ?, delivery_sent_at = NOW(), delivery_expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?')
                ->execute([$hash, $horas, $requestId]);
        }
        return $token;
    }

    /** Procura o pedido pelo par (referencia, token). Devolve null se nao valer ou tiver expirado. */
    function privacy_request_find_by_token(PDO $db, string $publicId, string $token, string $tipo): ?array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/', $publicId) || !preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $col = $tipo === 'verify' ? 'verify' : 'delivery';
        $s = $db->prepare("SELECT * FROM data_subject_requests WHERE public_id = ? AND {$col}_token_hash IS NOT NULL AND {$col}_expires_at > NOW() LIMIT 1");
        $s->execute([$publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row[$col . '_token_hash'], hash('sha256', $token))) {
            return null;
        }
        return $row;
    }

    /** Email para onde se envia a confirmacao: o da conta encontrada (prova que controla esse email). */
    function privacy_request_contact_email(PDO $db, array $request): string
    {
        $email = (string)(personal_data_decrypt($request['email'] ?? null) ?? '');
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Pagina legivel (HTML para imprimir/guardar em PDF) a partir do dossie. */
    function privacy_request_render_html(array $d, bool $paraTitular): string
    {
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '—';
        $perfis = ['ADMIN' => 'Administrador', 'GERENTE' => 'Gerente', 'CAIXA' => 'Caixa', 'GARCOM' => 'Garçom', 'COZINHA' => 'Cozinha', 'BAR' => 'Bar'];
        $planos = ['BASICO' => 'Básico', 'PROFISSIONAL' => 'Profissional', 'EMPRESARIAL' => 'Empresarial'];
        $linha = static fn(string $k, $v) => '<tr><th>' . $e($k) . '</th><td>' . ($v === '' || $v === null ? '<span class="vazio">não registado</span>' : $e($v)) . '</td></tr>';
        $h = '<section><h2>O seu pedido</h2><table>'
            . $linha('Referência', $d['request']['reference'] ?? '')
            . $linha('Recebido em', $data($d['request']['submitted_at'] ?? ''))
            . $linha('Nome indicado', $d['data_subject']['name_supplied'] ?? '')
            . $linha('Email indicado', $d['data_subject']['email_supplied'] ?? '')
            . '</table></section>';
        $users = $d['accounts']['users'] ?? [];
        $h .= '<section><h2>Contas de acesso ao sistema</h2>';
        if (!$users) {
            $h .= '<p class="vazio">Não encontrámos nenhuma conta de acesso com este email.</p>';
        }
        foreach ($users as $u) {
            $h .= '<table>' . $linha('Nome', $u['name'] ?? '') . $linha('Email', $u['email'] ?? '') . $linha('Função', $perfis[strtoupper((string)($u['role'] ?? ''))] ?? ($u['role'] ?? ''))
                . $linha('Estado', !empty($u['active']) ? 'Ativa' : 'Desativada') . $linha('Criada em', $data($u['created_at'] ?? '')) . $linha('Último acesso', $data($u['last_access_at'] ?? '')) . '</table>';
        }
        $h .= '</section>';
        $rests = $d['accounts']['restaurants'] ?? [];
        if ($rests) {
            $h .= '<section><h2>Restaurantes registados com este email</h2>';
            foreach ($rests as $r) {
                $h .= '<table>' . $linha('Nome', $r['name'] ?? '') . $linha('Email', $r['email'] ?? '') . $linha('Telefone', $r['phone'] ?? '')
                    . $linha('Morada', trim(($r['address'] ?? '') . (($r['city'] ?? '') !== '' ? ', ' . $r['city'] : ''))) . $linha('NUIT', $r['nuit'] ?? '')
                    . $linha('Plano', $planos[strtoupper((string)($r['plan'] ?? ''))] ?? ($r['plan'] ?? '')) . $linha('Registado em', $data($r['created_at'] ?? '')) . '</table>';
            }
            $h .= '</section>';
        }
        $act = $d['activity_summary'] ?? [];
        if ($act) {
            $h .= '<section><h2>Resumo da atividade no sistema</h2><table>';
            foreach ($act as $a) {
                $h .= $linha('Pedidos registados (como empregado de mesa)', (string)($a['pedidos_como_garcom'] ?? 0))
                    . $linha('Vendas registadas', (string)($a['vendas_registadas'] ?? 0))
                    . $linha('Turnos de trabalho', (string)($a['turnos_registados'] ?? 0));
            }
            $h .= '</table><p class="nota">Os pedidos e vendas em si pertencem ao restaurante onde trabalha e não são incluídos aqui.</p></section>';
        }
        $acc = $d['legal_acceptances'] ?? [];
        $h .= '<section><h2>Aceitação dos Termos e da Privacidade</h2>' . ($acc ? '' : '<p class="vazio">Sem registos.</p>');
        foreach ($acc as $a) {
            $h .= '<table>' . $linha('Aceite em', $data($a['accepted_at'] ?? '')) . $linha('Versão dos Termos', $a['terms_version'] ?? '') . $linha('Versão da Privacidade', $a['privacy_version'] ?? '') . '</table>';
        }
        $h .= '</section>';
        $titulo = $paraTitular ? 'Os seus dados pessoais' : 'Dados do titular — ' . ($d['data_subject']['name_supplied'] ?? '');
        $gerado = $data($d['generated_at_utc'] ?? date('c'));
        return '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . $e($titulo) . '</title>'
            . '<style>body{font-family:Arial,Helvetica,sans-serif;color:#1f2937;max-width:820px;margin:0 auto;padding:28px 20px;line-height:1.5}h1{font-size:24px;margin:0 0 4px}h2{font-size:16px;margin:26px 0 8px;padding-bottom:4px;border-bottom:2px solid #f59e0b}'
            . 'table{width:100%;border-collapse:collapse;margin:0 0 12px}th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top}th{width:38%;color:#4b5563;font-weight:600}.vazio{color:#9ca3af}.nota{font-size:12.5px;color:#6b7280}'
            . '.topo{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}.acoes a,.acoes button{display:inline-block;margin:4px 0 0 6px;padding:8px 14px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#111;text-decoration:none;font-size:14px;cursor:pointer}.acoes .principal{background:#f59e0b;border-color:#f59e0b;color:#111;font-weight:700}'
            . '@media print{.acoes{display:none}body{padding:0}}</style></head><body>'
            . '<div class="topo"><div><h1>' . $e($titulo) . '</h1><div class="nota">Documento gerado em ' . $e($gerado) . ' · Nivora Restaurant</div></div><div class="acoes">' . '%%ACOES%%' . '</div></div>'
            . $h
            . '<p class="nota" style="margin-top:28px">Palavras-passe, códigos e outros elementos de segurança nunca são incluídos. Alguns registos (por exemplo, faturas e vendas) podem ter de ser guardados por obrigação legal. Se algum dado estiver errado, pode pedir a correção em «Os seus dados» no nosso site.</p>'
            . '</body></html>';
    }
}

