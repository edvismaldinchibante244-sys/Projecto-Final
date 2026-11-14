<?php

require_once __DIR__ . '/../config/internal_api.php';
internal_api_deny_direct_http_execution(__FILE__);

require_once __DIR__ . '/notificar_cliente_whatsapp.php';
require_once __DIR__ . '/../config/email_helper.php';
if (is_file(__DIR__ . '/../config/plano_check.php')) {
    require_once __DIR__ . '/../config/plano_check.php';
}

if (!function_exists('reserva_notificacoes_normalizar_status')) {
    function reserva_notificacoes_normalizar_status(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'confirmada', 'confirmado', 'confirmar' => 'confirmado',
            'cancelada', 'cancelado', 'cancelar' => 'cancelado',
            'no_show', 'noshow' => 'no-show',
            'checkin', 'check-in' => 'checkin',
            default => $status,
        };
    }
}

if (!function_exists('reserva_notificacoes_textos_status')) {
    function reserva_notificacoes_textos_status(array $reserva, string $status): array
    {
        $nomeCliente = trim((string)($reserva['nome_cliente'] ?? 'cliente'));
        if ($nomeCliente === '') {
            $nomeCliente = 'cliente';
        }

        $restauranteNome = trim((string)($reserva['restaurante_nome'] ?? 'restaurante'));
        if ($restauranteNome === '') {
            $restauranteNome = 'restaurante';
        }
        $restauranteTelefone = trim((string)($reserva['restaurante_telefone'] ?? ''));
        $restauranteEndereco = trim((string)($reserva['restaurante_endereco'] ?? ''));
        $restauranteCidade = trim((string)($reserva['restaurante_cidade'] ?? ''));

        $dataReserva = trim((string)($reserva['data_reserva'] ?? ''));
        $horaReserva = substr(trim((string)($reserva['hora_reserva'] ?? '')), 0, 5);

        // A mesa so e comunicada ao confirmar e no check-in. Nos outros
        // estados nao faz sentido, e numa reserva cancelada seria confuso.
        $mesaNumero = trim((string)($reserva['mesa_numero'] ?? ''));
        $mostraMesa = $mesaNumero !== '' && in_array($status, ['confirmado', 'checkin'], true);

        $assunto = 'Atualizacao da sua reserva';
        $frase = 'teve o status atualizado';

        switch ($status) {
            case 'confirmado':
                $assunto = 'Sua reserva foi confirmada';
                $frase = 'foi confirmada';
                break;
            case 'cancelado':
                $assunto = 'Sua reserva foi cancelada';
                $frase = 'foi cancelada';
                break;
            case 'no-show':
                $assunto = 'Sua reserva foi marcada como no-show';
                $frase = 'foi marcada como no-show';
                break;
            case 'checkin':
                $assunto = 'Check-in da sua reserva registrado';
                $frase = 'teve o check-in registrado';
                break;
        }

        $mensagemWhatsapp = "Ola {$nomeCliente}, sua reserva no {$restauranteNome} para {$dataReserva} as {$horaReserva} {$frase}.";
        if ($mostraMesa) {
            // "Reservamos ... para si" e informativo sem soar a garantia:
            // a sala muda, e o lugar pode ter de ser trocado a chegada.
            $mensagemWhatsapp .= " Reservamos a mesa {$mesaNumero} para si.";
        }

        $nomeClienteEsc = htmlspecialchars($nomeCliente, ENT_QUOTES, 'UTF-8');
        $restauranteNomeEsc = htmlspecialchars($restauranteNome, ENT_QUOTES, 'UTF-8');
        $dataReservaEsc = htmlspecialchars($dataReserva, ENT_QUOTES, 'UTF-8');
        $dataReservaLabel = $dataReserva;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataReserva) === 1) {
            $dtReserva = DateTimeImmutable::createFromFormat('Y-m-d', $dataReserva);
            if ($dtReserva instanceof DateTimeImmutable) {
                $dataReservaLabel = $dtReserva->format('d/m/Y');
            }
        }
        $dataReservaLabelEsc = htmlspecialchars($dataReservaLabel, ENT_QUOTES, 'UTF-8');
        $horaReservaEsc = htmlspecialchars($horaReserva, ENT_QUOTES, 'UTF-8');
        $fraseEsc = htmlspecialchars($frase, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        $mesaHtml = '';
        if ($mostraMesa) {
            $mesaNumeroEsc = htmlspecialchars($mesaNumero, ENT_QUOTES, 'UTF-8');
            $mesaHtml = '
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:10px;">
                      <tr>
                        <td style="padding:0;">
                          <div style="border:1px solid #e5e7eb;border-radius:16px;padding:15px;background:#ffffff;">
                            <div style="font-size:12px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">A sua mesa</div>
                            <div style="font-size:15px;color:#111827;font-weight:800;margin-top:6px;">Mesa ' . $mesaNumeroEsc . '</div>
                            <div style="font-size:12px;color:#64748b;margin-top:6px;line-height:1.5;">Reservamos este lugar para si. Se a sala estiver diferente no dia, a equipa indica-lhe a mesa a chegada.</div>
                          </div>
                        </td>
                      </tr>
                    </table>';
        }

        $statusEmail = match ($status) {
            'confirmado' => [
                'titulo' => 'Reserva confirmada com sucesso',
                'badge' => 'CONFIRMADA',
                'cor' => '#16a34a',
                'bg' => '#ecfdf5',
                'texto' => 'A sua reserva foi confirmada pela equipa do restaurante.',
            ],
            'cancelado' => [
                'titulo' => 'Reserva cancelada',
                'badge' => 'CANCELADA',
                'cor' => '#dc2626',
                'bg' => '#fef2f2',
                'texto' => 'A sua reserva foi cancelada. Se precisar de apoio, contacte o restaurante.',
            ],
            'no-show' => [
                'titulo' => 'Reserva marcada como no-show',
                'badge' => 'NO-SHOW',
                'cor' => '#d97706',
                'bg' => '#fffbeb',
                'texto' => 'A reserva foi marcada como no-show pela equipa do restaurante.',
            ],
            'checkin' => [
                'titulo' => 'Check-in registado',
                'badge' => 'NO SALAO',
                'cor' => '#2563eb',
                'bg' => '#eff6ff',
                'texto' => 'O check-in da sua reserva foi registado com sucesso.',
            ],
            default => [
                'titulo' => 'Atualizacao da reserva',
                'badge' => 'ATUALIZADA',
                'cor' => '#0f766e',
                'bg' => '#f0fdfa',
                'texto' => 'A sua reserva teve uma atualizacao de status.',
            ],
        };

        $contatoRows = '';
        if ($restauranteTelefone !== '') {
            $contatoRows .= '<tr><td style="padding:10px 0;color:#64748b;font-size:13px;">Telefone</td><td align="right" style="padding:10px 0;color:#111827;font-size:13px;font-weight:700;">' . htmlspecialchars($restauranteTelefone, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        if ($restauranteEndereco !== '') {
            $contatoRows .= '<tr><td style="padding:10px 0;color:#64748b;font-size:13px;">Endereco</td><td align="right" style="padding:10px 0;color:#111827;font-size:13px;font-weight:700;">' . htmlspecialchars($restauranteEndereco, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        if ($restauranteCidade !== '') {
            $contatoRows .= '<tr><td style="padding:10px 0;color:#64748b;font-size:13px;">Cidade</td><td align="right" style="padding:10px 0;color:#111827;font-size:13px;font-weight:700;">' . htmlspecialchars($restauranteCidade, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $contatoHtml = '';
        if ($contatoRows !== '') {
            $contatoHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:16px;border-top:1px solid #e5e7eb;">'
                . '<tr><td colspan="2" style="padding:16px 0 4px;color:#111827;font-size:14px;font-weight:800;">Informacoes do restaurante</td></tr>'
                . $contatoRows
                . '</table>';
        }

        $mensagemEmail = '<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>' . htmlspecialchars($statusEmail['titulo'], ENT_QUOTES, 'UTF-8') . '</title>
  <style>
    @media only screen and (max-width:620px) {
      .nivora-outer { padding:12px 8px !important; }
      .nivora-wrap { width:calc(100vw - 16px) !important; max-width:calc(100vw - 16px) !important; }
      .nivora-shell { width:100% !important; max-width:100% !important; border-radius:14px !important; }
      .nivora-pad { padding-left:20px !important; padding-right:20px !important; }
      .nivora-title { font-size:24px !important; line-height:1.15 !important; }
      .reserva-grid td { display:block !important; width:100% !important; box-sizing:border-box !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#292524;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Reserva em ' . $restauranteNomeEsc . ' para ' . $dataReservaLabelEsc . ' as ' . $horaReservaEsc . ' ' . $fraseEsc . '.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f4f6;">
    <tr>
      <td align="center" class="nivora-outer" style="padding:32px 12px;">
        <table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0" class="nivora-wrap" style="width:620px;max-width:620px;">
          <tr>
            <td class="nivora-shell" style="background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.14);border:1px solid #e5e7eb;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td class="nivora-pad" style="padding:28px 34px;background:linear-gradient(135deg,#111827 0%,#292524 54%,#ea580c 100%);color:#ffffff;">
                    <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;font-weight:800;color:#fed7aa;">NIVORA RESTAURANT</div>
                    <div style="font-size:13px;margin-top:8px;color:#ffedd5;">Reservas e atendimento ao cliente</div>
                    <div class="nivora-title" style="font-size:30px;line-height:1.2;font-weight:900;margin-top:22px;">' . htmlspecialchars($statusEmail['titulo'], ENT_QUOTES, 'UTF-8') . '</div>
                    <div style="display:inline-block;margin-top:14px;padding:8px 13px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.24);font-size:12px;font-weight:800;letter-spacing:.08em;">' . htmlspecialchars($statusEmail['badge'], ENT_QUOTES, 'UTF-8') . '</div>
                  </td>
                </tr>
                <tr>
                  <td class="nivora-pad" style="padding:28px 34px 32px;">
                    <p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#111827;">Ola ' . $nomeClienteEsc . ',</p>
                    <p style="margin:0;color:#475569;font-size:15px;line-height:1.65;">' . htmlspecialchars($statusEmail['texto'], ENT_QUOTES, 'UTF-8') . '</p>

                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:22px 0 0;border-radius:18px;background:' . htmlspecialchars($statusEmail['bg'], ENT_QUOTES, 'UTF-8') . ';border:1px solid #d1fae5;">
                      <tr>
                        <td style="padding:18px 20px;">
                          <div style="font-size:12px;text-transform:uppercase;letter-spacing:.12em;font-weight:900;color:' . htmlspecialchars($statusEmail['cor'], ENT_QUOTES, 'UTF-8') . ';">Resumo da reserva</div>
                          <div style="font-size:22px;font-weight:900;color:#111827;margin-top:7px;">' . $restauranteNomeEsc . '</div>
                          <div style="font-size:14px;color:#475569;margin-top:5px;">' . $dataReservaLabelEsc . ' as ' . $horaReservaEsc . '</div>
                        </td>
                      </tr>
                    </table>

                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="reserva-grid" style="margin-top:18px;">
                      <tr>
                        <td width="50%" style="padding:0 7px 10px 0;">
                          <div style="border:1px solid #e5e7eb;border-radius:16px;padding:15px;background:#ffffff;">
                            <div style="font-size:12px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Cliente</div>
                            <div style="font-size:15px;color:#111827;font-weight:800;margin-top:6px;">' . $nomeClienteEsc . '</div>
                          </div>
                        </td>
                        <td width="50%" style="padding:0 0 10px 7px;">
                          <div style="border:1px solid #e5e7eb;border-radius:16px;padding:15px;background:#ffffff;">
                            <div style="font-size:12px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Data e hora</div>
                            <div style="font-size:15px;color:#111827;font-weight:800;margin-top:6px;">' . $dataReservaLabelEsc . ' · ' . $horaReservaEsc . '</div>
                          </div>
                        </td>
                      </tr>
                    </table>
                    ' . $mesaHtml . '

                    ' . $contatoHtml . '

                    <div style="margin-top:20px;border-radius:16px;background:#fff7ed;border:1px solid #fed7aa;padding:14px 16px;color:#7c2d12;font-size:13px;line-height:1.55;">
                      Se precisar alterar a reserva, responda este email ou contacte o restaurante pelo telefone indicado.
                    </div>
                  </td>
                </tr>
                <tr>
                  <td style="padding:18px 34px;background:#f9fafb;border-top:1px solid #e5e7eb;text-align:center;color:#94a3b8;font-size:12px;line-height:1.6;">
                    Nivora Restaurant · Gestao de reservas<br>
                    &copy; ' . $year . ' Nivora Restaurant. Todos os direitos reservados.
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
        return [
            'assunto' => $assunto,
            'whatsapp' => $mensagemWhatsapp,
            'email' => $mensagemEmail,
        ];
    }
}

if (!function_exists('reserva_notificacoes_buscar_reserva')) {
    function reserva_notificacoes_buscar_reserva(PDO $db, int $reservaId, ?int $restauranteId = null): ?array
    {
        if ($reservaId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                r.id,
                r.restaurante_id,
                r.nome_cliente,
                r.email_cliente,
                r.telefone_cliente,
                r.data_reserva,
                r.hora_reserva,
                r.status,
                r.mesa_atribuida,
                m.numero AS mesa_numero,
                c.email AS cliente_email,
                c.telefone AS cliente_telefone,
                rest.nome AS restaurante_nome,
                rest.telefone AS restaurante_telefone,
                rest.endereco AS restaurante_endereco,
                rest.cidade AS restaurante_cidade
            FROM reservas r
            LEFT JOIN mesas m ON m.id = r.mesa_atribuida AND m.restaurante_id = r.restaurante_id
            LEFT JOIN clientes c ON c.id = r.cliente_id
            INNER JOIN restaurantes rest ON rest.id = r.restaurante_id
            WHERE r.id = :id
        ";

        $params = [':id' => $reservaId];
        if ($restauranteId !== null && $restauranteId > 0) {
            $sql .= ' AND r.restaurante_id = :restaurante_id';
            $params[':restaurante_id'] = $restauranteId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $reserva = $stmt->fetch(PDO::FETCH_ASSOC);
        return $reserva ?: null;
    }
}

if (!function_exists('reserva_notificacoes_enviar_status_cliente')) {
    function reserva_notificacoes_enviar_status_cliente(PDO $db, int $reservaId, string $novoStatus, ?int $restauranteId = null): bool
    {
        $reserva = reserva_notificacoes_buscar_reserva($db, $reservaId, $restauranteId);
        if (!$reserva) {
            return false;
        }

        $ridNotif = (int)($reserva['restaurante_id'] ?? ($restauranteId ?? 0));
        $temNotificacoesCompletas = false;
        $temReservasEmail = false;
        if ($ridNotif > 0 && function_exists('plano_tem_funcionalidade_db')) {
            $temNotificacoesCompletas = plano_tem_funcionalidade_db($ridNotif, 'notificacoes')
                || plano_tem_funcionalidade_db($ridNotif, 'reservas_qr');
            $temReservasEmail = plano_tem_funcionalidade_db($ridNotif, 'reservas_notificacao_email');
        }

        $status = reserva_notificacoes_normalizar_status($novoStatus !== '' ? $novoStatus : (string)($reserva['status'] ?? ''));
        if ($status === '') {
            return false;
        }

        $statusEmailReserva = in_array($status, ['confirmado', 'cancelado'], true);
        $podeEmail = $temNotificacoesCompletas || ($temReservasEmail && $statusEmailReserva);
        $podeWhatsapp = $temNotificacoesCompletas;

        if (!$podeEmail && !$podeWhatsapp) {
            // Sem notificações no plano: não envia WhatsApp/email automático
            return false;
        }

        $textos = reserva_notificacoes_textos_status($reserva, $status);
        $telefone = trim((string)($reserva['telefone_cliente'] ?: ($reserva['cliente_telefone'] ?? '')));
        $email = trim((string)($reserva['email_cliente'] ?: ($reserva['cliente_email'] ?? '')));
        $enviado = false;

        if ($podeWhatsapp && $telefone !== '') {
            $enviado = notificar_cliente_whatsapp($telefone, $textos['whatsapp']) || $enviado;
        }

        if ($podeEmail && $email !== '') {
            $enviado = saas_enviar_email($email, $textos['assunto'], $textos['email']) || $enviado;
        }

        if (!$enviado) {
            error_log('[RESERVA][NOTIFICACAO] Nenhum canal disponivel ou envio falhou para a reserva ' . $reservaId . '.');
        }

        return $enviado;
    }
}

if (!function_exists('reserva_espera_avisar_vaga')) {
    /**
     * Avisa quem estava na lista de espera que se libertou uma mesa.
     *
     * Nao reserva nada: a mesa continua livre para quem chegar primeiro, e
     * o texto diz isso. Prometer a mesa sem o restaurante confirmar criaria
     * a situacao pior de todas -- o cliente aparece e a mesa ja la nao esta.
     */
    function reserva_espera_avisar_vaga(array $espera, string $restauranteNome, string $restauranteTelefone = ''): bool
    {
        $email = trim((string)($espera['email'] ?? $espera['email_cliente'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $nome = trim((string)($espera['nome'] ?? $espera['nome_cliente'] ?? 'cliente'));
        $hora = substr(trim((string)($espera['hora_reserva'] ?? '')), 0, 5);
        $nomeEsc = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
        $restEsc = htmlspecialchars($restauranteNome !== '' ? $restauranteNome : 'restaurante', ENT_QUOTES, 'UTF-8');
        $horaEsc = htmlspecialchars($hora, ENT_QUOTES, 'UTF-8');
        $telEsc = htmlspecialchars($restauranteTelefone, ENT_QUOTES, 'UTF-8');

        $contacto = $telEsc !== ''
            ? '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6;">Ligue para <b>' . $telEsc . '</b> para confirmar.</p>'
            : '<p style="margin:14px 0 0;font-size:14px;color:#334155;line-height:1.6;">Contacte o restaurante para confirmar.</p>';

        $html = '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#ffffff;border-radius:18px;border:1px solid #e5e7eb;overflow:hidden;">'
            . '<tr><td style="padding:24px 28px;background:linear-gradient(135deg,#111827 0%,#ea580c 100%);color:#ffffff;font-size:18px;font-weight:800;">Abriu uma mesa</td></tr>'
            . '<tr><td style="padding:24px 28px;">'
            . '<p style="margin:0;font-size:15px;color:#111827;line-height:1.6;">Ola ' . $nomeEsc . ',</p>'
            . '<p style="margin:12px 0 0;font-size:15px;color:#111827;line-height:1.6;">Estava na nossa lista de espera e acabou de ficar uma mesa livre no <b>' . $restEsc . '</b>'
            . ($horaEsc !== '' ? ' por volta das <b>' . $horaEsc . '</b>' : '') . '.</p>'
            . '<p style="margin:12px 0 0;font-size:14px;color:#64748b;line-height:1.6;">A mesa ainda nao esta reservada em seu nome. Confirme connosco para a guardarmos.</p>'
            . $contacto
            . '</td></tr></table></td></tr></table></body></html>';

        $assunto = 'Abriu uma mesa no ' . ($restauranteNome !== '' ? $restauranteNome : 'restaurante');

        if (!function_exists('saas_enviar_email')) {
            return false;
        }

        return (bool)saas_enviar_email($email, $assunto, $html);
    }
}
