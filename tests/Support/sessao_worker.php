<?php
// Simula pedidos HTTP em paralelo sobre a mesma sessao (corre num processo PHP separado).
// Uso: php sessao_worker.php <pasta_sessoes>
declare(strict_types=1);
$pasta = $argv[1];
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '1');
session_save_path($pasta);
define('APP_BASE', dirname(__DIR__, 2) . '/app');
require_once APP_BASE . '/src/config/security.php';

$abrir = static function (string $id): array {
    session_id($id);
    session_start();
    security_session_verificar_obsoleta();
    $dados = $_SESSION;
    return $dados;
};
$fechar = static function (): void { session_write_close(); };

// Login: sessao com utilizador, ultima renovacao ha 20 minutos.
session_start();
$_SESSION['logado'] = true;
$_SESSION['last_regen'] = time() - 1200;
$antigo = session_id();
$fechar();

// Pedido A renova (passaram 15 min).
$abrir($antigo);
security_regenerate_session(15);
$novo = session_id();
$fechar();

// Pedido B saiu ao mesmo tempo com o cookie antigo.
$b = $abrir($antigo);
security_regenerate_session(15);
$idDepoisB = session_id();
$fechar();

// Pedido seguinte do browser ja com o cookie novo.
$c = $abrir($novo);
$fechar();

// Alguem usa o cookie antigo 2 minutos depois.
$ficheiro = $pasta . '/sess_' . $antigo;
$conteudo = (string)file_get_contents($ficheiro);
file_put_contents($ficheiro, preg_replace('/__sessao_obsoleta_em\|i:\d+;/', '__sessao_obsoleta_em|i:' . (time() - 120) . ';', $conteudo));
$d = $abrir($antigo);
$fechar();

echo json_encode([
    'renovou' => $novo !== $antigo,
    'b_continua_logado' => !empty($b['logado']),
    'b_nao_renovou_outra_vez' => $idDepoisB === $antigo,
    'novo_logado' => !empty($c['logado']),
    'novo_nao_obsoleto' => !isset($c['__sessao_obsoleta_em']),
    'antigo_depois_da_graca_logado' => !empty($d['logado']),
]);
