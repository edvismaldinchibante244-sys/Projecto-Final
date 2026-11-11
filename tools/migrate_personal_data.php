<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/src/config/database.php';

function migration_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function migration_index_exists(PDO $db, string $table, string $index): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function migration_drop_unique_email_indexes(PDO $db, string $table): void
{
    $indexes = $db->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    $drop = [];
    foreach ($indexes as $index) {
        if (($index['Column_name'] ?? '') === 'email'
            && ($index['Key_name'] ?? '') !== 'PRIMARY') {
            $drop[(string)$index['Key_name']] = true;
        }
    }

    foreach (array_keys($drop) as $index) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $index)) {
            throw new RuntimeException('Nome de indice inesperado em ' . $table . '.');
        }
        $db->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
}

function migration_backup(PDO $db): string
{
    $payload = [
        'created_at' => gmdate('c'),
        'usuarios' => $db->query(
            'SELECT id, email, two_factor_email FROM usuarios ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC),
        'restaurantes' => $db->query(
            'SELECT id, email, telefone, endereco, nuit FROM restaurantes ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC),
    ];
    $hasPasswordResets = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' LIMIT 1")->fetchColumn();
    if ($hasPasswordResets) {
        $payload['password_resets'] = $db->query(
            'SELECT id, email FROM password_resets ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $encrypted = security_encrypt_value($json);
    if (!is_string($encrypted) || $encrypted === '') {
        throw new RuntimeException('Falha ao criptografar o backup da migracao.');
    }

    $dir = __DIR__ . '/../storage/migration_backups';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Falha ao criar diretorio de backup da migracao.');
    }

    $path = $dir . '/personal_data_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.enc';
    if (file_put_contents($path, $encrypted, LOCK_EX) === false) {
        throw new RuntimeException('Falha ao gravar backup da migracao.');
    }
    @chmod($path, 0600);
    return $path;
}

function migration_prepare(PDO $db): void
{
    migration_drop_unique_email_indexes($db, 'usuarios');
    migration_drop_unique_email_indexes($db, 'restaurantes');

    if (!migration_column_exists($db, 'usuarios', 'email_lookup')) {
        $db->exec('ALTER TABLE usuarios ADD COLUMN email_lookup CHAR(64) NULL AFTER email');
    }
    if (!migration_column_exists($db, 'restaurantes', 'email_lookup')) {
        $db->exec('ALTER TABLE restaurantes ADD COLUMN email_lookup CHAR(64) NULL AFTER email');
    }
    if (!migration_column_exists($db, 'restaurantes', 'nuit_lookup')) {
        $db->exec('ALTER TABLE restaurantes ADD COLUMN nuit_lookup CHAR(64) NULL AFTER nuit');
    }

    $db->exec('ALTER TABLE usuarios MODIFY email TEXT NOT NULL, MODIFY two_factor_email TEXT NULL');
    $db->exec('ALTER TABLE restaurantes MODIFY email TEXT NOT NULL, MODIFY telefone TEXT NULL, MODIFY endereco TEXT NULL, MODIFY nuit TEXT NULL');

    $hasPasswordResets = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' LIMIT 1")->fetchColumn();
    if ($hasPasswordResets) {
        migration_drop_unique_email_indexes($db, 'password_resets');
        if (!migration_column_exists($db, 'password_resets', 'email_lookup')) {
            $db->exec('ALTER TABLE password_resets ADD COLUMN email_lookup CHAR(64) NULL AFTER email');
        }
        $db->exec('ALTER TABLE password_resets MODIFY email TEXT NOT NULL');
        if (!migration_index_exists($db, 'password_resets', 'idx_email_lookup')) {
            $db->exec('ALTER TABLE password_resets ADD INDEX idx_email_lookup (email_lookup)');
        }
    }
}

function migration_assert_unique(array $rows, string $field, callable $lookup, string $label): void
{
    $seen = [];
    foreach ($rows as $row) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        $hash = $lookup($value);
        if (isset($seen[$hash])) {
            throw new RuntimeException("Duplicado em {$label}: IDs {$seen[$hash]} e {$row['id']}.");
        }
        $seen[$hash] = (int)$row['id'];
    }
}

function migration_apply(PDO $db): void
{
    migration_prepare($db);

    $usuarios = $db->query('SELECT id, email, two_factor_email FROM usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $restaurantes = $db->query('SELECT id, email, telefone, endereco, nuit FROM restaurantes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $hasPasswordResets = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' LIMIT 1")->fetchColumn();
    $passwordResets = $hasPasswordResets
        ? $db->query('SELECT id, email FROM password_resets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        : [];

    migration_assert_unique($usuarios, 'email', 'personal_data_email_lookup', 'usuarios.email');
    migration_assert_unique($restaurantes, 'email', 'personal_data_email_lookup', 'restaurantes.email');

    $db->beginTransaction();
    try {
        $updateUser = $db->prepare(
            'UPDATE usuarios SET email = ?, email_lookup = ?, two_factor_email = ? WHERE id = ?'
        );
        foreach ($usuarios as $row) {
            $email = trim((string)$row['email']);
            $twoFactorEmail = trim((string)($row['two_factor_email'] ?? ''));
            $updateUser->execute([
                personal_data_encrypt($email),
                personal_data_email_lookup($email),
                $twoFactorEmail === '' ? null : personal_data_encrypt($twoFactorEmail),
                (int)$row['id'],
            ]);
        }

        $updateRestaurant = $db->prepare(
            'UPDATE restaurantes SET email = ?, email_lookup = ?, telefone = ?, endereco = ?, nuit = ?, nuit_lookup = ? WHERE id = ?'
        );
        foreach ($restaurantes as $row) {
            $email = trim((string)$row['email']);
            $telefone = trim((string)($row['telefone'] ?? ''));
            $endereco = trim((string)($row['endereco'] ?? ''));
            $nuit = trim((string)($row['nuit'] ?? ''));
            $updateRestaurant->execute([
                personal_data_encrypt($email),
                personal_data_email_lookup($email),
                $telefone === '' ? null : personal_data_encrypt($telefone),
                $endereco === '' ? null : personal_data_encrypt($endereco),
                $nuit === '' ? null : personal_data_encrypt($nuit),
                personal_data_nuit_lookup($nuit),
                (int)$row['id'],
            ]);
        }

        if ($hasPasswordResets) {
            $updateReset = $db->prepare('UPDATE password_resets SET email = ?, email_lookup = ? WHERE id = ?');
            foreach ($passwordResets as $row) {
                $email = trim((string)$row['email']);
                $updateReset->execute([
                    personal_data_encrypt($email),
                    personal_data_email_lookup($email),
                    (int)$row['id'],
                ]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if (!migration_index_exists($db, 'usuarios', 'uq_usuarios_email_lookup')) {
        $db->exec('ALTER TABLE usuarios ADD UNIQUE INDEX uq_usuarios_email_lookup (email_lookup)');
    }
    if (!migration_index_exists($db, 'restaurantes', 'uq_restaurantes_email_lookup')) {
        $db->exec('ALTER TABLE restaurantes ADD UNIQUE INDEX uq_restaurantes_email_lookup (email_lookup)');
    }
    if (!migration_index_exists($db, 'restaurantes', 'idx_restaurantes_nuit_lookup')) {
        $db->exec('ALTER TABLE restaurantes ADD INDEX idx_restaurantes_nuit_lookup (nuit_lookup)');
    }
}

$mode = $argv[1] ?? '--status';
$db = (new Database())->getConnection();

if ($mode === '--status') {
    $status = [
        'usuarios_email_lookup' => migration_column_exists($db, 'usuarios', 'email_lookup'),
        'restaurantes_email_lookup' => migration_column_exists($db, 'restaurantes', 'email_lookup'),
        'restaurantes_nuit_lookup' => migration_column_exists($db, 'restaurantes', 'nuit_lookup'),
    ];
    echo json_encode($status, JSON_PRETTY_PRINT) . PHP_EOL;
    exit;
}

if (!in_array($mode, ['--prepare', '--apply'], true)) {
    fwrite(STDERR, "Uso: php tools/migrate_personal_data.php --status|--prepare|--apply\n");
    exit(2);
}

$backup = migration_backup($db);
migration_prepare($db);
if ($mode === '--apply') {
    migration_apply($db);
}

echo "Migracao {$mode} concluida.\n";
echo 'Backup criptografado: ' . $backup . PHP_EOL;
