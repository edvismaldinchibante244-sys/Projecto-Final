<?php
declare(strict_types=1);

namespace Nivora\Infrastructure\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public const APPLY_CONFIRMATION = 'APPLY_MIGRATIONS';
    private const TABLE = 'schema_migrations';
    private const LOCK_NAME = 'nivora_schema_migrations';

    public function __construct(
        private readonly PDO $db,
        private readonly string $directory
    ) {
    }

    /**
     * Valida os arquivos sem ligar ou alterar o banco.
     *
     * @return list<array{id:string, description:string, transactional:bool, up:callable, file:string, checksum:string}>
     */
    public static function loadDefinitions(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Diretorio de migracoes inexistente: ' . $directory);
        }

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files, SORT_STRING);
        $definitions = [];
        $ids = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (!preg_match('/^\d{8}_\d{4}_[a-z0-9_]+\.php$/', $filename)) {
                throw new RuntimeException('Nome de migracao invalido: ' . $filename);
            }

            $definition = require $file;
            if (!is_array($definition)) {
                throw new RuntimeException('Migracao deve retornar array: ' . $filename);
            }

            $expectedId = substr($filename, 0, -4);
            $id = (string)($definition['id'] ?? '');
            $description = trim((string)($definition['description'] ?? ''));
            $up = $definition['up'] ?? null;

            if ($id !== $expectedId) {
                throw new RuntimeException('ID da migracao nao corresponde ao arquivo: ' . $filename);
            }
            if ($description === '') {
                throw new RuntimeException('Descricao ausente na migracao: ' . $filename);
            }
            if (!is_callable($up)) {
                throw new RuntimeException('Callback up ausente na migracao: ' . $filename);
            }
            if (isset($ids[$id])) {
                throw new RuntimeException('ID de migracao duplicado: ' . $id);
            }

            $checksum = hash_file('sha256', $file);
            if (!is_string($checksum) || $checksum === '') {
                throw new RuntimeException('Nao foi possivel calcular checksum: ' . $filename);
            }

            $ids[$id] = true;
            $definitions[] = [
                'id' => $id,
                'description' => $description,
                'transactional' => (bool)($definition['transactional'] ?? false),
                'up' => $up,
                'file' => $file,
                'checksum' => $checksum,
            ];
        }

        return $definitions;
    }

    /** @return list<array{id:string, description:string, status:string, checksum:string}> */
    public function status(): array
    {
        $this->assertMysql();
        $definitions = self::loadDefinitions($this->directory);
        $applied = $this->appliedMigrations();
        $rows = [];

        foreach ($definitions as $migration) {
            $id = $migration['id'];
            $status = 'pending';
            if (isset($applied[$id])) {
                $status = hash_equals($applied[$id], $migration['checksum']) ? 'applied' : 'checksum_mismatch';
            }
            $rows[] = [
                'id' => $id,
                'description' => $migration['description'],
                'status' => $status,
                'checksum' => $migration['checksum'],
            ];
        }

        return $rows;
    }

    /** @return list<array{id:string, status:string}> */
    public function apply(string $confirmation, string $targetDatabase, bool $allowNonTest = false): array
    {
        if (!hash_equals(self::APPLY_CONFIRMATION, $confirmation)) {
            throw new RuntimeException('Confirmacao invalida. Nenhuma migracao foi executada.');
        }

        $this->assertMysql();
        $targetDatabase = trim($targetDatabase);
        if ($targetDatabase === '') {
            throw new RuntimeException('Banco-alvo obrigatorio. Nenhuma migracao foi executada.');
        }

        $actualDatabase = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($actualDatabase === '' || !hash_equals($actualDatabase, $targetDatabase)) {
            throw new RuntimeException('O banco conectado nao corresponde ao --target informado.');
        }

        $isolatedName = preg_match('/(?:^|[_-])(test|testing|sandbox|staging)(?:[_-]|$)/i', $actualDatabase) === 1;
        if (!$isolatedName && !$allowNonTest) {
            throw new RuntimeException('Banco-alvo nao parece isolado. Use --allow-non-test somente com autorizacao operacional.');
        }

        $definitions = self::loadDefinitions($this->directory);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([self::LOCK_NAME]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Nao foi possivel obter o bloqueio exclusivo de migracoes.');
        }

        try {
            $this->ensureMigrationsTable();
            $applied = $this->appliedMigrations();
            $result = [];

            foreach ($definitions as $migration) {
                $id = $migration['id'];
                if (isset($applied[$id])) {
                    if (!hash_equals($applied[$id], $migration['checksum'])) {
                        throw new RuntimeException('Checksum alterado para migracao ja aplicada: ' . $id);
                    }
                    $result[] = ['id' => $id, 'status' => 'already_applied'];
                    continue;
                }

                $transactional = $migration['transactional'];
                if ($transactional) {
                    $this->db->beginTransaction();
                }

                try {
                    ($migration['up'])($this->db);
                    $stmt = $this->db->prepare(
                        'INSERT INTO ' . self::TABLE . ' (id, checksum, description, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())'
                    );
                    $stmt->execute([$id, $migration['checksum'], $migration['description']]);
                    if ($transactional && $this->db->inTransaction()) {
                        $this->db->commit();
                    }
                    $result[] = ['id' => $id, 'status' => 'applied'];
                } catch (Throwable $e) {
                    if ($transactional && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            }

            return $result;
        } finally {
            try {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::LOCK_NAME]);
            } catch (Throwable) {
                // A ligacao MySQL tambem liberta o lock ao terminar.
            }
        }
    }

    private function assertMysql(): void
    {
        $driver = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            throw new RuntimeException('O runner suporta apenas MySQL. Driver recebido: ' . $driver);
        }
    }

    /** @return array<string,string> */
    private function appliedMigrations(): array
    {
        $exists = $this->db->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "'"
        );
        if ((int)$exists->fetchColumn() === 0) {
            return [];
        }

        $rows = $this->db->query('SELECT id, checksum FROM ' . self::TABLE)->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) {
            $applied[(string)$row['id']] = (string)$row['checksum'];
        }
        return $applied;
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'id VARCHAR(190) NOT NULL PRIMARY KEY,'
            . 'checksum CHAR(64) NOT NULL,'
            . 'description VARCHAR(255) NOT NULL,'
            . 'applied_at DATETIME NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
