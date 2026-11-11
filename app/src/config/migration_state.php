<?php
declare(strict_types=1);

/**
 * Consulta somente leitura para permitir a retirada gradual de DDL em runtime.
 * Na ausencia da tabela de controlo, retorna false e preserva o fallback legado.
 */
if (!function_exists('migration_esta_aplicada')) {
    function migration_esta_aplicada(PDO $db, string $migrationId): bool
    {
        static $cache = [];

        $migrationId = trim($migrationId);
        if ($migrationId === '') {
            return false;
        }

        $cacheKey = spl_object_id($db) . ':' . $migrationId;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        require_once __DIR__ . '/cache_curto.php';
        [$achou] = cache_curto_obter('migracao:' . $migrationId);
        if ($achou) {
            return $cache[$cacheKey] = true;
        }

        try {
            $exists = $db->query(
                "SELECT COUNT(*) FROM information_schema.tables "
                . "WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
            );
            if ((int)$exists->fetchColumn() === 0) {
                return $cache[$cacheKey] = false;
            }

            $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE id = ? LIMIT 1');
            $stmt->execute([$migrationId]);
            $aplicada = ((int)$stmt->fetchColumn() === 1);
            if ($aplicada) {
                cache_curto_guardar('migracao:' . $migrationId, 1, 3600);
            }
            return $cache[$cacheKey] = $aplicada;
        } catch (Throwable $e) {
            error_log('migration_esta_aplicada: consulta indisponivel; fallback preservado. ' . $e->getMessage());
            return $cache[$cacheKey] = false;
        }
    }
}
