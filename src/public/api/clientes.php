<?php
require_once __DIR__ . '/../app_base.php';

require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';

spl_autoload_register(static function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    // App\Model\Cliente -> APP_BASE/src/Model/Cliente.php
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = APP_BASE . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

header('Content-Type: application/json; charset=utf-8');

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE'],
    'rate_key' => 'clientes_api',
    'rate_max' => 120,
    'rate_window' => 60,
    'skip_csrf' => $requestMethod === 'GET',
]);

$db = (new Database())->getConnection();
$restauranteId = (int)$ctx['restaurante_id'];
$requestPath = trim((string)($_GET['route'] ?? ''), '/');

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

try {
    $modelCliente = new \App\Model\Cliente($db);
    $serviceCliente = new \App\Service\ClienteService($db, $modelCliente);

    if ($requestMethod === 'GET' && $requestPath === '') {
        $tipo = security_clean_string($_GET['tipo'] ?? 'todos', 20);
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $limite = max(1, min(100, (int)($_GET['limite'] ?? 10)));
        $offset = ($pagina - 1) * $limite;

        // Última visita efectiva: coluna CRM + reservas (id/contacto) + compras
        $query = "
            SELECT
                c.*,
                GREATEST(
                    COALESCE(c.data_ultima_visita, '1000-01-01'),
                    COALESCE((
                        SELECT MAX(r.data_reserva)
                        FROM reservas r
                        WHERE r.restaurante_id = c.restaurante_id
                          AND (
                            (r.cliente_id IS NOT NULL AND r.cliente_id = c.id)
                            OR (
                                c.telefone IS NOT NULL AND TRIM(c.telefone) <> ''
                                AND r.telefone_cliente IS NOT NULL
                                AND TRIM(r.telefone_cliente) = TRIM(c.telefone)
                            )
                            OR (
                                c.email IS NOT NULL AND TRIM(c.email) <> ''
                                AND r.email_cliente IS NOT NULL
                                AND LOWER(TRIM(r.email_cliente)) = LOWER(TRIM(c.email))
                            )
                          )
                    ), '1000-01-01'),
                    COALESCE((
                        SELECT MAX(DATE(ch.data_compra))
                        FROM cliente_historico_compras ch
                        WHERE ch.cliente_id = c.id
                          AND ch.restaurante_id = c.restaurante_id
                    ), '1000-01-01'),
                    COALESCE(c.data_primeira_visita, '1000-01-01')
                ) AS visita_calculada
            FROM clientes c
            WHERE c.restaurante_id = :restaurante_id
        ";
        if ($tipo !== '' && $tipo !== 'todos') {
            $query .= " AND c.tipo_cliente = :tipo ";
        }
        $query .= "
            AND COALESCE(c.ativo, 1) = 1
            ORDER BY visita_calculada DESC, c.id DESC
            LIMIT :limite OFFSET :offset
        ";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':restaurante_id', $restauranteId, PDO::PARAM_INT);
        if ($tipo !== '' && $tipo !== 'todos') {
            $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $calc = (string)($row['visita_calculada'] ?? '');
            if ($calc !== '' && $calc !== '1000-01-01') {
                $row['data_ultima_visita'] = $calc;
            } else {
                $row['data_ultima_visita'] = null;
            }
            unset($row['visita_calculada']);
        }
        unset($row);

        security_json([
            'success' => true,
            'data' => $rows,
            'pagina' => $pagina,
        ]);
    }

    if ($requestMethod === 'GET' && $requestPath === 'vips') {
        security_json([
            'success' => true,
            'data' => $modelCliente->obterVips($restauranteId, 20),
        ]);
    }

    if ($requestMethod === 'GET' && ctype_digit($requestPath)) {
        $clienteId = (int)$requestPath;
        $cliente = resource_require_cliente($db, $clienteId, $restauranteId);

        $stmtHistorico = $db->prepare("
            SELECT *
            FROM cliente_historico_compras
            WHERE cliente_id = :cliente_id
              AND restaurante_id = :restaurante_id
            ORDER BY data_compra DESC
            LIMIT 10
        ");
        $stmtHistorico->execute([
            ':cliente_id' => $clienteId,
            ':restaurante_id' => $restauranteId,
        ]);

        $estatisticas = [];
        $score = 0;
        try {
            $estatisticas = $modelCliente->calcularEstatisticas($clienteId) ?: [];
        } catch (Throwable $e) {
            error_log('clientes_api stats: ' . $e->getMessage());
        }
        try {
            $score = $serviceCliente->calcularScoreCliente($clienteId);
        } catch (Throwable $e) {
            error_log('clientes_api score: ' . $e->getMessage());
        }

        // Enriquecer ultima visita a partir de reservas/compras
        $tel = trim((string)($cliente['telefone'] ?? ''));
        $em = trim((string)($cliente['email'] ?? ''));
        $conds = ['(r.cliente_id IS NOT NULL AND r.cliente_id = :cid)'];
        $pRes = [':cid' => $clienteId, ':rid' => $restauranteId];
        if ($tel !== '') {
            $conds[] = '(r.telefone_cliente IS NOT NULL AND TRIM(r.telefone_cliente) = :tel)';
            $pRes[':tel'] = $tel;
        }
        if ($em !== '') {
            $conds[] = '(r.email_cliente IS NOT NULL AND LOWER(TRIM(r.email_cliente)) = LOWER(:em))';
            $pRes[':em'] = $em;
        }
        try {
            $stmtUlt = $db->prepare("
                SELECT MAX(r.data_reserva) AS d
                FROM reservas r
                WHERE r.restaurante_id = :rid
                  AND (" . implode(' OR ', $conds) . ")
            ");
            $stmtUlt->execute($pRes);
            $dRes = $stmtUlt->fetchColumn();
            $dComp = $estatisticas['data_ultima_visita'] ?? null;
            $dCol = $cliente['data_ultima_visita'] ?? null;
            $candidatos = array_filter([$dRes, $dComp, $dCol], static function ($v) {
                $s = substr((string)$v, 0, 10);
                return $s !== '' && $s !== '1000-01-01';
            });
            if ($candidatos) {
                $cliente['data_ultima_visita'] = max(array_map(static function ($v) {
                    return substr((string)$v, 0, 10);
                }, $candidatos));
            }
        } catch (Throwable $e) {
            error_log('clientes_api visita: ' . $e->getMessage());
        }

        security_json([
            'success' => true,
            'cliente' => $cliente,
            'estatisticas' => $estatisticas,
            'score_cliente' => $score,
            'historico_compras' => $stmtHistorico->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($requestMethod === 'POST' && $requestPath === '') {
        $nome = security_clean_string($input['nome'] ?? '', 120);
        $email = trim((string)($input['email'] ?? ''));
        $telefone = security_clean_string($input['telefone'] ?? '', 30);
        $tipoCliente = security_clean_string($input['tipo_cliente'] ?? 'comum', 20);

        if ($nome === '') {
            security_json(['success' => false, 'message' => 'Nome obrigatorio.'], 422);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            security_json(['success' => false, 'message' => 'Email invalido.'], 422);
        }

        $dados = [
            'restaurante_id' => $restauranteId,
            'nome' => $nome,
            'email' => $email !== '' ? $email : null,
            'telefone' => $telefone !== '' ? $telefone : null,
            'data_nascimento' => !empty($input['data_nascimento']) ? (string)$input['data_nascimento'] : null,
            'tipo_cliente' => $tipoCliente !== '' ? $tipoCliente : 'comum',
            'endereco' => security_clean_string($input['endereco'] ?? '', 180) ?: null,
            'cidade' => security_clean_string($input['cidade'] ?? '', 80) ?: null,
            'provincia' => security_clean_string($input['provincia'] ?? '', 80) ?: null,
            'pais' => security_clean_string($input['pais'] ?? '', 80) ?: null,
        ];

        if (!$modelCliente->criar($dados)) {
            security_json(['success' => false, 'message' => 'Erro ao criar cliente.'], 500);
        }

        $clienteId = (int)$db->lastInsertId();
        security_audit_event('cliente_criado', ['cliente_id' => $clienteId]);
        security_json([
            'success' => true,
            'message' => 'Cliente criado com sucesso.',
            'cliente_id' => $clienteId,
        ]);
    }

    if ($requestMethod === 'PUT' && ctype_digit($requestPath)) {
        $clienteId = (int)$requestPath;
        resource_require_cliente($db, $clienteId, $restauranteId, ['id']);

        $dados = [];
        foreach (['nome' => 120, 'telefone' => 30, 'tipo_cliente' => 20, 'endereco' => 180, 'cidade' => 80, 'provincia' => 80, 'pais' => 80] as $campo => $limite) {
            if (array_key_exists($campo, $input)) {
                $dados[$campo] = security_clean_string($input[$campo], $limite);
            }
        }
        if (array_key_exists('email', $input)) {
            $email = trim((string)$input['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                security_json(['success' => false, 'message' => 'Email invalido.'], 422);
            }
            $dados['email'] = $email;
        }

        $atualizado = $modelCliente->atualizar($clienteId, $dados);
        if ($atualizado) {
            security_audit_event('cliente_atualizado', ['cliente_id' => $clienteId]);
        }

        security_json([
            'success' => (bool)$atualizado,
            'message' => $atualizado ? 'Cliente atualizado.' : 'Erro ao atualizar cliente.',
        ], $atualizado ? 200 : 422);
    }

    if ($requestMethod === 'PUT' && preg_match('/^(\d+)\/bloquear$/', $requestPath, $matches)) {
        $clienteId = (int)$matches[1];
        resource_require_cliente($db, $clienteId, $restauranteId, ['id']);

        $motivo = security_clean_string($input['motivo'] ?? 'Bloqueado por administrador', 180);
        $bloqueado = $modelCliente->bloquear($clienteId, $motivo);
        if ($bloqueado) {
            security_audit_event('cliente_bloqueado', ['cliente_id' => $clienteId]);
        }

        security_json([
            'success' => (bool)$bloqueado,
            'message' => $bloqueado ? 'Cliente bloqueado.' : 'Erro ao bloquear cliente.',
        ], $bloqueado ? 200 : 422);
    }

    security_json(['success' => false, 'message' => 'Endpoint nao encontrado.'], 404);
} catch (Throwable $e) {
    error_log('clientes_api: ' . $e->getMessage());
    security_json(['success' => false, 'message' => 'Erro interno na API de clientes.'], 500);
}
