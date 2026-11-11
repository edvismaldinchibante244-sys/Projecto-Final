<?php
require_once __DIR__ . '/../app/src/config/database.php';

$db = (new Database())->getConnection();

// Buscar todos os comprovativos
$stmt = $db->query("SELECT id, restaurante_id, comprovativo_path FROM compras_planos WHERE comprovativo_path IS NOT NULL AND comprovativo_path != '' ORDER BY id DESC");
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dirBase = __DIR__ . '/../src/public/';

echo "=== Validacao dos comprovativos ===\n";
foreach ($compras as $c) {
    $path = trim((string)$c['comprovativo_path']);
    $path = rawurldecode($path);
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    // Replica a regex do comprovativo_arquivo.php
    $regexOk = preg_match('#^uploads/comprovativos/[A-Za-z0-9_.-]+\.(pdf|jpg|jpeg|png)$#i', $path) === 1;

    // Verifica se o arquivo existe
    $absolute = $dirBase . $path;
    $arquivoExiste = is_file($absolute);

    // Verifica se o registro existe no banco (mesma query do script)
    $checkStmt = $db->prepare("SELECT id FROM compras_planos WHERE comprovativo_path = :path LIMIT 1");
    $checkStmt->execute([':path' => $path]);
    $registroBanco = $checkStmt->fetchColumn() ? true : false;

    $status = $regexOk ? 'OK' : 'REGEX_FALHOU';
    $status .= $arquivoExiste ? '' : ' | ARQUIVO_NAO_EXISTE';
    $status .= $registroBanco ? '' : ' | REGISTRO_NAO_ENCONTRADO';

    echo "Compra #{$c['id']} (rest {$c['restaurante_id']}): {$path}\n";
    echo "  -> regex: " . ($regexOk ? 'OK' : 'FALHOU') . " | arquivo: " . ($arquivoExiste ? 'EXISTE' : 'NAO EXISTE') . " | banco: " . ($registroBanco ? 'ENCONTRADO' : 'NAO ENCONTRADO') . "\n";

    // Mostrar bytes em hex do path para detectar caracteres invisíveis
    echo "  -> path hex: " . bin2hex($path) . "\n";
    echo "\n";
}
