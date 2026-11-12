<?php
require_once __DIR__ . '/storage_path.php';

if (!function_exists('support_tickets_ensure_tables')) {
    function support_tickets_ensure_tables(PDO $db): void
    {
        static $ok = false;
        if ($ok) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS support_tickets (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                usuario_id INT NOT NULL,
                plano_codigo VARCHAR(40) NOT NULL,
                ticket_hash VARCHAR(64) NOT NULL,
                canal ENUM('DASHBOARD','WHATSAPP','EMAIL') NOT NULL DEFAULT 'DASHBOARD',
                prioridade ENUM('BAIXA','MEDIA','ALTA','URGENTE') NOT NULL DEFAULT 'ALTA',
                status ENUM('ABERTO','EM_ANDAMENTO','RESOLVIDO','FECHADO') NOT NULL DEFAULT 'ABERTO',
                assunto VARCHAR(160) NOT NULL,
                descricao TEXT NOT NULL,
                resposta_admin TEXT NULL,
                respondido_por INT NULL,
                respondido_em DATETIME NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_support_ticket_hash (ticket_hash),
                KEY idx_support_rest_status (restaurante_id, status, criado_em),
                KEY idx_support_status_prio (status, prioridade, criado_em),
                KEY idx_support_user (usuario_id, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT NOT NULL,
                restaurante_id INT NOT NULL,
                author_tipo ENUM('CLIENTE','SUPORTE','SISTEMA') NOT NULL DEFAULT 'CLIENTE',
                author_usuario_id INT NULL,
                mensagem TEXT NULL,
                anexo_nome VARCHAR(180) NULL,
                anexo_path VARCHAR(255) NULL,
                anexo_mime VARCHAR(120) NULL,
                anexo_size INT NULL,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_support_msg_ticket (ticket_id, criado_em),
                KEY idx_support_msg_rest (restaurante_id, criado_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $ticketCols = [];
        $msgCols = [];
        try {
            $ticketColsStmt = $db->query("SHOW COLUMNS FROM support_tickets");
            foreach (($ticketColsStmt ? $ticketColsStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
                $ticketCols[(string)($col['Field'] ?? '')] = true;
            }
            $msgColsStmt = $db->query("SHOW COLUMNS FROM support_ticket_messages");
            foreach (($msgColsStmt ? $msgColsStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $col) {
                $msgCols[(string)($col['Field'] ?? '')] = true;
            }
        } catch (Throwable $e) {
            $ticketCols = [];
            $msgCols = [];
        }

        $alterTickets = [];
        if (!isset($ticketCols['canal'])) $alterTickets[] = "ADD COLUMN canal ENUM('DASHBOARD','WHATSAPP','EMAIL') NOT NULL DEFAULT 'DASHBOARD' AFTER ticket_hash";
        if (!isset($ticketCols['resposta_admin'])) $alterTickets[] = "ADD COLUMN resposta_admin TEXT NULL AFTER descricao";
        if (!isset($ticketCols['respondido_por'])) $alterTickets[] = "ADD COLUMN respondido_por INT NULL AFTER resposta_admin";
        if (!isset($ticketCols['respondido_em'])) $alterTickets[] = "ADD COLUMN respondido_em DATETIME NULL AFTER respondido_por";
        if (!isset($ticketCols['atualizado_em'])) $alterTickets[] = "ADD COLUMN atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER criado_em";
        if (!isset($ticketCols['criado_em'])) $alterTickets[] = "ADD COLUMN criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP";

        foreach ($alterTickets as $sqlPart) {
            try {
                $db->exec("ALTER TABLE support_tickets " . $sqlPart);
            } catch (Throwable $e) {
                // Ignora se coluna ja existir em variacoes de schema.
            }
        }

        $alterMsgs = [];
        if (!isset($msgCols['author_tipo'])) $alterMsgs[] = "ADD COLUMN author_tipo ENUM('CLIENTE','SUPORTE','SISTEMA') NOT NULL DEFAULT 'CLIENTE' AFTER restaurante_id";
        if (!isset($msgCols['author_usuario_id'])) $alterMsgs[] = "ADD COLUMN author_usuario_id INT NULL AFTER author_tipo";
        if (!isset($msgCols['mensagem'])) $alterMsgs[] = "ADD COLUMN mensagem TEXT NULL AFTER author_usuario_id";
        if (!isset($msgCols['anexo_nome'])) $alterMsgs[] = "ADD COLUMN anexo_nome VARCHAR(180) NULL AFTER mensagem";
        if (!isset($msgCols['anexo_path'])) $alterMsgs[] = "ADD COLUMN anexo_path VARCHAR(255) NULL AFTER anexo_nome";
        if (!isset($msgCols['anexo_mime'])) $alterMsgs[] = "ADD COLUMN anexo_mime VARCHAR(120) NULL AFTER anexo_path";
        if (!isset($msgCols['anexo_size'])) $alterMsgs[] = "ADD COLUMN anexo_size INT NULL AFTER anexo_mime";
        if (!isset($msgCols['criado_em'])) $alterMsgs[] = "ADD COLUMN criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP";

        foreach ($alterMsgs as $sqlPart) {
            try {
                $db->exec("ALTER TABLE support_ticket_messages " . $sqlPart);
            } catch (Throwable $e) {
                // Ignora se coluna ja existir em variacoes de schema.
            }
        }

        $ok = true;
    }
}

if (!function_exists('support_tickets_normalize_status')) {
    function support_tickets_normalize_status(string $status): string
    {
        $value = strtoupper(trim($status));
        $allowed = ['ABERTO', 'EM_ANDAMENTO', 'RESOLVIDO', 'FECHADO'];
        return in_array($value, $allowed, true) ? $value : 'ABERTO';
    }
}

if (!function_exists('support_tickets_normalize_priority')) {
    function support_tickets_normalize_priority(string $priority): string
    {
        $value = strtoupper(trim($priority));
        $allowed = ['BAIXA', 'MEDIA', 'ALTA', 'URGENTE'];
        return in_array($value, $allowed, true) ? $value : 'ALTA';
    }
}

if (!function_exists('support_tickets_priority_label')) {
    function support_tickets_priority_label(string $priority): string
    {
        return [
            'BAIXA' => 'Baixa',
            'MEDIA' => 'Media',
            'ALTA' => 'Alta',
            'URGENTE' => 'Urgente',
        ][support_tickets_normalize_priority($priority)] ?? 'Alta';
    }
}

if (!function_exists('support_tickets_status_label')) {
    function support_tickets_status_label(string $status): string
    {
        return [
            'ABERTO' => 'Aberto',
            'EM_ANDAMENTO' => 'Em andamento',
            'RESOLVIDO' => 'Resolvido',
            'FECHADO' => 'Fechado',
        ][support_tickets_normalize_status($status)] ?? 'Aberto';
    }
}

if (!function_exists('support_tickets_priority_weight')) {
    function support_tickets_priority_weight(string $priority): int
    {
        return [
            'URGENTE' => 4,
            'ALTA' => 3,
            'MEDIA' => 2,
            'BAIXA' => 1,
        ][support_tickets_normalize_priority($priority)] ?? 3;
    }
}

if (!function_exists('support_tickets_sla_hours')) {
    function support_tickets_sla_hours(string $priority): int
    {
        return [
            'URGENTE' => 2,
            'ALTA' => 8,
            'MEDIA' => 24,
            'BAIXA' => 48,
        ][support_tickets_normalize_priority($priority)] ?? 8;
    }
}

if (!function_exists('support_tickets_sla_metrics')) {
    function support_tickets_sla_metrics(array $ticket, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $priority = support_tickets_normalize_priority((string)($ticket['prioridade'] ?? 'ALTA'));
        $targetHours = support_tickets_sla_hours($priority);
        $targetMinutes = $targetHours * 60;

        try {
            $createdAt = new DateTimeImmutable((string)($ticket['criado_em'] ?? 'now'));
        } catch (Throwable $e) {
            $createdAt = $now;
        }

        $responseValue = trim((string)($ticket['respondido_em'] ?? ''));
        $respondedAt = null;
        if ($responseValue !== '') {
            try {
                $respondedAt = new DateTimeImmutable($responseValue);
            } catch (Throwable $e) {
                $respondedAt = null;
            }
        }

        $reference = $respondedAt ?? $now;
        $elapsedMinutes = max(0, (int)floor(($reference->getTimestamp() - $createdAt->getTimestamp()) / 60));
        $remainingMinutes = $targetMinutes - $elapsedMinutes;
        $progress = $targetMinutes > 0 ? (int)round(($elapsedMinutes / $targetMinutes) * 100) : 100;

        if ($respondedAt !== null) {
            $state = $elapsedMinutes <= $targetMinutes ? 'CUMPRIDO' : 'VENCIDO';
        } elseif ($elapsedMinutes >= $targetMinutes) {
            $state = 'VENCIDO';
        } elseif ($progress >= 75) {
            $state = 'EM_RISCO';
        } else {
            $state = 'NO_PRAZO';
        }

        $labels = [
            'CUMPRIDO' => 'SLA cumprido',
            'VENCIDO' => 'SLA vencido',
            'EM_RISCO' => 'SLA em risco',
            'NO_PRAZO' => 'Dentro do SLA',
        ];

        return [
            'priority' => $priority,
            'target_hours' => $targetHours,
            'elapsed_minutes' => $elapsedMinutes,
            'remaining_minutes' => $remainingMinutes,
            'progress_percent' => min(999, max(0, $progress)),
            'due_at' => $createdAt->modify('+' . $targetHours . ' hours')->format('Y-m-d H:i:s'),
            'state' => $state,
            'label' => $labels[$state] ?? $state,
        ];
    }
}

if (!function_exists('support_tickets_open_count')) {
    function support_tickets_open_count(PDO $db, int $restauranteId): int
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM support_tickets
            WHERE restaurante_id = :rid
              AND status IN ('ABERTO', 'EM_ANDAMENTO')
        ");
        $stmt->execute([':rid' => $restauranteId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('support_tickets_notify_support_copy')) {
    function support_tickets_notify_support_copy(string $subject, string $body): void
    {
        if (function_exists('plano_notif_send_support_copy')) {
            plano_notif_send_support_copy($subject, $body);
        }
    }
}

if (!function_exists('support_tickets_support_email')) {
    function support_tickets_support_email(): string
    {
        if (function_exists('plano_notif_support_email')) {
            $email = trim((string)plano_notif_support_email());
            if ($email !== '') {
                return $email;
            }
        }

        if (function_exists('saas_email_env_value')) {
            $email = trim((string)(saas_email_env_value('SUPPORT_EMAIL') ?? ''));
            if ($email !== '') {
                return $email;
            }
        }

        return '';
    }
}

if (!function_exists('support_tickets_support_whatsapp')) {
    function support_tickets_support_whatsapp(): string
    {
        $keys = ['SUPPORT_WHATSAPP', 'WHATSAPP_SUPPORT_NUMBER', 'TWILIO_WHATSAPP_FROM'];
        foreach ($keys as $key) {
            $value = '';
            if (function_exists('saas_email_env_value')) {
                $value = trim((string)(saas_email_env_value($key) ?? ''));
            }
            if ($value === '' && isset($_ENV[$key])) {
                $value = trim((string)$_ENV[$key]);
            }
            if ($value === '' && isset($_SERVER[$key])) {
                $value = trim((string)$_SERVER[$key]);
            }
            if ($value !== '') {
                $value = preg_replace('/^whatsapp:/i', '', $value);
                return trim((string)$value);
            }
        }

        return '';
    }
}

if (!function_exists('support_tickets_build_whatsapp_url')) {
    function support_tickets_build_whatsapp_url(string $phone, string $message): ?string
    {
        $phone = trim($phone);
        $message = trim($message);
        if ($phone === '' || $message === '') {
            return null;
        }

        if (function_exists('notificar_cliente_whatsapp_link')) {
            return notificar_cliente_whatsapp_link($phone, $message);
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
    }
}

if (!function_exists('support_tickets_store_attachment')) {
    function support_tickets_store_attachment(array $file, int $ticketId, ?string &$error = null): ?array
    {
        $error = null;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (!function_exists('security_validate_upload')) {
            $error = 'Validacao de upload indisponivel';
            return null;
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        $allowedMime = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];

        if (!security_validate_upload($file, $allowedExt, $allowedMime, 8 * 1024 * 1024, $error)) {
            return null;
        }

        $baseDir = nv_storage('support_attachments');
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
            $error = 'Nao foi possivel preparar a pasta de anexos';
            return null;
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $safeName = 't' . $ticketId . '_' . bin2hex(random_bytes(8)) . ($ext !== '' ? ('.' . $ext) : '');
        $targetAbs = $baseDir . '/' . $safeName;
        if (!@move_uploaded_file((string)$file['tmp_name'], $targetAbs)) {
            $error = 'Falha ao salvar o anexo';
            return null;
        }

        @chmod($targetAbs, 0644);

        return [
            'nome' => substr((string)($file['name'] ?? 'anexo'), 0, 180),
            'path' => 'storage/support_attachments/' . $safeName,
            'mime' => substr((string)($file['type'] ?? ''), 0, 120),
            'size' => (int)($file['size'] ?? 0),
        ];
    }
}
