<?php
// Simula o que o comprovativo_arquivo.php faz para o super admin
$_SESSION['super_admin'] = 1;
$_SESSION['logado'] = true;

require_once __DIR__ . '/../app/src/config/database.php';

$db = (new Database())->getConnection();

$path = 'uploads/comprovativos/comp_20260723180201_2c9e144bc8a5.jpg';

// 1. Valida regex
$regexOk = preg_match('#^uploads/comprovativos/[A-Za-z0-9_.-]+\.(pdf|jpg|jpeg|png)$#i', $path) === 1;
echo "Regex: " . ($regexOk ? 'OK' : 'FALHOU') . "\n";

// 2. Query como super admin (sem filtro de restaurante)
$stmt = $db->prepare("SELECT id FROM compras_planos WHERE comprovativo_path = :path LIMIT 1");
$stmt->execute([':path' => $path]);
$encontrado = $stmt->fetchColumn();
echo "Registro no banco (super admin): " . ($encontrado ? "ENCONTRADO id=$encontrado" : "NAO ENCONTRADO") . "\n";

// 3. Verifica arquivo absoluto
$absolute = __DIR__ . '/../src/public/' . $path;
echo "Caminho absoluto: $absolute\n";
echo "Arquivo existe: " . (is_file($absolute) ? 'SIM' : 'NAO') . "\n";
echo "Arquivo legivel: " . (is_readable($absolute) ? 'SIM' : 'NAO') . "\n";

// 4. Testa se o realpath bate
$real = realpath($absolute);
echo "Realpath: " . ($real ?: 'FALHOU') . "\n";

// 5. Verifica permissões do diretório
$dir = __DIR__ . '/../src/public/uploads/comprovativos';
echo "\n=== Permissoes do diretorio ===\n";
echo "Diretorio existe: " . (is_dir($dir) ? 'SIM' : 'NAO') . "\n";
echo "Diretorio legivel: " . (is_readable($dir) ? 'SIM' : 'NAO') . "\n";
echo "Permissao dir: " . (function_exists('fileperms') ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A') . "\n";
echo "Permissao arquivo: " . (function_exists('fileperms') ? substr(sprintf('%o', fileperms($absolute)), -4) : 'N/A') . "\n";
