<?php

/**
 * Schema de categorias / subcategorias (parent_id).
 */

require_once __DIR__ . '/migration_state.php';

if (!defined('CATEGORIA_SCHEMA_MIGRATION_ID')) {
    define('CATEGORIA_SCHEMA_MIGRATION_ID', '20260814_1145_categoria_hierarquia');
}

if (!function_exists('categoria_schema_garantir')) {
    function categoria_schema_garantir(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        // Depois da migracao versionada, elimina DDL das requisicoes normais.
        // Sem schema_migrations (instalacoes antigas), o fallback continua activo.
        if (migration_esta_aplicada($db, CATEGORIA_SCHEMA_MIGRATION_ID)) {
            $done = true;
            return;
        }

        try {
            $cols = [];
            $stmt = $db->query("SHOW COLUMNS FROM categorias");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower((string)$row['Field'])] = true;
            }
            if (!isset($cols['parent_id'])) {
                $db->exec("ALTER TABLE categorias ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER restaurante_id");
            }
            if (!isset($cols['ativo'])) {
                $db->exec("ALTER TABLE categorias ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
            }
        } catch (Throwable $e) {
            error_log('categoria_schema_garantir: ' . $e->getMessage());
        }

        $done = true;
    }
}
