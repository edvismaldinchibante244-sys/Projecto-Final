<?php

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

// Função para buscar telefone do restaurante
function buscar_telefone_restaurante($db, $restaurante_id) {
    $stmt = $db->prepare("SELECT telefone FROM restaurantes WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $restaurante_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['telefone'] ?? null;
}
