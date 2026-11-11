<?php
require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

// API de busca e filtro de restaurantes
// Endpoint: /src/api/buscar_restaurantes.php

include_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$db = (new Database())->getConnection();

// Parâmetros de busca
$nome = trim($_GET['nome'] ?? '');
$cidade = trim($_GET['cidade'] ?? '');
$tipo = trim($_GET['tipo'] ?? ''); // Ex: pizzaria, churrascaria, etc

$query = "SELECT id, nome, cidade, tipo_cozinha, telefone, status FROM restaurantes WHERE status = 'ATIVO'";
$params = [];

if ($nome) {
    $query .= " AND nome LIKE :nome";
    $params[':nome'] = "%$nome%";
}
if ($cidade) {
    $query .= " AND cidade LIKE :cidade";
    $params[':cidade'] = "%$cidade%";
}
if ($tipo) {
    $query .= " AND tipo_cozinha LIKE :tipo";
    $params[':tipo'] = "%$tipo%";
}

$query .= " ORDER BY nome ASC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$restaurantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'data' => $restaurantes,
    'total' => count($restaurantes)
]);
