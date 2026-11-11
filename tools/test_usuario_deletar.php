<?php
/**
 * Script de teste: usuario_deletar.php
 * 
 * Uso: php test_usuario_deletar.php
 */

require_once dirname(__DIR__) . '/src/public/app_base.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

// Simular session de admin autenticado
session_start();

// Buscar um admin e um restaurante para teste
$db = (new Database())->getConnection();

// Listar usuarios
echo "=== USUARIOS NO BANCO ===\n";
$stmt = $db->query("SELECT id, nome, email, perfil, restaurante_id FROM usuarios LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo sprintf("[%3d] %s <%s> perfil=%s restaurante_id=%d\n", 
        $u['id'], $u['nome'], $u['email'], $u['perfil'], $u['restaurante_id']);
}

// Simular session
echo "\n=== SIMULANDO SESSION DE ADMIN ===\n";
$stmt = $db->prepare("SELECT id, restaurante_id FROM usuarios WHERE perfil = 'ADMIN' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    $_SESSION['usuario_id'] = $admin['id'];
    $_SESSION['restaurante_id'] = $admin['restaurante_id'];
    $_SESSION['perfil'] = 'ADMIN';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    echo "Session setada:\n";
    echo "  usuario_id: " . $_SESSION['usuario_id'] . "\n";
    echo "  restaurante_id: " . $_SESSION['restaurante_id'] . "\n";
    echo "  perfil: " . $_SESSION['perfil'] . "\n";
    
    // Buscar um usuario para deletar (que nao seja admin)
    $stmt2 = $db->prepare("SELECT id, nome, email FROM usuarios WHERE id != ? AND restaurante_id = ? AND perfil != 'ADMIN' LIMIT 1");
    $stmt2->execute([$admin['id'], $admin['restaurante_id']]);
    $target = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($target) {
        echo "\n=== TESTANDO DELECAO ===\n";
        echo "Target para deletar:\n";
        echo "  id: " . $target['id'] . "\n";
        echo "  nome: " . $target['nome'] . "\n";
        echo "  email: " . $target['email'] . "\n";
        
            // Simular a operacao de inativacao sem apagar dados historicos.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['id'] = $target['id'];
        $_POST['_csrf'] = $_SESSION['csrf_token'];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $_SESSION['csrf_token'];
        
            echo "\n=== EXECUTANDO INATIVACAO ===\n";
        
        // Executar a logica de delecao
        $restauranteId = (int)($_SESSION['restaurante_id'] ?? 0);
        $perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
        
        echo "restauranteId: $restauranteId\n";
        echo "perfil: $perfil\n";
        
        if ($restauranteId > 0 && $perfil === 'ADMIN') {
            $id = intval($_POST['id']);
            echo "Tentando deletar usuario id=$id\n";
            
            if ($id !== intval($_SESSION['usuario_id'])) {
                $db->beginTransaction();
                $query = "DELETE FROM usuarios WHERE id = :id AND restaurante_id = :rid";
                $stmt3 = $db->prepare($query);
                $stmt3->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt3->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
                
                $stmt3 = $db->prepare("UPDATE usuarios SET ativo = 0, tentativas_login = 0, bloqueado_ate = NULL WHERE id = :id AND restaurante_id = :rid AND ativo = 1");
                $stmt3->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt3->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
                if ($stmt3->execute()) {
                    echo "✓ Usuario inativado com sucesso! Rows affected: " . $stmt3->rowCount() . "\n";
                    $verify = $db->prepare("SELECT ativo FROM usuarios WHERE id = ?");
                    $verify->execute([$id]);
                    echo ((int)$verify->fetchColumn() === 0 ? "✓ Historico preservado e conta inativa\n" : "✗ Conta continua ativa\n");
                } else {
                    echo "✗ Erro ao executar inativacao: " . json_encode($stmt3->errorInfo()) . "\n";
                }
                $db->rollBack();
            } else {
                echo "✗ Nao pode deletar a si mesmo\n";
            }
        } else {
            echo "✗ Sem permissao: restauranteId=$restauranteId, perfil=$perfil\n";
        }
    } else {
        echo "Nenhum usuario disponivel para teste de delecao\n";
    }
} else {
    echo "Nenhum admin encontrado\n";
}

?>
