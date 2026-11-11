<?php

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/plano_check.php';
require_once __DIR__ . '/../config/reserva_qr_schema.php';
require_once __DIR__ . '/../config/mesa_display_helper.php';
require_once __DIR__ . '/../config/migration_state.php';
require_once __DIR__ . '/../config/pedido_schema.php';
require_once __DIR__ . '/pre_pedido_reserva.php';
require_once __DIR__ . '/../Model/Reserva.php';
require_once __DIR__ . '/../Service/ReservaService.php';
require_once __DIR__ . '/notificar_cliente_whatsapp.php';
require_once __DIR__ . '/utils_restaurante.php';

if (!defined('PEDIDO_QR_META_SCHEMA_MIGRATION_ID')) {
    define('PEDIDO_QR_META_SCHEMA_MIGRATION_ID', '20260815_0900_pedido_qr_meta');
}

function reserva_publica_qr_pedido_schema(PDO $db): void
{
    static $initialized = [];
    $key = spl_object_id($db);
    if (isset($initialized[$key])) {
        return;
    }
    if (migration_esta_aplicada($db, PEDIDO_QR_META_SCHEMA_MIGRATION_ID)) {
        $initialized[$key] = true;
        return;
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS pedido_qr_meta (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pedido_id BIGINT UNSIGNED NOT NULL,
            restaurante_id INT NOT NULL,
            token VARCHAR(128) NOT NULL,
            idem_key VARCHAR(128) NOT NULL,
            cliente_nome VARCHAR(160) NULL,
            cliente_telefone VARCHAR(60) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_qr_idem (restaurante_id, token, idem_key),
            INDEX idx_qr_token_created (token, created_at),
            INDEX idx_qr_pedido (pedido_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $stmtCol = $db->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'itens_pedido'
              AND COLUMN_NAME = 'observacao'
            LIMIT 1
        ");
        $stmtCol->execute();
        if (!$stmtCol->fetchColumn()) {
            $db->exec("ALTER TABLE itens_pedido ADD COLUMN observacao VARCHAR(255) NULL AFTER subtotal");
        }
    } catch (Throwable $e) {
        // Não bloquear pedido se a coluna já existir ou schema legado divergir.
    }

    $initialized[$key] = true;
}

function consultar_disponibilidade_publica(array $dados, ?PDO $db = null): array
{
    $restauranteId = (int)($dados['restaurante_id'] ?? 0);
    $dataReserva = trim((string)($dados['data'] ?? $dados['data_reserva'] ?? ''));
    $horaReserva = trim((string)($dados['hora'] ?? $dados['hora_reserva'] ?? ''));
    $quantidade = (int)($dados['quantidade'] ?? $dados['quantidade_pessoas'] ?? 0);

    if ($restauranteId <= 0) {
        return [
            'success' => false,
            'message' => 'Selecione o restaurante para consultar a disponibilidade.',
            'http_code' => 422,
        ];
    }

    if (!reserva_publica_data_valida($dataReserva)) {
        return [
            'success' => false,
            'message' => 'Data da reserva invalida.',
            'http_code' => 422,
        ];
    }

    if (!reserva_publica_hora_valida($horaReserva)) {
        return [
            'success' => false,
            'message' => 'Hora da reserva invalida.',
            'http_code' => 422,
        ];
    }

    if ($quantidade <= 0) {
        return [
            'success' => false,
            'message' => 'Informe uma quantidade de pessoas valida.',
            'http_code' => 422,
        ];
    }

    $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'Africa/Maputo');
    $dataHoraReserva = \DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $dataReserva . ' ' . substr($horaReserva, 0, 5),
        $timezone
    );

    if (!$dataHoraReserva || $dataHoraReserva < new \DateTimeImmutable('now', $timezone)) {
        return [
            'success' => false,
            'message' => 'Escolha uma data e hora futuras para consultar a disponibilidade.',
            'http_code' => 422,
        ];
    }

    try {
        $db = $db ?? (new Database())->getConnection();

        $stmtRestaurante = $db->prepare("SELECT id FROM restaurantes WHERE id = :id LIMIT 1");
        $stmtRestaurante->execute([':id' => $restauranteId]);
        if (!$stmtRestaurante->fetch(PDO::FETCH_ASSOC)) {
            return [
                'success' => false,
                'message' => 'Restaurante nao encontrado.',
                'http_code' => 404,
            ];
        }

        $modelReserva = new \App\Model\Reserva($db);
        $serviceReserva = new \App\Service\ReservaService($db, $modelReserva);
        $mesasDisponiveis = $serviceReserva->validarDisponibilidade(
            $restauranteId,
            $dataReserva,
            $horaReserva,
            $quantidade
        );

        $total = count($mesasDisponiveis);
        $mensagem = $total > 0
            ? $total . ' mesa(s) disponivel(is) para este horario.'
            : 'Nenhuma mesa disponivel para este horario e quantidade.';

        return [
            'success' => true,
            'message' => $mensagem,
            'mesas_disponiveis' => $mesasDisponiveis,
            'total' => $total,
            'http_code' => 200,
        ];
    } catch (\Throwable $e) {
        error_log('[RESERVA_PUBLICA][DISPONIBILIDADE][ERROR] ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro interno ao consultar a disponibilidade.',
            'http_code' => 500,
        ];
    }
}

function consultar_contexto_qr_publico(array $dados, ?PDO $db = null): array
{
    $token = trim((string)($dados['token'] ?? ''));
    if ($token === '') {
        return [
            'success' => false,
            'message' => 'Sessao QR invalida.',
            'http_code' => 422,
        ];
    }

    try {
        $db = $db ?? (new Database())->getConnection();
        reserva_qr_cleanup($db);

        $sessao = reserva_qr_get_session_by_token($db, $token, true);
        if (!$sessao) {
            return [
                'success' => false,
                'message' => 'Esta sessao QR expirou ou nao existe mais.',
                'http_code' => 404,
            ];
        }

        $stmtMesas = $db->prepare("
            SELECT id, numero, capacidade, status
            FROM mesas
            WHERE restaurante_id = :restaurante_id
            ORDER BY numero ASC
        ");
        $stmtMesas->execute([':restaurante_id' => (int)$sessao['restaurante_id']]);
        $mesas = array_values(array_filter(
            $stmtMesas->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn(array $mesa): bool => !mesa_eh_balcao($mesa['numero'] ?? '')
        ));

        $cardapio = consultar_cardapio_publico([
            'restaurante_id' => (int)$sessao['restaurante_id'],
        ], $db);

        $hold = reserva_qr_get_hold_by_token($db, $token);

        return [
            'success' => true,
            'http_code' => 200,
            'sessao' => [
                'token' => $token,
                'expires_at' => $sessao['expires_at'],
                'mesa_id' => !empty($sessao['mesa_id']) ? (int)$sessao['mesa_id'] : null,
                'mesa_numero' => $sessao['mesa_numero'] ?? null,
                'telefone_cliente' => $sessao['telefone_cliente'] ?? null,
                'nome_atendente' => $sessao['nome_atendente'] ?? null,
                'consumed_at' => $sessao['consumed_at'] ?? null,
            ],
            'restaurante' => [
                'id' => (int)$sessao['restaurante_id'],
                'nome' => (string)$sessao['restaurante_nome'],
                'cidade' => (string)($sessao['restaurante_cidade'] ?? ''),
                'logo' => reserva_publica_normalizar_logo_restaurante($sessao['restaurante_logo'] ?? null),
            ],
            'mesas' => $mesas,
            'cardapio' => $cardapio,
            'pre_bloqueio' => $hold,
        ];
    } catch (\Throwable $e) {
        error_log('[RESERVA_PUBLICA][CONTEXTO_QR][ERROR] ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro interno ao carregar a sessao QR.',
            'http_code' => 500,
        ];
    }
}

function prebloquear_reserva_publica(array $dados, ?PDO $db = null): array
{
    $token = trim((string)($dados['token'] ?? ''));
    if ($token === '') {
        return [
            'success' => false,
            'message' => 'Sessao QR invalida para pre-bloqueio.',
            'http_code' => 422,
        ];
    }

    try {
        $db = $db ?? (new Database())->getConnection();
        reserva_qr_cleanup($db);

        $sessao = reserva_qr_get_session_by_token($db, $token, true);
        if (!$sessao) {
            return [
                'success' => false,
                'message' => 'Esta sessao QR expirou ou nao esta mais disponivel.',
                'http_code' => 404,
            ];
        }

        $mesaPreBloqueioId = !empty($dados['mesa_id'])
            ? (int)$dados['mesa_id']
            : (!empty($sessao['mesa_id']) ? (int)$sessao['mesa_id'] : 0);
        if ($mesaPreBloqueioId > 0) {
            $stmtMesaPre = $db->prepare("
                SELECT numero FROM mesas
                WHERE id = :id AND restaurante_id = :rid
                LIMIT 1
            ");
            $stmtMesaPre->execute([
                ':id' => $mesaPreBloqueioId,
                ':rid' => (int)$sessao['restaurante_id'],
            ]);
            $numeroMesaPre = $stmtMesaPre->fetchColumn();
            if ($numeroMesaPre !== false && mesa_eh_balcao($numeroMesaPre)) {
                return [
                    'success' => false,
                    'message' => 'O Balcão / Take-away não pode ser pré-bloqueado.',
                    'http_code' => 422,
                ];
            }
        }

        $serviceReserva = new \App\Service\ReservaService($db, new \App\Model\Reserva($db));
        $resultado = $serviceReserva->criarPreBloqueio(
            (int)$sessao['restaurante_id'],
            $token,
            trim((string)($dados['data'] ?? $dados['data_reserva'] ?? '')),
            trim((string)($dados['hora'] ?? $dados['hora_reserva'] ?? '')),
            (int)($dados['quantidade'] ?? $dados['quantidade_pessoas'] ?? 0),
            $mesaPreBloqueioId > 0 ? $mesaPreBloqueioId : null,
            (int)($dados['duracao_minutos'] ?? 5)
        );
        if (!empty($resultado['expires_at']) && function_exists('reserva_qr_utc_para_local')) {
            $resultado['expires_at_local'] = reserva_qr_utc_para_local((string)$resultado['expires_at'], 'H:i');
        }

        $resultado['http_code'] = !empty($resultado['success']) ? 200 : reserva_publica_http_code($resultado);
        return $resultado;
    } catch (\Throwable $e) {
        error_log('[RESERVA_PUBLICA][PRE_BLOQUEIO][ERROR] ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro interno ao pre-bloquear a mesa.',
            'http_code' => 500,
        ];
    }
}

function consultar_cardapio_publico(array $dados, ?PDO $db = null): array
{
    $restauranteId = (int)($dados['restaurante_id'] ?? $dados['rid'] ?? 0);
    if ($restauranteId <= 0) {
        return [
            'success' => false,
            'message' => 'Selecione o restaurante para visualizar o cardapio.',
            'http_code' => 422,
        ];
    }

    try {
        $db = $db ?? (new Database())->getConnection();

        $stmtRestaurante = $db->prepare("
            SELECT id, nome, cidade
            FROM restaurantes
            WHERE id = :id
            LIMIT 1
        ");
        $stmtRestaurante->execute([':id' => $restauranteId]);
        $restaurante = $stmtRestaurante->fetch(PDO::FETCH_ASSOC);

        if (!$restaurante) {
            return [
                'success' => false,
                'message' => 'Restaurante nao encontrado.',
                'http_code' => 404,
            ];
        }

        $stmtProdutos = $db->prepare("
            SELECT
                p.id,
                p.nome,
                p.descricao,
                p.preco,
                p.imagem,
                c.nome AS categoria_nome
            FROM produtos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE p.restaurante_id = :restaurante_id
              AND p.ativo = 1
            ORDER BY c.nome ASC, p.nome ASC
        ");
        $stmtProdutos->execute([':restaurante_id' => $restauranteId]);
        $produtos = $stmtProdutos->fetchAll(PDO::FETCH_ASSOC);

        $preview = [];
        foreach ($produtos as $produto) {
            $categoriaNome = trim((string)($produto['categoria_nome'] ?? ''));
            if ($categoriaNome === '') {
                $categoriaNome = 'Outros';
            }

            if (!isset($preview[$categoriaNome])) {
                if (count($preview) >= 3) {
                    continue;
                }

                $preview[$categoriaNome] = [
                    'nome' => $categoriaNome,
                    'itens' => [],
                ];
            }

            if (count($preview[$categoriaNome]['itens']) >= 4) {
                continue;
            }

            $preview[$categoriaNome]['itens'][] = [
                'id' => (int)$produto['id'],
                'nome' => (string)$produto['nome'],
                'descricao' => (string)($produto['descricao'] ?? ''),
                'preco' => (float)$produto['preco'],
                'imagem' => reserva_publica_normalizar_imagem_produto($produto['imagem'] ?? null),
            ];
        }

        return [
            'success' => true,
            'restaurante' => [
                'id' => (int)$restaurante['id'],
                'nome' => (string)$restaurante['nome'],
                'cidade' => (string)($restaurante['cidade'] ?? ''),
            ],
            'cardapio_preview' => array_values($preview),
            'total_produtos' => count($produtos),
            'cardapio_url' => 'cardapio.php?rid=' . $restauranteId,
            'message' => empty($produtos)
                ? 'Este restaurante ainda nao publicou produtos no cardapio.'
                : 'Preview do cardapio carregado com sucesso.',
            'http_code' => 200,
        ];
    } catch (\Throwable $e) {
        error_log('[RESERVA_PUBLICA][CARDAPIO][ERROR] ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro interno ao carregar o cardapio.',
            'http_code' => 500,
        ];
    }
}

function reserva_publica_normalizar_logo_restaurante($logo): ?string
{
    $logo = trim((string)$logo);
    if ($logo === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $logo)) {
        return $logo;
    }

    $logo = str_replace('\\', '/', $logo);
    $logo = preg_replace('#^(\./)+#', '', $logo);
    $logo = preg_replace('#^/?src/public/#i', '', $logo);
    $logo = ltrim($logo, '/');

    return $logo !== '' ? $logo : null;
}

function reserva_publica_normalizar_imagem_produto($imagem): ?string
{
    $imagem = trim((string)$imagem);
    if ($imagem === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $imagem)) {
        return $imagem;
    }

    $imagem = str_replace('\\', '/', $imagem);
    $imagem = preg_replace('#^(\./)+#', '', $imagem);
    $imagem = preg_replace('#^/?src/public/#i', '', $imagem);
    $imagem = ltrim($imagem, '/');

    if ($imagem === '') {
        return null;
    }

    // Caminho real do diretório público (fora de app/)
    $publicRoot = realpath(dirname(__DIR__, 2) . '/../src/public');
    if ($publicRoot === false) {
        return null;
    }

    $arquivoPublico = $publicRoot . DIRECTORY_SEPARATOR . $imagem;
    if (!is_file($arquivoPublico)) {
        return null;
    }

    // Retorna caminho relativo que o front resolverá a partir de /src/public
    return $imagem;
}

function reserva_publica_verificar_acesso_plano(int $restauranteId): array
{
    if (!function_exists('plano_get_dados') || !function_exists('plano_tem_funcionalidade_db')) {
        return ['success' => true];
    }

    $dadosPlano = plano_get_dados($restauranteId);
    $planoNome = strtoupper((string)($dadosPlano['plano_nome'] ?? 'BASICO'));
    $temAcesso = plano_tem_funcionalidade_db($restauranteId, 'reservas_qr');

    if (!$temAcesso) {
        return [
            'success' => false,
            'message' => 'As reservas públicas só estão disponíveis para o plano Empresarial.',
            'http_code' => 403,
        ];
    }

    return ['success' => true, 'http_code' => 200];
}

function criar_reserva_publica(array $dados, ?PDO $db = null): array
{
    try {
        $db = $db ?? (new Database())->getConnection();
        reserva_qr_cleanup($db);

        $restauranteId = (int)($dados['restaurante_id'] ?? 0);
        $verificacaoPlano = reserva_publica_verificar_acesso_plano($restauranteId);
        if (!empty($verificacaoPlano['success']) && !($verificacaoPlano['success'] ?? true)) {
            return $verificacaoPlano;
        }

        $tokenSessao = trim((string)($dados['token'] ?? $dados['sessao_token'] ?? ''));
        if ($tokenSessao !== '') {
            $sessaoQr = reserva_qr_get_session_by_token($db, $tokenSessao);
            if (!$sessaoQr) {
                return [
                    'success' => false,
                    'message' => 'A sessao QR informada expirou. Solicite um novo QR Code ao restaurante.',
                    'http_code' => 410,
                ];
            }

            $dados['restaurante_id'] = (int)$sessaoQr['restaurante_id'];
            $dados['origem'] = 'qr';
            $dados['sessao_token'] = $tokenSessao;

            if (empty($dados['mesa_atribuida']) && !empty($sessaoQr['mesa_id'])) {
                $dados['mesa_atribuida'] = (int)$sessaoQr['mesa_id'];
            }

            $holdAtual = reserva_qr_get_hold_by_token($db, $tokenSessao);
            if (empty($dados['mesa_atribuida']) && !empty($holdAtual['mesa_id'])) {
                $dados['mesa_atribuida'] = (int)$holdAtual['mesa_id'];
            }
        }

        $modelReserva = new \App\Model\Reserva($db);
        $serviceReserva = new \App\Service\ReservaService($db, $modelReserva);

        $mesaReservaId = (int)($dados['mesa_atribuida'] ?? 0);
        if ($mesaReservaId > 0) {
            $stmtMesaReserva = $db->prepare("
                SELECT numero
                FROM mesas
                WHERE id = :id AND restaurante_id = :rid
                LIMIT 1
            ");
            $stmtMesaReserva->execute([
                ':id' => $mesaReservaId,
                ':rid' => (int)$dados['restaurante_id'],
            ]);
            $numeroMesaReserva = $stmtMesaReserva->fetchColumn();
            if ($numeroMesaReserva !== false && mesa_eh_balcao($numeroMesaReserva)) {
                return [
                    'success' => false,
                    'message' => 'O Balcão / Take-away não pode receber reservas.',
                    'http_code' => 422,
                ];
            }
        }

        $resultado = $serviceReserva->criarReserva($dados);
        if (!($resultado['success'] ?? false)) {
            $resultado['http_code'] = reserva_publica_http_code($resultado);
            return reserva_publica_sem_detalhe_interno($resultado, 'CRIAR');
        }

        if ($tokenSessao !== '' && !empty($sessaoQr['id'])) {
            try {
                reserva_qr_ligar_reserva_sessao($db, (int)$dados['restaurante_id'], (int)($resultado['reserva_id'] ?? 0), (int)$sessaoQr['id'], $tokenSessao);
            } catch (\Throwable $e) {
                error_log('[RESERVA_PUBLICA][LIGAR_PRE_PEDIDO] ' . $e->getMessage());
            }
        }

        try {
            reserva_publica_notificar_cliente(
                $dados['telefone_cliente'] ?? null,
                $dados['nome_cliente'] ?? null
            );

            reserva_publica_notificar_restaurante(
                $db,
                (int)($dados['restaurante_id'] ?? 0),
                $dados,
                $resultado
            );

            if ($tokenSessao !== '') {
                reserva_qr_mark_session_consumed($db, $tokenSessao);
            }
        } catch (\Throwable $e) {
            // Reserva ja gravada: falhas aqui nao devem virar erro para o cliente.
            error_log('[RESERVA_PUBLICA][POS_COMMIT] ' . $e->getMessage());
        }

        $resultado['message'] = 'Reserva criada com sucesso! Aguarde confirmacao do restaurante.';
        $resultado['http_code'] = 200;

        return $resultado;
    } catch (\InvalidArgumentException $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'http_code' => 422,
        ];
    } catch (\Throwable $e) {
        error_log('[RESERVA_PUBLICA][ERROR] ' . $e->getMessage());

        return [
            'success' => false,
            'message' => 'Erro interno ao criar a reserva.',
            'http_code' => 500,
        ];
    }
}

function reserva_qr_resolver_mesa_pedido(PDO $db, array $sessao, ?array $hold, int $mesaIdInput, int $restauranteId): array
{
    if ($hold && !empty($hold['mesa_id'])) {
        $mesaHold = (int)$hold['mesa_id'];
        $mesaId = $mesaIdInput > 0 ? $mesaIdInput : $mesaHold;
        if ($mesaId !== $mesaHold) {
            return [
                'ok' => false,
                'message' => 'A mesa do pedido deve ser a mesma do pre-bloqueio.',
                'http_code' => 422,
            ];
        }

        return ['ok' => true, 'mesa_id' => $mesaId];
    }

    $mesaSessao = !empty($sessao['mesa_id']) ? (int)$sessao['mesa_id'] : 0;
    if ($mesaSessao > 0) {
        $mesaId = $mesaIdInput > 0 ? $mesaIdInput : $mesaSessao;
        if ($mesaId !== $mesaSessao) {
            return [
                'ok' => false,
                'message' => 'A mesa do pedido deve ser a mesma da sessao QR.',
                'http_code' => 422,
            ];
        }

        return ['ok' => true, 'mesa_id' => $mesaId];
    }

    if ($mesaIdInput > 0) {
        $stmtMesa = $db->prepare('SELECT id FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1');
        $stmtMesa->execute([':id' => $mesaIdInput, ':rid' => $restauranteId]);
        if ($stmtMesa->fetch(PDO::FETCH_ASSOC)) {
            return ['ok' => true, 'mesa_id' => $mesaIdInput];
        }

        return [
            'ok' => false,
            'message' => 'Mesa nao encontrada para este restaurante.',
            'http_code' => 404,
        ];
    }

    // Takeaway / cardápio sem mesa (PRO+): usar mesa Balcão
    $obsSessao = strtolower((string)($sessao['observacoes'] ?? ''));
    $permiteTakeaway = (strpos($obsSessao, 'takeaway') !== false)
        || (strpos($obsSessao, 'balcao') !== false)
        || (strpos($obsSessao, 'cardapio') !== false)
        || empty($sessao['mesa_id']);
    if ($permiteTakeaway) {
        $mesaBalcao = reserva_publica_garantir_mesa_balcao($db, $restauranteId);
        if ($mesaBalcao) {
            return ['ok' => true, 'mesa_id' => $mesaBalcao, 'takeaway' => true];
        }
    }

    return [
        'ok' => false,
        'message' => 'Informe a mesa no formulario ou envie a reserva antes do pedido.',
        'http_code' => 422,
    ];
}

/**
 * Garante mesa "Balcão" / takeaway para pedidos QR sem mesa.
 */
function reserva_publica_garantir_mesa_balcao(PDO $db, int $restauranteId): ?int
{
    if ($restauranteId <= 0) {
        return null;
    }

    try {
        $balcao = mesa_garantir_balcao_canonico($db, $restauranteId);
        $id = (int)($balcao['id'] ?? 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        error_log('[RESERVA_PUBLICA][MESA_BALCAO] ' . $e->getMessage());
        return null;
    }
}

function reserva_publica_status_mesa_apos_pedido_qr(PDO $db, int $restauranteId, int $mesaId): string
{
    if ($restauranteId <= 0 || $mesaId <= 0) {
        return 'OCUPADA';
    }

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM reservas
        WHERE restaurante_id = :rid
          AND mesa_atribuida = :mesa_id
          AND data_reserva = CURDATE()
          AND status IN ('pendente', 'confirmado')
    ");
    $stmt->execute([
        ':rid' => $restauranteId,
        ':mesa_id' => $mesaId,
    ]);

    return (int)$stmt->fetchColumn() > 0 ? 'RESERVADA' : 'OCUPADA';
}

function criar_pedido_qr_publico(array $dados, ?PDO $db = null): array
{
    $token = trim((string)($dados['token'] ?? ''));
    $itens = $dados['itens'] ?? [];
    $observacao = trim((string)($dados['observacao'] ?? ''));
    $mesaIdInput = (int)($dados['mesa_id'] ?? $dados['mesa_atribuida'] ?? 0);
    $idemKey = trim((string)($dados['idem_key'] ?? ''));
    $clienteNome = trim((string)($dados['cliente_nome'] ?? $dados['nome_cliente'] ?? ''));
    $clienteTelefone = trim((string)($dados['cliente_telefone'] ?? $dados['telefone_cliente'] ?? ''));

    if ($token === '') {
        return ['success' => false, 'message' => 'Token QR invalido.', 'http_code' => 422];
    }
    if ($idemKey === '' || strlen($idemKey) < 8) {
        return ['success' => false, 'message' => 'Chave de idempotencia invalida.', 'http_code' => 422];
    }
    if (!is_array($itens) || empty($itens)) {
        return ['success' => false, 'message' => 'Selecione pelo menos 1 item.', 'http_code' => 422];
    }

    try {
        $db = $db ?? (new Database())->getConnection();
        reserva_publica_qr_pedido_schema($db);
        pedido_sequencia_garantir($db); // fora da transacao (DDL)
        reserva_qr_cleanup($db);

        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        if (!security_rate_limit('pedido_qr_ip_' . $ip, 20, 60)) {
            return ['success' => false, 'message' => 'Muitas tentativas deste IP. Aguarde.', 'http_code' => 429];
        }
        if (!security_rate_limit('pedido_qr_token_' . $token, 10, 60)) {
            return ['success' => false, 'message' => 'Muitas tentativas para este QR. Aguarde.', 'http_code' => 429];
        }

        $sessao = reserva_qr_get_session_by_token($db, $token, true);
        if (!$sessao) {
            return ['success' => false, 'message' => 'Sessao QR expirada.', 'http_code' => 410];
        }

        $restauranteId = (int)($sessao['restaurante_id'] ?? 0);
        if ($restauranteId <= 0) {
            return ['success' => false, 'message' => 'Restaurante invalido para o token.', 'http_code' => 422];
        }

        $hold = reserva_qr_get_hold_by_token($db, $token);
        $mesaResolvida = reserva_qr_resolver_mesa_pedido($db, $sessao, $hold, $mesaIdInput, $restauranteId);
        if (empty($mesaResolvida['ok'])) {
            return [
                'success' => false,
                'message' => (string)($mesaResolvida['message'] ?? 'Nao foi possivel definir a mesa do pedido.'),
                'http_code' => (int)($mesaResolvida['http_code'] ?? 422),
            ];
        }

        $mesaId = (int)($mesaResolvida['mesa_id'] ?? 0);
        if ($mesaId <= 0) {
            return ['success' => false, 'message' => 'Escolha uma mesa para enviar pedido.', 'http_code' => 422];
        }

        $stmtMesa = $db->prepare("SELECT id, numero, garcom_id FROM mesas WHERE id = :id AND restaurante_id = :rid LIMIT 1");
        $stmtMesa->execute([':id' => $mesaId, ':rid' => $restauranteId]);
        $mesa = $stmtMesa->fetch(PDO::FETCH_ASSOC);
        if (!$mesa) {
            return ['success' => false, 'message' => 'Mesa nao encontrada.', 'http_code' => 404];
        }

        require_once APP_BASE . '/src/config/promocao_schema.php';
        $itens = promocao_expandir_linhas($db, $restauranteId, $itens);

        $produtoIds = [];
        $qtdByProduto = [];
        $linhasPedido = [];
        foreach ($itens as $item) {
            $pid = (int)($item['produto_id'] ?? $item['id'] ?? 0);
            $qtd = (int)($item['quantidade'] ?? $item['qtd'] ?? 0);
            if ($pid <= 0 || $qtd <= 0) {
                continue;
            }
            $qtd = max(1, min(50, $qtd));
            $obsItem = trim((string)($item['observacao'] ?? $item['note'] ?? $item['obs'] ?? ''));
            if (function_exists('mb_substr')) {
                $obsItem = mb_substr($obsItem, 0, 120);
            } else {
                $obsItem = substr($obsItem, 0, 120);
            }
            $produtoIds[] = $pid;
            $qtdByProduto[$pid] = ($qtdByProduto[$pid] ?? 0) + $qtd;
            $linhasPedido[] = [
                'produto_id' => $pid,
                'qtd' => $qtd,
                'observacao' => $obsItem,
                '_promocao_id' => (int)($item['_promocao_id'] ?? 0),
                '_promocao_nome' => (string)($item['_promocao_nome'] ?? ''),
                '_preco_override' => $item['_preco_override'] ?? null,
                '_subtotal_override' => $item['_subtotal_override'] ?? null,
            ];
        }
        $produtoIds = array_values(array_unique($produtoIds));
        if (empty($produtoIds) || empty($linhasPedido)) {
            return ['success' => false, 'message' => 'Itens invalidos.', 'http_code' => 422];
        }

        $in = implode(',', array_fill(0, count($produtoIds), '?'));
        $stmtProdutos = $db->prepare("
            SELECT p.id, p.nome, p.preco, p.estoque, c.nome AS categoria_nome
            FROM produtos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE p.restaurante_id = ? AND p.ativo = 1 AND p.id IN ($in)
        ");
        $stmtProdutos->execute(array_merge([$restauranteId], $produtoIds));
        $produtos = $stmtProdutos->fetchAll(PDO::FETCH_ASSOC);
        if (empty($produtos)) {
            return ['success' => false, 'message' => 'Nenhum produto valido para este restaurante.', 'http_code' => 422];
        }

        $temEstoqueAvancado = function_exists('plano_tem_funcionalidade_db')
            && plano_tem_funcionalidade_db($restauranteId, 'estoque_avancado');

        $produtoMap = [];
        foreach ($produtos as $p) {
            $pid = (int)$p['id'];
            $produtoMap[$pid] = $p;
            if (!$temEstoqueAvancado && array_key_exists('estoque', $p) && $p['estoque'] !== null) {
                $stock = (int)$p['estoque'];
                $need = (int)($qtdByProduto[$pid] ?? 0);
                if ($stock <= 0) {
                    return [
                        'success' => false,
                        'message' => 'O item "' . ($p['nome'] ?? '') . '" está esgotado.',
                        'http_code' => 409,
                    ];
                }
                if ($need > $stock) {
                    return [
                        'success' => false,
                        'message' => 'Stock insuficiente para "' . ($p['nome'] ?? '') . '" (disponível: ' . $stock . ').',
                        'http_code' => 409,
                    ];
                }
            }
        }

        $normalizar = static function ($texto) {
            $txt = strtolower(trim((string)$texto));
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
            if ($ascii !== false) {
                $txt = strtolower($ascii);
            }
            return $txt;
        };
        $inferDestino = static function ($categoria, $nome) use ($normalizar) {
            $base = $normalizar(($categoria ?? '') . ' ' . ($nome ?? ''));
            $keys = ['bebida', 'bar', 'drink', 'sumo', 'suco', 'refrigerante', 'agua', 'cerveja', 'vinho', 'whisky', 'rum', 'vodka', 'gin', 'licor', 'cha', 'te', 'cafe', 'espresso', 'latte', 'milkshake', 'energetico', 'coca', 'pepsi', 'fanta'];
            foreach ($keys as $k) {
                if (strpos($base, $k) !== false) {
                    return 'bar';
                }
            }
            return 'cozinha';
        };

        $itensNormalizados = [];
        $total = 0.0;
        $obsItensParts = [];
        $temColObsItem = false;
        try {
            $chkObs = $db->prepare("
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'itens_pedido'
                  AND COLUMN_NAME = 'observacao'
                LIMIT 1
            ");
            $chkObs->execute();
            $temColObsItem = (bool)$chkObs->fetchColumn();
        } catch (Throwable $e) {
            $temColObsItem = false;
        }

        foreach ($linhasPedido as $linha) {
            $pid = (int)$linha['produto_id'];
            if (!isset($produtoMap[$pid])) {
                continue;
            }
            $p = $produtoMap[$pid];
            $qtd = (int)$linha['qtd'];
            $preco = $linha['_preco_override'] !== null ? (float)$linha['_preco_override'] : (float)($p['preco'] ?? 0);
            $subtotal = $linha['_subtotal_override'] !== null ? (float)$linha['_subtotal_override'] : $preco * $qtd;
            $obsItem = (string)($linha['observacao'] ?? '');
            $promoNome = trim((string)($linha['_promocao_nome'] ?? ''));
            $itensNormalizados[] = [
                'produto_id' => $pid,
                'qtd' => $qtd,
                'preco' => $preco,
                'subtotal' => $subtotal,
                'destino' => $inferDestino($p['categoria_nome'] ?? '', $p['nome'] ?? ''),
                'observacao' => $obsItem,
                'produto_nome' => $promoNome !== '' ? ('[' . $promoNome . '] ' . ($p['nome'] ?? '')) : ($p['nome'] ?? ''),
                'promocao_id' => (int)($linha['_promocao_id'] ?? 0),
            ];
            $total += $subtotal;
            if ($obsItem !== '' && !$temColObsItem) {
                $obsItensParts[] = ($p['nome'] ?? ('#' . $pid)) . ': ' . $obsItem;
            }
        }
        if (empty($itensNormalizados)) {
            return ['success' => false, 'message' => 'Itens nao encontrados no cardapio ativo.', 'http_code' => 422];
        }

        $stmtIdemExists = $db->prepare("
            SELECT m.pedido_id, p.numero_pedido, p.total, p.status
            FROM pedido_qr_meta m
            INNER JOIN pedidos p ON p.id = m.pedido_id AND p.restaurante_id = m.restaurante_id
            WHERE m.restaurante_id = :rid AND m.token = :token AND m.idem_key = :idem
            LIMIT 1
        ");
        $stmtIdemExists->execute([':rid' => $restauranteId, ':token' => $token, ':idem' => $idemKey]);
        $dup = $stmtIdemExists->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            return [
                'success' => true,
                'message' => 'Pedido ja recebido anteriormente.',
                'pedido_id' => (int)$dup['pedido_id'],
                'numero_pedido' => (string)$dup['numero_pedido'],
                'total' => number_format((float)$dup['total'], 2, ',', '.'),
                'status' => (string)$dup['status'],
                'idempotent' => true,
                'http_code' => 200,
            ];
        }

        // Reserva feita com este QR e cliente ainda nao chegou: o carrinho fica guardado
        // e so vai para a cozinha no check-in (ReservaService::fazerCheckIn).
        $reservaEmEspera = reserva_pre_pedido_reserva_em_espera($db, $restauranteId, (int)($sessao['id'] ?? 0));
        if ($reservaEmEspera > 0) {
            return reserva_pre_pedido_guardar($db, $reservaEmEspera, compact(
                'restauranteId', 'mesaId', 'observacao', 'obsItensParts', 'clienteNome',
                'clienteTelefone', 'total', 'temColObsItem', 'itensNormalizados', 'temEstoqueAvancado', 'token', 'idemKey'
            ));
        }

        pedido_sequencia_preparar($db, $restauranteId); // fora da transacao
        $db->beginTransaction();

        // Numero atomico: pedidos simultaneos nunca recebem o mesmo numero.
        $gravado = reserva_pre_pedido_gravar_pedido($db, compact(
            'restauranteId', 'mesaId', 'mesa', 'observacao', 'obsItensParts', 'clienteNome',
            'clienteTelefone', 'total', 'temColObsItem', 'itensNormalizados', 'temEstoqueAvancado', 'token', 'idemKey'
        ));
        $pedidoId = $gravado['pedido_id'];
        $numero = $gravado['numero'];

        $statusMesa = reserva_publica_status_mesa_apos_pedido_qr($db, $restauranteId, $mesaId);
        $isBalcao = mesa_eh_balcao($mesa['numero'] ?? '');
        if (!$isBalcao) {
            $stmtMesaUp = $db->prepare("
                UPDATE mesas
                SET status = :status
                WHERE id = :id
                  AND restaurante_id = :rid
                  AND status <> 'OCUPADA'
            ");
            $stmtMesaUp->execute([
                ':status' => $statusMesa,
                ':id' => $mesaId,
                ':rid' => $restauranteId,
            ]);
        } else {
            // Keep placeholder assignment for unused vars in older flow
            $statusMesa = 'LIVRE';
        }

        // (pedido_qr_meta ja gravado em reserva_pre_pedido_gravar_pedido)

        // Pre-pedido de uma reserva feita com este QR: fica ligado a ela para o check-in o encontrar.
        $reservaDoQr = reserva_qr_reserva_da_sessao($db, $restauranteId, (int)($sessao['id'] ?? 0));
        if ($reservaDoQr > 0) {
            $db->prepare('UPDATE pedido_qr_meta SET reserva_id = :r WHERE pedido_id = :p AND restaurante_id = :rid')
                ->execute([':r' => $reservaDoQr, ':p' => $pedidoId, ':rid' => $restauranteId]);
        }

        $db->commit();

        // WhatsApp: confirmação ao cliente (plano com notificações)
        if ($clienteTelefone !== ''
            && function_exists('plano_tem_funcionalidade_db')
            && plano_tem_funcionalidade_db($restauranteId, 'notificacoes')
            && function_exists('notificar_cliente_whatsapp')
        ) {
            $mesaLabel = (string)($mesa['numero'] ?? '?');
            $msg = 'Pedido #' . $numero . ' recebido'
                . ($mesaLabel !== '' ? ' (mesa ' . $mesaLabel . ')' : '')
                . '. Total: ' . number_format($total, 2, ',', '.') . ' MZN. Status: em preparo.';
            try {
                notificar_cliente_whatsapp($clienteTelefone, $msg);
            } catch (Throwable $e) {
                error_log('[RESERVA_PUBLICA][PEDIDO_QR][WA] ' . $e->getMessage());
            }
        }

        $pedido_data = [
            'id' => $pedidoId,
            'numero_pedido' => $numero,
            'mesa_id' => $mesaId,
            'mesa_numero' => $mesa['numero'] ?? '?',
            'status' => 'PREPARANDO',
            'total' => $total,
            'criado_em' => date('c'),
            'restaurante_id' => $restauranteId,
            'origem' => 'QR',
        ];
        include APP_BASE . '/../src/public/notify_websocket.php';

        return [
            'success' => true,
            'message' => 'Pedido enviado com sucesso.',
            'pedido_id' => $pedidoId,
            'numero_pedido' => $numero,
            'total' => number_format($total, 2, ',', '.'),
            'status' => 'PREPARANDO',
            'http_code' => 200,
        ];
    } catch (\Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[RESERVA_PUBLICA][PEDIDO_QR][ERROR] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Erro interno ao enviar pedido.', 'http_code' => 500];
    }
}

function reserva_publica_data_valida(string $data): bool
{
    $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'Africa/Maputo');
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $data, $timezone);
    return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $data;
}

function reserva_publica_hora_valida(string $hora): bool
{
    $horaCurta = substr($hora, 0, 5);
    $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'Africa/Maputo');
    $dt = \DateTimeImmutable::createFromFormat('H:i', $horaCurta, $timezone);
    return $dt instanceof \DateTimeImmutable && $dt->format('H:i') === $horaCurta;
}

function acompanhar_pedido_qr_publico(array $dados, ?PDO $db = null): array
{
    $token = trim((string)($dados['token'] ?? ''));
    $pedidoIdFiltro = (int)($dados['pedido_id'] ?? 0);
    // Anti-IDOR: nunca aceitar pedido_id sem token secreto do QR.
    if ($token === '') {
        return ['success' => false, 'message' => 'Recurso nao encontrado.', 'http_code' => 404];
    }

    try {
        $db = $db ?? (new Database())->getConnection();
        reserva_publica_qr_pedido_schema($db);

        if ($pedidoIdFiltro > 0) {
            $stmt = $db->prepare("
                SELECT p.id, p.numero_pedido, p.status, p.total, p.atualizado_em, p.criado_em
                FROM pedido_qr_meta m
                INNER JOIN pedidos p ON p.id = m.pedido_id AND p.restaurante_id = m.restaurante_id
                WHERE m.token = :token AND m.pedido_id = :pid
                LIMIT 1
            ");
            $stmt->execute([':token' => $token, ':pid' => $pedidoIdFiltro]);
        } else {
            $stmt = $db->prepare("
                SELECT p.id, p.numero_pedido, p.status, p.total, p.atualizado_em, p.criado_em
                FROM pedido_qr_meta m
                INNER JOIN pedidos p ON p.id = m.pedido_id AND p.restaurante_id = m.restaurante_id
                WHERE m.token = :token
                ORDER BY m.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([':token' => $token]);
        }
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) {
            // Ainda sem pedido na cozinha para este QR: estado normal, nao e erro.
            // (Resposta igual para tokens invalidos: nao revela se o token existe.)
            $emEspera = reserva_pre_pedido_em_espera_do_token($db, $token);
            return [
                'success' => true,
                'pedido' => null,
                'pre_pedido_em_espera' => $emEspera ? ['total' => $emEspera['total']] : null,
                'http_code' => 200,
            ];
        }

        $stmtItens = $db->prepare("
            SELECT
                SUM(CASE WHEN COALESCE(status,'em_preparo')='pendente' THEN 1 ELSE 0 END) AS pendentes,
                SUM(CASE WHEN COALESCE(status,'em_preparo') IN ('em_preparo','PREPARANDO') THEN 1 ELSE 0 END) AS preparo,
                SUM(CASE WHEN COALESCE(status,'em_preparo') IN ('pronto','PRONTO') THEN 1 ELSE 0 END) AS prontos,
                SUM(CASE WHEN COALESCE(status,'em_preparo') IN ('entregue','ENTREGUE') THEN 1 ELSE 0 END) AS entregues
            FROM itens_pedido
            WHERE pedido_id = :pedido_id
        ");
        $stmtItens->execute([':pedido_id' => (int)$pedido['id']]);
        $resumo = $stmtItens->fetch(PDO::FETCH_ASSOC) ?: ['pendentes' => 0, 'preparo' => 0, 'prontos' => 0, 'entregues' => 0];

        $status = strtoupper(trim((string)$pedido['status']));
        $timeline = [
            ['key' => 'NOVO', 'label' => 'Recebido', 'done' => true],
            ['key' => 'PREPARANDO', 'label' => 'Em preparo', 'done' => in_array($status, ['PREPARANDO', 'PRONTO', 'ENTREGUE', 'PAGO'], true)],
            ['key' => 'PRONTO', 'label' => 'Pronto', 'done' => in_array($status, ['PRONTO', 'ENTREGUE', 'PAGO'], true)],
            ['key' => 'ENTREGUE', 'label' => 'Entregue', 'done' => in_array($status, ['ENTREGUE', 'PAGO'], true)],
        ];

        return [
            'success' => true,
            'pedido' => [
                'id' => (int)$pedido['id'],
                'numero_pedido' => (string)$pedido['numero_pedido'],
                'status' => (string)$pedido['status'],
                'total' => (float)$pedido['total'],
                'atualizado_em' => (string)($pedido['atualizado_em'] ?? ''),
                'criado_em' => (string)($pedido['criado_em'] ?? ''),
            ],
            'timeline' => $timeline,
            'resumo_itens' => [
                'pendentes' => (int)($resumo['pendentes'] ?? 0),
                'em_preparo' => (int)($resumo['preparo'] ?? 0),
                'prontos' => (int)($resumo['prontos'] ?? 0),
                'entregues' => (int)($resumo['entregues'] ?? 0),
            ],
            'http_code' => 200,
        ];
    } catch (\Throwable $e) {
        error_log('[RESERVA_PUBLICA][ACOMPANHAR_PEDIDO][ERROR] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Erro interno ao consultar pedido.', 'http_code' => 500];
    }
}

/**
 * Respostas publicas nunca levam a mensagem tecnica (SQL, caminhos...): fica so no log.
 */
function reserva_publica_sem_detalhe_interno(array $resultado, string $contexto): array
{
    if (isset($resultado['error'])) {
        // A causa tecnica ja foi gravada no log pelo ReservaService.
        error_log('[RESERVA_PUBLICA][' . $contexto . '] falhou: ' . (string)($resultado['message'] ?? ''));
        unset($resultado['error']);
    }
    return $resultado;
}

function reserva_publica_http_code(array $resultado): int
{
    if (!empty($resultado['success'])) {
        return 200;
    }

    $code = (string)($resultado['code'] ?? '');
    if (in_array($code, ['SEM_DISPONIBILIDADE', 'MESA_INDISPONIVEL'], true)) {
        return 409;
    }

    return !empty($resultado['error']) ? 500 : 422;
}

function reserva_publica_notificar_cliente($telefone, $nomeCliente): void
{
    $telefone = trim((string)$telefone);
    if ($telefone === '') {
        return;
    }

    $nomeCliente = trim((string)$nomeCliente);
    if ($nomeCliente === '') {
        $nomeCliente = 'cliente';
    }

    $mensagem = "Ola {$nomeCliente}, sua reserva foi recebida e esta pendente de confirmacao. Em breve voce recebera uma resposta.";
    if (!notificar_cliente_whatsapp($telefone, $mensagem)) {
        error_log('[RESERVA_PUBLICA][WHATSAPP] Falha ao notificar cliente.');
    }
}

function reserva_publica_notificar_restaurante(PDO $db, int $restauranteId, array $dados, array $resultado): void
{
    if ($restauranteId <= 0) {
        return;
    }

    $telefoneRestaurante = buscar_telefone_restaurante($db, $restauranteId);
    if (!$telefoneRestaurante) {
        return;
    }

    $nomeCliente = trim((string)($dados['nome_cliente'] ?? 'Cliente'));
    $dataReserva = trim((string)($dados['data_reserva'] ?? ''));
    $horaReserva = substr(trim((string)($dados['hora_reserva'] ?? '')), 0, 5);
    $mesaNumero = trim((string)($resultado['mesa_numero'] ?? ''));

    $mensagem = "Nova reserva recebida de {$nomeCliente} para {$dataReserva} as {$horaReserva}.";
    if ($mesaNumero !== '') {
        $mensagem .= " Mesa sugerida: {$mesaNumero}.";
    }
    $mensagem .= ' Confirme pelo painel.';

    if (!notificar_cliente_whatsapp($telefoneRestaurante, $mensagem)) {
        error_log('[RESERVA_PUBLICA][WHATSAPP] Falha ao notificar restaurante.');
    }
}
