<?php

require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/api_guard.php';
require_once APP_BASE . '/src/config/database.php';
require_once APP_BASE . '/src/config/reserva_qr_schema.php';
require_once APP_BASE . '/src/api/notificar_cliente_whatsapp.php';
require_once APP_BASE . '/src/api/reserva_qr_delivery.php';

header('Content-Type: application/json; charset=utf-8');

function reservas_qr_delivery_build_context(array $dados, int $restauranteId, int $sessaoId, int $reservaId): array
{
    $tipo = $sessaoId > 0 ? 'sessao' : 'reserva';
    $restauranteNome = trim((string)($dados['restaurante_nome'] ?? 'Restaurante'));
    $nomeCliente = trim((string)($dados['nome_cliente'] ?? 'Cliente'));
    $telefone = trim((string)($dados['telefone_cliente'] ?? ''));
    $email = trim((string)($dados['email_cliente'] ?? ''));
    $observacoes = trim((string)($dados['observacoes'] ?? ''));
    $mesaNumero = trim((string)($dados['mesa_numero'] ?? ''));
    $mesaCapacidade = (int)($dados['mesa_capacidade'] ?? 0);
    $mesaLabel = $mesaNumero !== ''
        ? ('Mesa ' . $mesaNumero . ($mesaCapacidade > 0 ? ' • ' . $mesaCapacidade . ' lugares' : ''))
        : '';

    if ($tipo === 'sessao') {
        $token = trim((string)($dados['token'] ?? ''));
        // Nunca devolva a pagina sem token: esse link abre uma sessao invalida
        // e pode induzir o operador a distribuir um QR que nao funciona.
        if ($token === '') {
            throw new RuntimeException('A sessao QR nao possui token valido. Gere um novo QR.');
        }
        $link = reserva_qr_build_public_url($token);
        $expiresAt = trim((string)($dados['expires_at'] ?? ''));

        return [
            'tipo' => $tipo,
            'sessao_id' => $sessaoId,
            'reserva_id' => 0,
            'restaurante_id' => $restauranteId,
            'restaurante_nome' => $restauranteNome,
            'nome_cliente' => $nomeCliente !== '' ? $nomeCliente : 'Cliente',
            'telefone_cliente' => $telefone,
            'email_cliente' => $email,
            'observacoes' => $observacoes,
            'mesa_label' => $mesaLabel,
            'link' => $link,
            'expires_at' => $expiresAt,
            'titulo' => 'QR Code para reserva',
            'codigo' => 'S-' . str_pad((string)$sessaoId, 6, '0', STR_PAD_LEFT),
            'data_hora_label' => $expiresAt !== '' ? ('Valido ate ' . $expiresAt) : '',
            'download_name' => 'qr-reserva-sessao-' . $sessaoId . '.png',
            'qr_payload' => $link,
            'whatsapp_message' => trim(
                "Ola {$nomeCliente}, segue o QR Code da sua reserva no {$restauranteNome}."
                . ($mesaLabel !== '' ? "\n{$mesaLabel}" : '')
                . ($expiresAt !== '' ? "\nValido ate: {$expiresAt}" : '')
                . "\nUse a imagem em anexo ou abra o link: {$link}"
            ),
        ];
    }

    $codigo = 'RSV-' . str_pad((string)$reservaId, 6, '0', STR_PAD_LEFT);
    $dataReserva = trim((string)($dados['data_reserva'] ?? ''));
    $horaReserva = substr(trim((string)($dados['hora_reserva'] ?? '')), 0, 5);
    $quantidade = (int)($dados['quantidade_pessoas'] ?? 0);
    $status = trim((string)($dados['status'] ?? 'pendente'));
    $dataHoraLabel = trim($dataReserva . ($horaReserva !== '' ? ' ' . $horaReserva : ''));

    $linhasPayload = [
        'RESERVA RESTAURANTE',
        'Codigo: ' . $codigo,
        'Restaurante: ' . $restauranteNome,
        'Cliente: ' . ($nomeCliente !== '' ? $nomeCliente : 'Cliente'),
    ];
    if ($dataHoraLabel !== '') {
        $linhasPayload[] = 'Data/Hora: ' . $dataHoraLabel;
    }
    if ($mesaLabel !== '') {
        $linhasPayload[] = 'Mesa: ' . $mesaLabel;
    }
    if ($quantidade > 0) {
        $linhasPayload[] = 'Pessoas: ' . $quantidade;
    }
    if ($status !== '') {
        $linhasPayload[] = 'Status: ' . ucfirst($status);
    }

    return [
        'tipo' => $tipo,
        'sessao_id' => 0,
        'reserva_id' => $reservaId,
        'restaurante_id' => $restauranteId,
        'restaurante_nome' => $restauranteNome,
        'nome_cliente' => $nomeCliente !== '' ? $nomeCliente : 'Cliente',
        'telefone_cliente' => $telefone,
        'email_cliente' => $email,
        'observacoes' => $observacoes,
        'mesa_label' => $mesaLabel,
        'link' => '',
        'expires_at' => '',
        'titulo' => 'QR Code da reserva',
        'codigo' => $codigo,
        'data_hora_label' => $dataHoraLabel,
        'download_name' => 'qr-reserva-' . $reservaId . '.png',
        'qr_payload' => implode("\n", $linhasPayload),
        'whatsapp_message' => trim(
            "Ola {$nomeCliente}, segue o QR Code da sua reserva no {$restauranteNome}."
            . ($dataHoraLabel !== '' ? "\nData/Hora: {$dataHoraLabel}" : '')
            . ($mesaLabel !== '' ? "\n{$mesaLabel}" : '')
            . "\nCodigo da reserva: {$codigo}"
        ),
    ];
}

function reservas_qr_extract_public_token(array $input): string
{
    $token = trim((string)($input['token'] ?? ''));
    if ($token !== '') {
        return $token;
    }

    $link = trim((string)($input['link'] ?? $input['url'] ?? ''));
    if ($link === '') {
        return '';
    }

    $query = (string)(parse_url($link, PHP_URL_QUERY) ?: '');
    if ($query === '') {
        return '';
    }

    parse_str($query, $params);
    return trim((string)($params['token'] ?? ''));
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    security_json(['success' => false, 'message' => 'Metodo nao permitido.'], 405);
}

$ctx = api_guard([
    'roles' => ['ADMIN', 'GERENTE', 'GARCOM'],
    'rate_key' => 'reservas_qr_enviar',
    'rate_max' => 30,
    'rate_window' => 60,
]);

$restauranteId = (int)$ctx['restaurante_id'];
$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$sessaoId = (int)($input['sessao_id'] ?? 0);
$reservaId = (int)($input['reserva_id'] ?? 0);
$qrImageData = trim((string)($input['qr_image_data'] ?? ''));
$enviarWhatsApp = array_key_exists('enviar_whatsapp', $input) ? (bool)$input['enviar_whatsapp'] : true;
$enviarEmail = array_key_exists('enviar_email', $input) ? (bool)$input['enviar_email'] : true;

if ($sessaoId <= 0 && $reservaId <= 0) {
    security_json(['success' => false, 'message' => 'Informe sessao_id ou reserva_id.'], 400);
}

$db = (new Database())->getConnection();

try {
    if ($sessaoId > 0) {
        $stmt = $db->prepare("
            SELECT
                sq.id,
                sq.token,
                sq.token_cipher,
                sq.mesa_id,
                sq.telefone_cliente,
                sq.nome_atendente,
                sq.observacoes,
                sq.expires_at,
                sq.consumed_at,
                sq.revoked_at,
                m.numero AS mesa_numero,
                m.capacidade AS mesa_capacidade,
                rest.nome AS restaurante_nome
            FROM reserva_qr_sessoes sq
            LEFT JOIN mesas m ON m.id = sq.mesa_id
            INNER JOIN restaurantes rest ON rest.id = sq.restaurante_id
            WHERE sq.id = :id
              AND sq.restaurante_id = :restaurante_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $sessaoId, ':restaurante_id' => $restauranteId]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $stmt = $db->prepare("
            SELECT
                r.id,
                r.nome_cliente,
                r.telefone_cliente,
                r.email_cliente,
                r.observacoes,
                r.data_reserva,
                r.hora_reserva,
                r.quantidade_pessoas,
                r.status,
                r.mesa_atribuida,
                m.numero AS mesa_numero,
                m.capacidade AS mesa_capacidade,
                rest.nome AS restaurante_nome
            FROM reservas r
            LEFT JOIN mesas m ON m.id = r.mesa_atribuida
            INNER JOIN restaurantes rest ON rest.id = r.restaurante_id
            WHERE r.id = :id
              AND r.restaurante_id = :restaurante_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $reservaId, ':restaurante_id' => $restauranteId]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$dados) {
        security_json(['success' => false, 'message' => 'Sessao ou reserva nao encontrada. Gere um novo QR Code.'], 404);
    }

    if ($sessaoId > 0) {
        $agoraUtc = reserva_qr_now_string();
        $expiraEm = trim((string)($dados['expires_at'] ?? ''));
        $revogada = trim((string)($dados['revoked_at'] ?? '')) !== '';

        if ($revogada || $expiraEm === '' || $expiraEm <= $agoraUtc) {
            security_json([
                'success' => false,
                'code' => $revogada ? 'QR_REVOKED' : 'QR_EXPIRED',
                'message' => $revogada
                    ? 'Este QR Code foi revogado. Gere um novo QR Code.'
                    : 'Este QR Code expirou. Gere um novo QR Code antes de enviar.',
                'expires_at' => $expiraEm,
            ], 410);
        }
    }

    if ($sessaoId > 0 && function_exists('reserva_qr_session_plain_token')) {
        $dados['token'] = reserva_qr_session_plain_token($dados);
        if ($dados['token'] === '') {
            $inputToken = reservas_qr_extract_public_token($input);
            $sessaoPorToken = $inputToken !== ''
                ? reserva_qr_get_session_by_token($db, $inputToken, true)
                : null;

            if (
                $sessaoPorToken
                && (int)($sessaoPorToken['id'] ?? 0) === $sessaoId
                && (int)($sessaoPorToken['restaurante_id'] ?? 0) === $restauranteId
            ) {
                $dados['token'] = $inputToken;
            }
        }
    }

    $dados['nome_cliente'] = trim((string)($input['nome_cliente'] ?? $dados['nome_cliente'] ?? $dados['nome_atendente'] ?? ''));
    $dados['telefone_cliente'] = trim((string)($input['telefone_cliente'] ?? $dados['telefone_cliente'] ?? ''));
    $dados['email_cliente'] = trim((string)($input['email_cliente'] ?? $dados['email_cliente'] ?? ''));
    $dados['observacoes'] = trim((string)($input['observacoes'] ?? $dados['observacoes'] ?? ''));

    $contexto = reservas_qr_delivery_build_context($dados, $restauranteId, $sessaoId, $reservaId);

    if ($qrImageData === '') {
        security_json([
            'success' => true,
            'needs_image' => true,
            'message' => 'QR pronto para gerar imagem e compartilhar.',
            'sessao_id' => $contexto['sessao_id'],
            'reserva_id' => $contexto['reserva_id'],
            'tipo' => $contexto['tipo'],
            'titulo' => $contexto['titulo'],
            'codigo' => $contexto['codigo'],
            'link' => $contexto['link'],
            'expires_at' => $contexto['expires_at'],
            'download_name' => $contexto['download_name'],
            'qr_payload' => $contexto['qr_payload'],
            'nome_cliente' => $contexto['nome_cliente'],
            'telefone' => $contexto['telefone_cliente'],
            'email' => $contexto['email_cliente'],
            'mesa_label' => $contexto['mesa_label'],
            'data_hora_label' => $contexto['data_hora_label'],
            'can_send_whatsapp' => $contexto['telefone_cliente'] !== '',
            'can_send_email' => $contexto['email_cliente'] !== '',
        ]);
    }

    $savedImage = reserva_qr_delivery_store_image_data(
        $qrImageData,
        $contexto['codigo'],
        $restauranteId
    );

    $emailHtml = reserva_qr_delivery_build_email_html($contexto, $savedImage['public_url']);
    $delivery = [
        'whatsapp' => ['attempted' => false, 'sent' => false, 'error' => null],
        'email' => ['attempted' => false, 'sent' => false, 'error' => null],
    ];
    $warnings = [];

    if ($enviarWhatsApp && $contexto['telefone_cliente'] !== '') {
        $delivery['whatsapp']['attempted'] = true;

        $mediaForWhatsApp = function_exists('notificar_cliente_whatsapp_cloud_api_configurada')
            && notificar_cliente_whatsapp_cloud_api_configurada()
            ? $savedImage['absolute_path']
            : $savedImage['public_url'];

        if (
            $mediaForWhatsApp === $savedImage['public_url']
            && !reserva_qr_delivery_public_media_url($savedImage['public_url'])
        ) {
            $delivery['whatsapp']['error'] = 'O provedor atual do WhatsApp exige uma URL publica da imagem. Configure WhatsApp Cloud API com upload de media local ou publique o sistema.';
            $warnings[] = 'WhatsApp automatico com Twilio requer URL publica; para localhost use WhatsApp Cloud API ou publique o sistema.';
        } else {
            $delivery['whatsapp']['sent'] = notificar_cliente_whatsapp(
                $contexto['telefone_cliente'],
                $contexto['whatsapp_message'],
                $mediaForWhatsApp
            );

            if (!$delivery['whatsapp']['sent']) {
                $delivery['whatsapp']['error'] = function_exists('notificar_cliente_whatsapp_last_error')
                    ? notificar_cliente_whatsapp_last_error()
                    : 'Falha ao enviar via WhatsApp.';
            }
        }
    }

    if ($enviarEmail && $contexto['email_cliente'] !== '') {
        $delivery['email']['attempted'] = true;
        $delivery['email']['sent'] = reserva_qr_delivery_send_email(
            $contexto['email_cliente'],
            $contexto['titulo'] . ' - ' . $contexto['restaurante_nome'],
            $emailHtml,
            $savedImage['absolute_path'],
            $contexto['download_name'],
            $savedImage['public_url']
        );

        if (!$delivery['email']['sent']) {
            $delivery['email']['error'] = function_exists('saas_email_get_last_error')
                ? saas_email_get_last_error()
                : 'Falha ao enviar via e-mail.';
        }
    }

    $sentChannels = [];
    if ($delivery['whatsapp']['sent']) {
        $sentChannels[] = 'WhatsApp';
    }
    if ($delivery['email']['sent']) {
        $sentChannels[] = 'e-mail';
    }

    foreach (['whatsapp', 'email'] as $channel) {
        if (
            !empty($delivery[$channel]['attempted'])
            && empty($delivery[$channel]['sent'])
            && !empty($delivery[$channel]['error'])
        ) {
            $warnings[] = (string)$delivery[$channel]['error'];
        }
    }

    $message = $sentChannels
        ? 'QR Code gerado e enviado por ' . implode(' e ', $sentChannels) . '.'
        : 'QR Code gerado, mas o envio automatico nao foi concluido. Use o download manual ou verifique a configuracao do canal.';

    security_audit_event('reserva_qr_enviado', [
        'sessao_id' => $contexto['sessao_id'],
        'reserva_id' => $contexto['reserva_id'],
        'whatsapp' => $delivery['whatsapp']['sent'],
        'email' => $delivery['email']['sent'],
    ]);

    security_json([
        'success' => true,
        'message' => $message,
        'tipo' => $contexto['tipo'],
        'sessao_id' => $contexto['sessao_id'],
        'reserva_id' => $contexto['reserva_id'],
        'titulo' => $contexto['titulo'],
        'codigo' => $contexto['codigo'],
        'link' => $contexto['link'],
        'expires_at' => $contexto['expires_at'],
        'download_name' => $contexto['download_name'],
        'qr_payload' => $contexto['qr_payload'],
        'image_url' => $savedImage['public_url'],
        'download_url' => $savedImage['public_url'],
        'telefone' => $contexto['telefone_cliente'],
        'email' => $contexto['email_cliente'],
        'mesa_label' => $contexto['mesa_label'],
        'data_hora_label' => $contexto['data_hora_label'],
        'delivery' => $delivery,
        'warnings' => $warnings,
    ]);
} catch (Throwable $e) {
    error_log('[RESERVAS_QR_ENVIAR][ERROR] ' . $e->getMessage());
    $isProduction = strtolower((string)(getenv('APP_ENV') ?: 'local')) === 'production';
    security_json([
        'success' => false,
        'message' => 'Erro ao preparar o envio do QR Code.',
        'detail' => $isProduction ? null : $e->getMessage(),
    ], 500);
}
