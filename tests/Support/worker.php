<?php
declare(strict_types=1);

/*
 * Processo auxiliar para os testes de concorrencia (corre em paralelo).
 * Uso: php worker.php <tipo> <restaurante> [commit|rollback] [extra]
 * Imprime uma linha: OK:<valor> | RB:<valor> | ERR:<mensagem>
 */
require __DIR__ . '/../bootstrap.php';

use Nivora\Tests\Support\TestDb;

[$_, $tipo, $rid] = $argv + [null, '', '0'];
$rid = (int)$rid;
$acao = $argv[3] ?? 'commit';
$extra = $argv[4] ?? '';

try {
    switch ($tipo) {
        case 'pedido':
            require_once APP_BASE . '/src/config/pedido_schema.php';
            $db = TestDb::novaLigacao();
            pedido_sequencia_preparar($db, $rid);
            $db->beginTransaction();
            $n = pedido_proximo_numero($db, $rid);
            usleep(random_int(0, 20000));
            $db->prepare('INSERT INTO pedidos (restaurante_id, numero_pedido, criado_em) VALUES (?, ?, UTC_TIMESTAMP())')
                ->execute([$rid, $n]);
            if ($acao === 'rollback') {
                $db->rollBack();
                echo "RB:$n\n";
            } else {
                $db->commit();
                echo "OK:$n\n";
            }
            break;

        case 'fatura':
            require_once APP_BASE . '/src/Model/Venda.php';
            $db = TestDb::novaLigacao();
            $venda = new Venda($db);
            if ($extra !== 'sem_preparar') {
                $venda->prepararNumeracaoFatura($rid);
            }
            $db->beginTransaction();
            $n = $venda->gerarNumeroFatura($rid);
            usleep(random_int(0, 20000));
            $db->prepare('INSERT INTO vendas (restaurante_id, usuario_id, caixa_id, numero_fatura) VALUES (?, 1, 1, ?)')
                ->execute([$rid, $n]);
            if ($acao === 'rollback') {
                $db->rollBack();
                echo "RB:$n\n";
            } else {
                $db->commit();
                echo "OK:$n\n";
            }
            break;

        case 'ratelimit':
            $_SERVER['REMOTE_ADDR'] = '198.51.100.' . ($rid % 250);
            require_once APP_BASE . '/src/config/security.php';
            $permitido = security_rate_limit('teste_' . $extra, 10, 60);
            echo ($permitido ? 'OK:permitido' : 'OK:bloqueado') . "\n";
            break;

        case '2fa_falha':
            require_once APP_BASE . '/src/config/security.php';
            require_once APP_BASE . '/src/config/login_security.php';
            require_once APP_BASE . '/src/config/login_2fa_store.php';
            echo 'OK:' . login_api_increment_pending_2fa_attempts(['token' => $extra]) . "\n";
            break;

        case 'pagar':
            require_once APP_BASE . '/src/Service/PagamentoService.php';
            $db = TestDb::novaLigacao();
            $r = (new PagamentoService($db))->pagarPedido(
                ['restaurante_id' => $rid, 'usuario_id' => 1, 'telas_bar_cozinha' => true, 'contas_abertas' => false],
                ['pedido_id' => (int)$extra, 'forma_pagamento' => 'CARTAO']
            );
            $b = $r['body'];
            echo !empty($b['already_paid']) ? "OK:ja_pago\n" : (!empty($b['success']) ? "OK:pago\n" : 'ERR:' . ($b['message'] ?? '?') . "\n");
            break;

        default:
            echo "ERR:tipo desconhecido\n";
    }
} catch (Throwable $e) {
    echo 'ERR:' . $e->getMessage() . "\n";
}
