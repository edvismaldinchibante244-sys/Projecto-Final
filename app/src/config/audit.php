<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/migration_state.php';

if (!defined('AUDIT_SCHEMA_MIGRATION_ID')) {
    define('AUDIT_SCHEMA_MIGRATION_ID', '20260814_1255_audit_logs');
}

if (!function_exists('audit_ensure_table')) {
    function audit_ensure_table(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        if (migration_esta_aplicada($db, AUDIT_SCHEMA_MIGRATION_ID)) {
            $done = true;
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event VARCHAR(120) NOT NULL,
                severity VARCHAR(20) NOT NULL DEFAULT 'INFO',
                usuario_id INT NULL,
                restaurante_id INT NULL,
                ip VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                method VARCHAR(12) NULL,
                uri VARCHAR(500) NULL,
                context_json JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_event (event),
                INDEX idx_audit_usuario (usuario_id),
                INDEX idx_audit_restaurante (restaurante_id),
                INDEX idx_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $done = true;
    }
}

if (!function_exists('audit_log')) {
    function audit_log(?PDO $db, string $event, array $context = [], string $severity = 'INFO'): bool
    {
        $severity = strtoupper(trim($severity)) ?: 'INFO';
        $severity = in_array($severity, ['DEBUG', 'INFO', 'WARN', 'ERROR', 'CRITICAL'], true) ? $severity : 'INFO';

        try {
            if (!$db instanceof PDO) {
                if (!class_exists('Database')) {
                    require_once __DIR__ . '/database.php';
                }
                $db = (new Database())->getConnection();
            }

            if (!$db->inTransaction()) {
                audit_ensure_table($db);
            }

            $stmt = $db->prepare("
                INSERT INTO audit_logs
                    (event, severity, usuario_id, restaurante_id, ip, user_agent, method, uri, context_json)
                VALUES
                    (:event, :severity, :usuario_id, :restaurante_id, :ip, :user_agent, :method, :uri, :context_json)
            ");

            $stmt->execute([
                ':event' => substr($event, 0, 120),
                ':severity' => $severity,
                ':usuario_id' => isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null,
                ':restaurante_id' => isset($_SESSION['restaurante_id']) ? (int)$_SESSION['restaurante_id'] : null,
                ':ip' => security_get_client_ip(),
                ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ':method' => substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 12),
                ':uri' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
                ':context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return true;
        } catch (Throwable $e) {
            security_log_event('audit_log_failed', [
                'event' => $event,
                'severity' => $severity,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
