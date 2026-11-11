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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
