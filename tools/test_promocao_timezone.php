<?php
require_once __DIR__ . '/../app/src/config/database.php';
require_once __DIR__ . '/../app/src/config/promocao_schema.php';

$local = '2026-08-06T13:44';
$utc = promocao_data_local_para_utc($local);
$roundTrip = promocao_data_utc_para_local($utc);
if ($utc !== '2026-08-06 11:44:00' || $roundTrip !== '2026-08-06 13:44:00') {
    fwrite(STDERR, "FALHA: conversao Maputo/UTC incorreta: {$local} -> {$utc} -> {$roundTrip}\n");
    exit(1);
}

$db = (new Database())->getConnection();
promocao_schema_garantir($db);
$rows = $db->query("
    SELECT id, inicio_em, fim_em, ativo,
           CASE
               WHEN ativo = 0 THEN 'INATIVA'
               WHEN fim_em IS NOT NULL AND fim_em < NOW() THEN 'EXPIRADA'
               WHEN inicio_em IS NOT NULL AND inicio_em > NOW() THEN 'AGENDADA'
               ELSE 'ATIVA'
           END AS estado_mysql
    FROM promocoes
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
foreach ($rows as $row) {
    $inicio = !empty($row['inicio_em']) ? new DateTimeImmutable($row['inicio_em'], new DateTimeZone('UTC')) : null;
    $fim = !empty($row['fim_em']) ? new DateTimeImmutable($row['fim_em'], new DateTimeZone('UTC')) : null;
    $estadoPhp = !(int)$row['ativo']
        ? 'INATIVA'
        : ($fim && $fim < $now
            ? 'EXPIRADA'
            : ($inicio && $inicio > $now ? 'AGENDADA' : 'ATIVA'));
    if ($estadoPhp !== $row['estado_mysql']) {
        fwrite(STDERR, 'FALHA: divergencia de estado na promocao #' . $row['id'] . ": PHP={$estadoPhp}, MySQL={$row['estado_mysql']}\n");
        exit(1);
    }
}

$active = count(array_filter($rows, static fn($row) => $row['estado_mysql'] === 'ATIVA'));
echo 'OK: timezone Africa/Maputo normalizado; ' . count($rows) . ' promocao(oes), ' . $active . " ativa(s).\n";
