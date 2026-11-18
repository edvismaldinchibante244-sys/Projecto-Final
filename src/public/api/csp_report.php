<?php

/*
 * Recebe as queixas do browser quando a politica de seguranca de conteudo
 * bloqueia alguma coisa.
 *
 * Sem isto a politica esta em vigor mas e cega: se bloquear um script de que
 * uma pagina precisa, o restaurante ve a funcionalidade avariada e nos nao
 * ficamos a saber de nada.
 *
 * Este endpoint e publico -- tem de ser, porque e o browser que escreve para
 * ca, sem sessao. Por isso leva cinto e suspensorios:
 *   - limite de pedidos por IP;
 *   - corpo limitado a 16 KB (um relatorio legitimo tem menos de 1 KB);
 *   - o ficheiro de registo para de crescer aos 2 MB;
 *   - queixas repetidas contam, nao se repetem linha a linha;
 *   - NUNCA responde com conteudo, so 204.
 *
 * Privacidade: o browser manda o endereco completo da pagina, e algumas das
 * nossas levam segredos no endereco (o token de recuperacao de senha, por
 * exemplo). A query string e cortada antes de se escrever seja o que for.
 */

require_once __DIR__ . '/../app_base.php';
require_once APP_BASE . '/src/config/security.php';
require_once APP_BASE . '/src/config/storage_path.php';

http_response_code(204);
header('Content-Type: text/plain; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

// 60 relatorios por minuto por IP: um browser com uma pagina muito partida
// manda varios de seguida, mas nao centenas.
if (function_exists('security_rate_limit') && !security_rate_limit('csp_report', 60, 60)) {
    exit;
}

$bruto = file_get_contents('php://input', false, null, 0, 16384);
if ($bruto === false || $bruto === '') {
    exit;
}

$dados = json_decode($bruto, true);
if (!is_array($dados)) {
    exit;
}

// Formato antigo (report-uri) e novo (report-to) tem caixas diferentes.
$relatorios = [];
if (isset($dados['csp-report']) && is_array($dados['csp-report'])) {
    $relatorios[] = $dados['csp-report'];
} elseif (isset($dados[0]) && is_array($dados[0])) {
    foreach ($dados as $item) {
        if (is_array($item) && isset($item['body']) && is_array($item['body'])) {
            $relatorios[] = $item['body'];
        }
    }
} else {
    $relatorios[] = $dados;
}

$semSegredos = static function ($url): string {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    // Corta a query string: e la que viajam os tokens.
    $corte = strcspn($url, '?#');
    $url = substr($url, 0, $corte);

    return mb_substr($url, 0, 300);
};

$ficheiro = nv_storage('logs/csp_violacoes.log');
$pasta = dirname($ficheiro);
if (!is_dir($pasta)) {
    @mkdir($pasta, 0775, true);
}

// Aos 2 MB para de escrever, em vez de encher o disco do servidor.
if (is_file($ficheiro) && filesize($ficheiro) > 2 * 1024 * 1024) {
    exit;
}

foreach ($relatorios as $r) {
    if (!is_array($r)) {
        continue;
    }

    $directiva = mb_substr(trim((string)($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? '')), 0, 60);
    $bloqueado = $semSegredos($r['blocked-uri'] ?? $r['blockedURL'] ?? '');
    $pagina = $semSegredos($r['document-uri'] ?? $r['documentURL'] ?? '');
    $linha = (int)($r['line-number'] ?? $r['lineNumber'] ?? 0);

    if ($directiva === '' && $bloqueado === '' && $pagina === '') {
        continue;
    }

    $registo = sprintf(
        "[%s] %s | bloqueado: %s | pagina: %s%s%s",
        date('Y-m-d H:i:s'),
        $directiva !== '' ? $directiva : '(sem directiva)',
        $bloqueado !== '' ? $bloqueado : '(inline)',
        $pagina !== '' ? $pagina : '(desconhecida)',
        $linha > 0 ? ' | linha: ' . $linha : '',
        PHP_EOL
    );

    @file_put_contents($ficheiro, $registo, FILE_APPEND | LOCK_EX);
}

exit;
