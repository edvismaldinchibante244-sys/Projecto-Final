<?php

/*
 * Cartao partilhado dos emails do sistema.
 *
 * Porque existe: o aspecto nasceu no email de verificacao em duas etapas
 * (email_otp_template.php) e foi copiado a mao para a recuperacao de senha e
 * para os avisos de backup. Os emails de plano e de suporte ficaram com um
 * cartao antigo, e ninguem reparou durante meses. Quem escrever um email novo
 * usa esta funcao e nao volta a acontecer.
 *
 * Cor: o destaque do cabecalho leva a cor do plano do restaurante (ver
 * plano_cores.php). O titulo NAO leva cor nenhuma -- e quase preto, de
 * proposito. O que sinaliza "aprovado", "rejeitado" ou "a vencer" e o
 * sobretitulo, e so ele: e la que se poe o verde, o vermelho ou o laranja.
 */

require_once __DIR__ . '/plano_cores.php';

if (!function_exists('nivora_email_layout')) {
    /**
     * @param array{
     *   titulo:string, sobretitulo?:string, sobretitulo_cor?:string,
     *   saudacao?:string, paragrafos?:array, dados?:array,
     *   destaque?:string, destaque_titulo?:string,
     *   botao_texto?:string, botao_url?:string,
     *   nota?:string, rodape?:string, plano?:string
     * } $o
     */
    function nivora_email_layout(array $o): string
    {
        $esc = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };

        $marca = 'Nivora Restaurant';
        if (function_exists('plano_notif_system_name')) {
            $n = trim((string)plano_notif_system_name());
            if ($n !== '') {
                $marca = $n;
            }
        }
        $inicial = mb_strtoupper(mb_substr($marca, 0, 1, 'UTF-8'), 'UTF-8');

        $plano = trim((string)($o['plano'] ?? ''));
        $destaqueCor = $plano !== '' ? plano_cor($plano) : '#d9773a';
        $destaqueSuave = $plano !== '' ? plano_cor_suave($plano) : '#fff7ed';
        $etiqueta = '';
        if ($plano !== '') {
            $info = plano_cor_info($plano);
            $etiqueta = "<span style=\"display:inline-block;margin-top:7px;padding:3px 10px;border-radius:999px;"
                . "background:" . $esc($info['cor']) . ";color:#ffffff;font-size:10px;font-weight:800;"
                . "letter-spacing:.1em;text-transform:uppercase;\">" . $esc($info['nome']) . "</span>";
        }

        $sobretitulo = trim((string)($o['sobretitulo'] ?? ''));
        $sobreCor = trim((string)($o['sobretitulo_cor'] ?? '')) ?: '#c15f2a';
        $blocoSobre = $sobretitulo !== ''
            ? "<p style=\"margin:0 0 10px;font-size:12px;line-height:1.3;letter-spacing:0.12em;"
                . "text-transform:uppercase;color:" . $esc($sobreCor) . ";font-weight:800;\">" . $esc($sobretitulo) . "</p>"
            : '';

        $saudacao = trim((string)($o['saudacao'] ?? ''));
        $blocoSaudacao = $saudacao !== ''
            ? "<p style=\"margin:0 0 12px;font-size:16px;line-height:1.6;color:#44403c;\">Olá, <strong style=\"color:#1c1917;\">"
                . $esc($saudacao) . "</strong>.</p>"
            : '';

        $textos = '';
        foreach ((array)($o['paragrafos'] ?? []) as $par) {
            $par = trim((string)$par);
            if ($par !== '') {
                $textos .= "<p style=\"margin:0 0 12px;font-size:15px;line-height:1.65;color:#57534e;\">" . $esc($par) . "</p>";
            }
        }

        $linhas = '';
        foreach ((array)($o['dados'] ?? []) as $rotulo => $valor) {
            if (trim((string)$valor) === '') {
                continue;
            }
            $linhas .= "<tr>"
                . "<td style=\"padding:11px 14px;border-bottom:1px solid #e7e5e4;font-size:13px;color:#78716c;width:40%;\">" . $esc($rotulo) . "</td>"
                . "<td style=\"padding:11px 14px;border-bottom:1px solid #e7e5e4;font-size:14px;color:#1c1917;font-weight:700;\">" . $esc($valor) . "</td>"
                . "</tr>";
        }
        $tabela = $linhas !== ''
            ? "<table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" "
                . "style=\"width:100%;table-layout:fixed;background:#fafaf9;border:1px solid #e7e5e4;border-radius:12px;margin:4px 0 6px;\">"
                . $linhas . "</table>"
            : '';

        $destaque = '';
        $textoDestaque = trim((string)($o['destaque'] ?? ''));
        if ($textoDestaque !== '') {
            $tituloDestaque = trim((string)($o['destaque_titulo'] ?? ''));
            $destaque = "<table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" "
                . "style=\"width:100%;table-layout:fixed;background:" . $esc($destaqueSuave)
                . ";border:1px solid #e7e5e4;border-left:4px solid " . $esc($destaqueCor)
                . ";border-radius:14px;margin:6px 0;\"><tr>"
                . "<td style=\"padding:18px 20px;overflow-wrap:anywhere;\">"
                . ($tituloDestaque !== ''
                    ? "<p style=\"margin:0 0 8px;font-size:11px;line-height:1.2;letter-spacing:0.14em;text-transform:uppercase;color:" . $esc($destaqueCor) . ";font-weight:800;\">" . $esc($tituloDestaque) . "</p>"
                    : '')
                . "<p style=\"margin:0;font-size:15px;line-height:1.6;color:#1c1917;\">" . nl2br($esc($textoDestaque)) . "</p>"
                . "</td></tr></table>";
        }

        $botao = '';
        $botaoUrl = trim((string)($o['botao_url'] ?? ''));
        $botaoTexto = trim((string)($o['botao_texto'] ?? ''));
        if ($botaoUrl !== '' && $botaoTexto !== '') {
            $botao = "<p style=\"margin:22px 0 2px;\"><a href=\"" . $esc($botaoUrl) . "\" "
                . "style=\"display:inline-block;padding:13px 24px;background:#1c1917;color:#ffffff;text-decoration:none;"
                . "border-radius:10px;font-size:14px;font-weight:800;\">" . $esc($botaoTexto) . "</a></p>";
        }

        $nota = trim((string)($o['nota'] ?? ''));
        $blocoNota = $nota !== ''
            ? "<p style=\"margin:18px 0 0;font-size:13px;line-height:1.6;color:#78716c;\">" . $esc($nota) . "</p>"
            : '';

        $rodape = trim((string)($o['rodape'] ?? '')) ?: 'Email automático — não responda a esta mensagem.';
        $ano = date('Y');
        $titulo = $esc($o['titulo'] ?? '');

        return '<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<title>' . $titulo . '</title>
<style>
@media only screen and (max-width:620px) {
  .nivora-outer { padding:12px 8px !important; }
  .nivora-wrap { width:calc(100vw - 16px) !important; max-width:calc(100vw - 16px) !important; }
  .nivora-shell { width:100% !important; max-width:100% !important; border-radius:12px !important; }
  .nivora-pad { padding-left:20px !important; padding-right:20px !important; }
  .nivora-title { font-size:23px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#292524;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f4f6;">
<tr><td align="center" class="nivora-outer" style="padding:32px 12px;">
<div class="nivora-wrap" style="width:100%;max-width:600px;margin:0 auto;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" class="nivora-shell" style="width:100%;table-layout:fixed;background:#ffffff;border:1px solid #e7e5e4;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(28,25,23,0.08);">
<tr>
<td class="nivora-pad" style="padding:22px 28px;background:#1c1917;border-bottom:4px solid ' . $esc($destaqueCor) . ';">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;">
<tr>
<td width="48" valign="middle">
<div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:10px;background:' . $esc($destaqueCor) . ';color:#ffffff;font-size:20px;font-weight:800;">' . $esc($inicial) . '</div>
</td>
<td valign="middle" style="overflow-wrap:anywhere;">
<p style="margin:0;font-size:13px;line-height:1.2;letter-spacing:0.13em;text-transform:uppercase;color:#fff7ed;font-weight:800;">' . $esc($marca) . '</p>
<p style="margin:5px 0 0;font-size:12px;line-height:1.3;color:#a8a29e;">Gestão inteligente para restaurantes</p>
' . $etiqueta . '
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td class="nivora-pad" style="padding:34px 32px 10px;overflow-wrap:anywhere;">
' . $blocoSobre . '
<h1 class="nivora-title" style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#1c1917;font-weight:800;">' . $titulo . '</h1>
' . $blocoSaudacao . $textos . '
</td>
</tr>
<tr>
<td class="nivora-pad" style="padding:0 32px 6px;overflow-wrap:anywhere;">
' . $tabela . $destaque . $botao . $blocoNota . '
</td>
</tr>
<tr><td style="height:24px;line-height:24px;">&nbsp;</td></tr>
<tr>
<td class="nivora-pad" style="padding:20px 32px;background:#1c1917;border-top:1px solid #292524;overflow-wrap:anywhere;">
<p style="margin:0 0 5px;font-size:12px;line-height:1.4;color:#d6d3d1;font-weight:700;">' . $esc($marca) . '</p>
<p style="margin:0;font-size:11px;line-height:1.5;color:#78716c;">' . $esc($rodape) . '<br>&copy; ' . $ano . ' ' . $esc($marca) . '. Todos os direitos reservados.</p>
</td>
</tr>
</table>
</div>
</td></tr>
</table>
</body>
</html>';
    }
}
