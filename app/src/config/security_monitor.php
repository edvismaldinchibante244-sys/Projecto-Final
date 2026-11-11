<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/security_ip_blocklist.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/migration_state.php';

if (!defined('SECURITY_MONITOR_SCHEMA_MIGRATION_ID')) {
    define('SECURITY_MONITOR_SCHEMA_MIGRATION_ID', '20260814_1440_security_monitor');
}

if (!function_exists('security_monitor_ensure_tables')) {
    function security_monitor_ensure_tables(PDO $db): void
    {
        static $initialized = [];
        $key = spl_object_id($db);
        if (isset($initialized[$key])) {
            return;
        }
        if (migration_esta_aplicada($db, SECURITY_MONITOR_SCHEMA_MIGRATION_ID)) {
            $initialized[$key] = true;
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS blocked_ips (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(64) NOT NULL,
                motivo VARCHAR(255) NOT NULL,
                origem VARCHAR(40) NOT NULL DEFAULT 'manual',
                bloqueio_tipo ENUM('TEMPORARIO','PERMANENTE') NOT NULL DEFAULT 'TEMPORARIO',
                bloqueado_ate DATETIME NULL,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                criado_por INT NULL,
                criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_blocked_ip (ip),
                INDEX idx_blocked_ativo (ativo),
                INDEX idx_blocked_ate (bloqueado_ate)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS security_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_name VARCHAR(80) NOT NULL,
                attack_type VARCHAR(40) NOT NULL DEFAULT 'SUSPEITO',
                severity ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
                ip VARCHAR(64) NULL,
                endpoint VARCHAR(255) NULL,
                method VARCHAR(12) NULL,
                payload_excerpt VARCHAR(600) NULL,
                source_log VARCHAR(60) NOT NULL DEFAULT 'security.log',
                context_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_security_events_created (created_at),
                INDEX idx_security_events_type (attack_type),
                INDEX idx_security_events_ip (ip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS offline_sync_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                op_id VARCHAR(80) NOT NULL,
                restaurante_id INT NULL,
                usuario_id INT NULL,
                device_id VARCHAR(120) NULL,
                endpoint VARCHAR(255) NOT NULL,
                method VARCHAR(12) NOT NULL DEFAULT 'POST',
                status ENUM('PENDING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
                attempts INT NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                payload_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_sync_at DATETIME NULL,
                UNIQUE KEY uq_offline_op_id (op_id),
                INDEX idx_offline_status (status),
                INDEX idx_offline_restaurante (restaurante_id),
                INDEX idx_offline_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $initialized[$key] = true;
    }
}

if (!function_exists('security_monitor_detect_attack_type')) {
    function security_monitor_detect_attack_type(array $row): string
    {
        $event = strtoupper((string)($row['event'] ?? ''));
        $reason = strtoupper((string)($row['context']['reason'] ?? ''));
        $ua = strtoupper((string)($row['user_agent'] ?? ''));
        $uri = strtoupper((string)($row['uri'] ?? ''));

        if (str_contains($event, 'BRUTE') || str_contains($event, 'LOGIN_FAILED') || str_contains($event, 'LOGIN_BLOQUEADO')) {
            return 'BRUTE_FORCE';
        }
        if (str_contains($reason, 'SQLI') || str_contains($uri, 'UNION%20SELECT') || str_contains($uri, ' OR 1=1')) {
            return 'SQL_INJECTION';
        }
        if (str_contains($reason, 'XSS') || str_contains($uri, '<SCRIPT')) {
            return 'XSS';
        }
        if (str_contains($reason, 'PATH_TRAVERSAL') || str_contains($uri, '../')) {
            return 'PATH_TRAVERSAL';
        }
        if (str_contains($reason, 'SENSITIVE_PROBE')) {
            return 'SENSITIVE_PROBE';
        }
        if (str_contains($ua, 'BOT') || str_contains($ua, 'CRAWLER') || str_contains($ua, 'SPIDER')) {
            return 'BOT';
        }
        if (str_contains($event, 'WAF')) {
            return 'WAF_BLOCK';
        }
        return 'SUSPEITO';
    }
}

if (!function_exists('security_monitor_severity_for_type')) {
    function security_monitor_severity_for_type(string $type): string
    {
        $type = strtoupper(trim($type));
        if (in_array($type, ['SQL_INJECTION', 'PATH_TRAVERSAL'], true)) {
            return 'CRITICAL';
        }
        if (in_array($type, ['XSS', 'BRUTE_FORCE', 'SENSITIVE_PROBE'], true)) {
            return 'HIGH';
        }
        if (in_array($type, ['BOT', 'WAF_BLOCK'], true)) {
            return 'MEDIUM';
        }
        return 'LOW';
    }
}

if (!function_exists('security_monitor_ingest_log_file')) {
    function security_monitor_ingest_log_file(PDO $db, string $path, string $sourceLog, int $maxLines = 1000): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $rows = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($rows) || empty($rows)) {
            return 0;
        }

        $slice = array_slice($rows, -1 * max(1, $maxLines));
        $inserted = 0;
        $stmt = $db->prepare("
            INSERT INTO security_events
                (event_name, attack_type, severity, ip, endpoint, method, payload_excerpt, source_log, context_json, created_at)
            VALUES
                (:event_name, :attack_type, :severity, :ip, :endpoint, :method, :payload_excerpt, :source_log, :context_json, :created_at)
        ");

        foreach ($slice as $line) {
            $data = json_decode((string)$line, true);
            if (!is_array($data)) {
                continue;
            }

            $eventName = (string)($data['event'] ?? '');
            if ($eventName === '') {
                continue;
            }

            $createdAtRaw = (string)($data['time'] ?? '');
            $createdAt = date('Y-m-d H:i:s', strtotime($createdAtRaw ?: 'now'));
            $ip = security_clean_string((string)($data['ip'] ?? ''), 64);
            $endpoint = security_clean_string((string)($data['uri'] ?? ''), 255);
            $method = security_clean_string((string)($data['method'] ?? ''), 12);
            $type = security_monitor_detect_attack_type($data);
            $severity = security_monitor_severity_for_type($type);
            $payloadExcerpt = security_clean_string((string)json_encode($data['context'] ?? [], JSON_UNESCAPED_UNICODE), 600);
            $contextJson = json_encode($data['context'] ?? [], JSON_UNESCAPED_UNICODE);

            // dedupe simples por assinatura curta de evento
            $check = $db->prepare("
                SELECT id
                  FROM security_events
                 WHERE event_name = :event_name
                   AND ip = :ip
                   AND endpoint = :endpoint
                   AND source_log = :source_log
                   AND created_at BETWEEN DATE_SUB(:created_at_min, INTERVAL 2 SECOND) AND DATE_ADD(:created_at_max, INTERVAL 2 SECOND)
                 LIMIT 1
            ");
            $check->execute([
                ':event_name' => $eventName,
                ':ip' => $ip,
                ':endpoint' => $endpoint,
                ':source_log' => $sourceLog,
                ':created_at_min' => $createdAt,
                ':created_at_max' => $createdAt,
            ]);
            if ($check->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }

            $stmt->execute([
                ':event_name' => $eventName,
                ':attack_type' => $type,
                ':severity' => $severity,
                ':ip' => $ip ?: null,
                ':endpoint' => $endpoint ?: null,
                ':method' => $method ?: null,
                ':payload_excerpt' => $payloadExcerpt ?: null,
                ':source_log' => $sourceLog,
                ':context_json' => $contextJson ?: null,
                ':created_at' => $createdAt,
            ]);
            $inserted++;
        }

        return $inserted;
    }
}

if (!function_exists('security_monitor_block_ip')) {
    function security_monitor_block_ip(PDO $db, string $ip, string $motivo, string $origem = 'manual', bool $permanente = false, int $duracaoMin = 60): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        security_monitor_ensure_tables($db);

        $bloqueadoAte = null;
        if (!$permanente) {
            $duracao = max(1, $duracaoMin);
            $bloqueadoAte = date('Y-m-d H:i:s', time() + ($duracao * 60));
        }

        $stmt = $db->prepare("
            INSERT INTO blocked_ips
                (ip, motivo, origem, bloqueio_tipo, bloqueado_ate, ativo, criado_por)
            VALUES
                (:ip, :motivo, :origem, :tipo, :bloqueado_ate, 1, :criado_por)
        ");
        $stmt->execute([
            ':ip' => $ip,
            ':motivo' => security_clean_string($motivo, 255),
            ':origem' => security_clean_string($origem, 40),
            ':tipo' => $permanente ? 'PERMANENTE' : 'TEMPORARIO',
            ':bloqueado_ate' => $bloqueadoAte,
            ':criado_por' => (int)($_SESSION['usuario_id'] ?? 0) ?: null,
        ]);

        $rows = security_ip_blocklist_read();
        $rows[] = [
            'ip' => $ip,
            'reason' => $motivo,
            'source' => $origem,
            'blocked_until' => $permanente ? 0 : (time() + (max(1, $duracaoMin) * 60)),
            'permanent' => $permanente ? 1 : 0,
            'created_at' => time(),
        ];
        security_ip_blocklist_write($rows);

        security_log_event('security_ip_blocked', [
            'ip' => $ip,
            'motivo' => $motivo,
            'origem' => $origem,
            'permanente' => $permanente ? 1 : 0,
            'duracao_min' => $permanente ? null : max(1, $duracaoMin),
        ]);

        audit_log($db, 'security_ip_blocked', [
            'ip' => $ip,
            'motivo' => $motivo,
            'origem' => $origem,
            'permanente' => $permanente ? 1 : 0,
        ], 'WARN');

        return true;
    }
}

if (!function_exists('security_monitor_unblock_ip')) {
    function security_monitor_unblock_ip(PDO $db, string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        security_monitor_ensure_tables($db);

        $stmt = $db->prepare("
            UPDATE blocked_ips
               SET ativo = 0, atualizado_em = NOW()
             WHERE ip = :ip
               AND ativo = 1
        ");
        $stmt->execute([':ip' => $ip]);

        $rows = security_ip_blocklist_read();
        $rows = array_values(array_filter($rows, static function ($row) use ($ip) {
            return (string)($row['ip'] ?? '') !== $ip;
        }));
        security_ip_blocklist_write($rows);

        security_log_event('security_ip_unblocked', ['ip' => $ip]);
        audit_log($db, 'security_ip_unblocked', ['ip' => $ip], 'INFO');
        return true;
    }
}
