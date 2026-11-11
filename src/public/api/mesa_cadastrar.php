<?php
require_once __DIR__ . '/../app_base.php';

/*
  API - Cadastrar Nova Mesa
  Arquitetura N-Tier
 */

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/restaurante_context.php';

header('Content-Type: application/json');

api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'mesa_cadastrar',
    'rate_max' => 40,
    'rate_window' => 60,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_restaurante_contexto_id() <= 0) {
        echo json_encode(array("success" => false, "message" => "Não autenticado"));
        exit;
    }

    // Apenas ADMIN pode cadastrar mesas
    if (!in_array($_SESSION['perfil'] ?? '', ['ADMIN', 'GERENTE'], true)) {
        echo json_encode(array("success" => false, "message" => "Sem permissão"));
        exit;
    }

    $tipo = strtolower(trim((string)($_POST['tipo'] ?? 'mesa')));
    $numero = trim((string)($_POST['numero'] ?? ''));
    $capacidade = (int)($_POST['capacidade'] ?? 0);

    if ($tipo === 'balcao') {
        // Canal único automático; nome e capacidade não são configuráveis.
        $numero = '';
        $capacidade = 1;
    }

    if ($tipo !== 'balcao' && ($numero === '' || $capacidade <= 0)) {
        echo json_encode(array("success" => false, "message" => "Preencha todos os campos"));
        exit;
    }
    require_once APP_BASE . '/src/Controller/MesaController.php';

    $controller = new MesaController();

    $dados = array(
        'numero' => $numero,
        'capacidade' => $capacidade,
        'tipo' => $tipo,
    );

    $resultado = $controller->cadastrar($dados, session_restaurante_contexto_id(), session_restaurante_capability_id());
    echo json_encode($resultado);
} else {
    echo json_encode(array("success" => false, "message" => "Método não permitido"));
}

