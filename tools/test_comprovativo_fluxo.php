<?php
// Simula sessão de super admin e testa o comprovativo_arquivo.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['logado'] = true;
$_SESSION['super_admin'] = 1;
$_SESSION['usuario_id'] = 1;
$_SESSION['nome'] = 'Super Admin';
$_SESSION['restaurante_id'] = 0;

// Simula o GET path
$_GET['path'] = 'uploads/comprovativos/comp_20260723180201_2c9e144bc8a5.jpg';

// Captura a saída
ob_start();

try {
    require __DIR__ . '/../src/public/comprovativo_arquivo.php';
    $output = ob_get_clean();
    
    $headers = headers_list();
    echo "=== HEADERS ===\n";
    foreach ($headers as $h) {
        echo $h . "\n";
    }
    echo "\n=== SAIDA ===\n";
    echo "Tamanho da saida: " . strlen($output) . " bytes\n";
    echo "Primeiros 20 bytes (hex): " . bin2hex(substr($output, 0, 20)) . "\n";
    
    // Verifica se é uma imagem JPEG
    if (strlen($output) > 2 && bin2hex(substr($output, 0, 2)) === 'ffd8') {
        echo "\nRESULTADO: IMAGEM JPEG SERVIDA COM SUCESSO!\n";
    } else {
        echo "\nRESULTADO: SAIDA NAO E IMAGEM JPEG\n";
        echo "Conteudo: " . substr($output, 0, 200) . "\n";
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
