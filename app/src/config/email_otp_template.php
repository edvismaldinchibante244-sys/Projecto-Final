<?php

if (!function_exists('nivora_email_compose_login_otp')) {
    /**
     * Monta o email empresarial de verificacao em duas etapas.
     *
     * @param array{nome?:string,code:string,expires_minutes?:int} $options
     * @return array{subject:string,html:string}
     */
    function nivora_email_compose_login_otp(array $options): array
    {
        $nome = trim((string)($options['nome'] ?? ''));
        $code = preg_replace('/\D+/', '', (string)($options['code'] ?? '')) ?? '';
        $expiresMinutes = max(1, (int)($options['expires_minutes'] ?? 5));

        $saudacao = $nome !== '' ? $nome : 'Utilizador';
        $saudacaoEsc = htmlspecialchars($saudacao, ENT_QUOTES, 'UTF-8');
        $codeEsc = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        $subject = 'Nivora Restaurant — código de verificação';
        $html = '<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>Código de verificação</title>
  <style>
    @media only screen and (max-width:620px) {
      .nivora-outer { padding:12px 8px !important; }
      .nivora-wrap { width:calc(100vw - 16px) !important; max-width:calc(100vw - 16px) !important; }
      .nivora-shell { width:100% !important; max-width:100% !important; border-radius:12px !important; }
      .nivora-pad { padding-left:20px !important; padding-right:20px !important; }
      .nivora-title { font-size:23px !important; }
      .nivora-code { font-size:30px !important; letter-spacing:6px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#292524;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">O seu código de acesso NIVORA expira em ' . $expiresMinutes . ' minutos.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f4f6;">
    <tr>
      <td align="center" class="nivora-outer" style="padding:32px 12px;">
        <div class="nivora-wrap" style="width:100%;max-width:600px;margin:0 auto;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="nivora-shell" style="width:100%;table-layout:fixed;background:#ffffff;border:1px solid #e7e5e4;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(28,25,23,0.08);">
          <tr>
            <td class="nivora-pad" style="padding:22px 28px;background:#1c1917;border-bottom:4px solid #d9773a;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;">
                <tr>
                  <td width="48" valign="middle">
                    <div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:10px;background:#d9773a;color:#1c1917;font-size:20px;font-weight:800;">N</div>
                  </td>
                  <td valign="middle" style="overflow-wrap:anywhere;">
                    <p style="margin:0;font-size:13px;line-height:1.2;letter-spacing:0.13em;text-transform:uppercase;color:#fff7ed;font-weight:800;">NIVORA RESTAURANT</p>
                    <p style="margin:5px 0 0;font-size:12px;line-height:1.3;color:#a8a29e;">Gestão inteligente para restaurantes</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="nivora-pad" style="padding:34px 32px 14px;overflow-wrap:anywhere;">
              <p style="margin:0 0 10px;font-size:12px;line-height:1.3;letter-spacing:0.12em;text-transform:uppercase;color:#c15f2a;font-weight:800;">Verificação em duas etapas</p>
              <h1 class="nivora-title" style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#1c1917;font-weight:800;">Confirme o seu acesso</h1>
              <p style="margin:0 0 12px;font-size:16px;line-height:1.6;color:#44403c;">Olá, <strong style="color:#1c1917;">' . $saudacaoEsc . '</strong>.</p>
              <p style="margin:0;font-size:15px;line-height:1.65;color:#57534e;">Recebemos uma tentativa de entrada na sua conta. Use o código abaixo para concluir o login com segurança.</p>
            </td>
          </tr>
          <tr>
            <td class="nivora-pad" style="padding:18px 32px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;">
                <tr>
                  <td align="center" style="padding:24px 16px;">
                    <p style="margin:0 0 10px;font-size:11px;line-height:1.2;letter-spacing:0.14em;text-transform:uppercase;color:#9a3412;font-weight:800;">Código de verificação</p>
                    <p class="nivora-code" style="margin:0;font-family:Consolas,Menlo,Monaco,monospace;font-size:34px;line-height:1.25;letter-spacing:8px;color:#1c1917;font-weight:800;">' . $codeEsc . '</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td class="nivora-pad" style="padding:4px 32px 30px;overflow-wrap:anywhere;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;background:#fafaf9;border:1px solid #e7e5e4;border-radius:12px;">
                <tr>
                  <td width="74" valign="middle" align="center" style="padding:16px 0 16px 16px;">
                    <div style="width:44px;height:44px;line-height:44px;text-align:center;border-radius:22px;background:#1c1917;color:#fdba74;font-size:13px;font-weight:800;">' . $expiresMinutes . ' min</div>
                  </td>
                  <td valign="middle" style="padding:16px;overflow-wrap:anywhere;">
                    <p style="margin:0 0 4px;font-size:14px;line-height:1.4;color:#292524;font-weight:700;">Validade limitada</p>
                    <p style="margin:0;font-size:13px;line-height:1.5;color:#78716c;">O código expira em ' . $expiresMinutes . ' minutos e só pode ser usado para esta tentativa de login.</p>
                  </td>
                </tr>
              </table>
              <p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:#78716c;"><strong style="color:#57534e;">Não iniciou esta sessão?</strong> Ignore este email. O acesso não será concluído sem o código.</p>
              <p style="margin:10px 0 0;font-size:12px;line-height:1.55;color:#a8a29e;">Por segurança, nunca partilhe este código. A Nivora Restaurant nunca solicita a sua senha por email, telefone ou mensagem.</p>
            </td>
          </tr>
          <tr>
            <td class="nivora-pad" style="padding:20px 32px;background:#1c1917;border-top:1px solid #292524;overflow-wrap:anywhere;">
              <p style="margin:0 0 5px;font-size:12px;line-height:1.4;color:#d6d3d1;font-weight:700;">Nivora Restaurant · Segurança de acesso</p>
              <p style="margin:0;font-size:11px;line-height:1.5;color:#78716c;">Email automático — não responda a esta mensagem.<br>&copy; ' . $year . ' Nivora Restaurant. Todos os direitos reservados.</p>
            </td>
          </tr>
        </table>
        </div>
      </td>
    </tr>
  </table>
</body>
</html>';

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }
}
