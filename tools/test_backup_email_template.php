<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/app/src/config/backup_helper.php');

$checks = 0;
$assert = static function (bool $cond, string $msg) use (&$checks): void {
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
};

$assert(str_contains($source, 'NIVORA RESTAURANT'), 'template deve usar cabecalho Nivora');
$assert(str_contains($source, 'Backup e continuidade operacional'), 'template deve ter contexto empresarial');
$assert(str_contains($source, 'Os dados do restaurante foram protegidos'), 'template deve explicar resultado ao cliente');
$assert(str_contains($source, 'Copia guardada com sucesso'), 'template deve destacar sucesso');
$assert(str_contains($source, 'Nota de seguranca'), 'template deve explicar que o caminho interno nao e exposto');
$assert(str_contains($source, 'Configuracoes &gt; Backup'), 'template deve orientar onde consultar backup');
$assert(!str_contains($source, '<code>{$arquivoPath}</code>'), 'template antigo com caminho interno nao deve ser usado');

echo "OK: template de email de backup validado ({$checks} verificacoes).\n";
