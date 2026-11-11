<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/app/src/api/reserva_notificacoes.php');

$checks = 0;
$assert = static function (bool $cond, string $msg) use (&$checks): void {
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
};

$assert(str_contains($source, 'NIVORA RESTAURANT'), 'template deve usar cabecalho Nivora');
$assert(str_contains($source, 'Reservas e atendimento ao cliente'), 'template deve ter contexto de reservas');
$assert(str_contains($source, 'Reserva confirmada com sucesso'), 'template deve destacar confirmacao');
$assert(str_contains($source, 'Resumo da reserva'), 'template deve organizar resumo da reserva');
$assert(str_contains($source, 'Informacoes do restaurante'), 'template deve manter dados do restaurante');
$assert(str_contains($source, 'Gestao de reservas'), 'template deve usar rodape profissional');
$assert(!str_contains($source, 'Resumo rÃ¡pido:'), 'template antigo com resumo simples nao deve voltar');

echo "OK: template de email de reserva validado ({$checks} verificacoes).\n";
