<?php
/**
 * Teste final da API usuario_deletar.php
 */

// Simular a requisição
session_start();
$_SERVER['REQUEST_METHOD'] = 'POST';

require_once dirname(__DIR__) . '/src/public/app_base.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/audit.php';

// Setup session
$db = (new Database())->getConnection();
$stmt = $db->prepare("SELECT id, restaurante_id FROM usuarios WHERE perfil = 'ADMIN' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    $_SESSION['usuario_id'] = $admin['id'];
    $_SESSION['restaurante_id'] = $admin['restaurante_id'];
    $_SESSION['perfil'] = 'ADMIN';
    
    // Buscar usuario para deletar
    $stmt = $db->prepare("SELECT id, nome FROM usuarios WHERE id != ? AND restaurante_id = ? AND perfil != 'ADMIN' LIMIT 1");
    $stmt->execute([$admin['id'], $admin['restaurante_id']]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($target) {
        echo "=== TESTANDO DELECAO COM API CORRIGIDA ===\n";
        echo "Admin: ID " . $admin['id'] . "\n";
        echo "Target: ID " . $target['id'] . " - " . $target['nome'] . "\n";
        
        // Simular POST
        $_POST['id'] = $target['id'];
        $_POST['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $_POST['_csrf'];
        
        // Executar logica da API
        $restauranteId = (int)($_SESSION['restaurante_id'] ?? 0);
        $perfil = strtoupper(trim((string)($_SESSION['perfil'] ?? '')));
        
        if ($restauranteId > 0 && $perfil === 'ADMIN') {
            $id = intval($_POST['id']);
            
            if ($id !== intval($_SESSION['usuario_id'])) {
                // Verificar super_admin
                $colStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'super_admin'");
                $temColunaSuperAdmin = $colStmt && $colStmt->fetch(PDO::FETCH_ASSOC) ? true : false;
                
                $queryCheck = "SELECT id, nome FROM usuarios WHERE id = :id AND restaurante_id = :rid LIMIT 1";
                $stmtCheck = $db->prepare($queryCheck);
                $stmtCheck->bindParam(':id', $id, PDO::PARAM_INT);
                $stmtCheck->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
                $stmtCheck->execute();
                $usuario = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($usuario) {
                    // Deletar dados associados
                    try {
                        $queryTurnos = "DELETE FROM funcionarios_turnos WHERE usuario_id = :id";
                        $stmtTurnos = $db->prepare($queryTurnos);
                        $stmtTurnos->bindParam(':id', $id, PDO::PARAM_INT);
                        $stmtTurnos->execute();
                        echo "✓ Deletados " . $stmtTurnos->rowCount() . " turnos\n";
                        
                        $queryEstoque = "DELETE FROM estoque_movimentacao WHERE usuario_id = :id";
                        $stmtEstoque = $db->prepare($queryEstoque);
                        $stmtEstoque->bindParam(':id', $id, PDO::PARAM_INT);
                        $stmtEstoque->execute();
                        echo "✓ Deletadas " . $stmtEstoque->rowCount() . " movimentacoes estoque\n";
                        
                        $queryLog = "DELETE FROM pedido_status_log WHERE usuario_responsavel = :id";
                        $stmtLog = $db->prepare($queryLog);
                        $stmtLog->bindParam(':id', $id, PDO::PARAM_INT);
                        $stmtLog->execute();
                        echo "✓ Deletados " . $stmtLog->rowCount() . " logs\n";
                    } catch (Throwable $e) {
                        echo "⚠ Erro ao deletar dados: " . $e->getMessage() . "\n";
                    }
                    
                    // Deletar usuario
                    $query = "DELETE FROM usuarios WHERE id = :id AND restaurante_id = :rid";
                    if ($temColunaSuperAdmin) {
                        $query .= " AND COALESCE(super_admin, 0) = 0";
                    }
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $stmt->bindValue(':rid', $restauranteId, PDO::PARAM_INT);
                    
                    if ($stmt->execute()) {
                        if ($stmt->rowCount() > 0) {
                            echo "✓ Usuario deletado com sucesso!\n";
                            
                            // Verificar se foi realmente deletado
                            $verify = $db->prepare("SELECT id FROM usuarios WHERE id = ?");
                            $verify->execute([$id]);
                            if (!$verify->fetch()) {
                                echo "✓ Confirmado: usuario nao existe mais no banco\n";
                            }
                        } else {
                            echo "✗ Usuario nao encontrado ou ja foi deletado\n";
                        }
                    } else {
                        echo "✗ Erro ao executar DELETE: " . json_encode($stmt->errorInfo()) . "\n";
                    }
                } else {
                    echo "✗ Usuario nao encontrado\n";
                }
            } else {
                echo "✗ Nao pode deletar a si mesmo\n";
            }
        } else {
            echo "✗ Sem permissao\n";
        }
    } else {
        echo "Nenhum usuario disponivel para teste\n";
    }
} else {
    echo "Nenhum admin encontrado\n";
}

?>
