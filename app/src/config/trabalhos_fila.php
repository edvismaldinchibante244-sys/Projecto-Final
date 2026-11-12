<?php
require_once __DIR__ . '/storage_path.php';
/**
 * Fila de trabalhos em segundo plano (tabela trabalhos_fila).
 *
 * Trabalhos pesados (ex.: exportar todos os dados) nao correm dentro do pedido do utilizador,
 * que podia expirar. O pedido regista o trabalho e a pagina vai perguntando o estado.
 * Quem processa:
 *   - a tarefa agendada src/public/cron/trabalhos.php (a cada minuto, se estiver configurada);
 *   - ou, se nao houver tarefa agendada, o proprio pedido de estado (fila_processar_pendente),
 *     depois de responder ao browser quando o servidor o permite.
 * Um trabalho so e processado uma vez (reserva atomica). Os ficheiros prontos ficam 24 h.
 */

if (!defined('FILA_HORAS_FICHEIRO')) {
    define('FILA_HORAS_FICHEIRO', 24);
    define('FILA_MAX_TENTATIVAS', 3);
}

if (!function_exists('fila_disponivel')) {
    function fila_disponivel(PDO $db): bool
    {
        try {
            $db->query('SELECT 1 FROM trabalhos_fila LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    function fila_pasta(): string
    {
        $dir = nv_storage('trabalhos');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new RuntimeException('Não foi possível criar a pasta dos ficheiros (' . $dir . ').');
        }
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    /** Regista um trabalho. Se ja houver um igual por fazer, devolve esse (evita duplicados). */
    function fila_criar(PDO $db, int $rid, int $uid, string $tipo, array $parametros = []): array
    {
        $st = $db->prepare("SELECT * FROM trabalhos_fila WHERE restaurante_id = ? AND tipo = ? AND estado IN ('PENDENTE', 'A_PROCESSAR')
            AND COALESCE(parametros, '') = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$rid, $tipo, $parametros ? (string)json_encode($parametros) : '']);
        $existente = $st->fetch(PDO::FETCH_ASSOC);
        if ($existente) {
            return $existente;
        }
        $db->prepare('INSERT INTO trabalhos_fila (restaurante_id, usuario_id, tipo, parametros) VALUES (?, ?, ?, ?)')
            ->execute([$rid, $uid ?: null, $tipo, $parametros ? json_encode($parametros) : null]);
        return fila_obter($db, (int)$db->lastInsertId()) ?? [];
    }

    function fila_obter(PDO $db, int $id): ?array
    {
        $st = $db->prepare('SELECT *, UNIX_TIMESTAMP(expira_em) AS expira_ts FROM trabalhos_fila WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Devolve trabalhos presos (servidor reiniciado a meio) a fila, ou marca-os como erro. */
    function fila_recuperar_presos(PDO $db): void
    {
        $db->exec("UPDATE trabalhos_fila SET estado = IF(tentativas >= " . FILA_MAX_TENTATIVAS . ", 'ERRO', 'PENDENTE'),
                erro = IF(tentativas >= " . FILA_MAX_TENTATIVAS . ", 'O trabalho foi interrompido várias vezes.', erro), reserva = NULL
            WHERE estado = 'A_PROCESSAR' AND iniciado_em < NOW() - INTERVAL 15 MINUTE");
    }

    /** Reserva um trabalho pendente (o $id indicado, ou o mais antigo). Devolve-o ou null. */
    function fila_reservar(PDO $db, ?int $id = null): ?array
    {
        $reserva = bin2hex(random_bytes(8));
        $sql = "UPDATE trabalhos_fila SET estado = 'A_PROCESSAR', reserva = ?, iniciado_em = NOW(), tentativas = tentativas + 1
                WHERE estado = 'PENDENTE'" . ($id ? ' AND id = ?' : '') . ' ORDER BY id LIMIT 1';
        $st = $db->prepare($sql);
        $st->execute($id ? [$reserva, $id] : [$reserva]);
        if ($st->rowCount() < 1) {
            return null;
        }
        $st = $db->prepare('SELECT *, UNIX_TIMESTAMP(expira_em) AS expira_ts FROM trabalhos_fila WHERE reserva = ?');
        $st->execute([$reserva]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Corre um trabalho ja reservado. Devolve o trabalho atualizado. */
    function fila_executar(PDO $db, array $t, bool $avisarPorEmail = false): array
    {
        @set_time_limit(900);
        $id = (int)$t['id'];
        try {
            if ($t['tipo'] !== 'exportar_dados') {
                throw new RuntimeException('Tipo de trabalho desconhecido.');
            }
            if (!function_exists('enc_exportar_zip')) {
                require_once __DIR__ . '/plano_encerramento.php';
            }
            $parametros = [];
            if (!empty($t['parametros'])) {
                $parametros = json_decode((string)$t['parametros'], true) ?: [];
            }
            $daRede = !empty($parametros['rede']) && function_exists('enc_exportar_zip_rede');
            $tmp = $daRede
                ? enc_exportar_zip_rede($db, (int)$t['restaurante_id'])
                : enc_exportar_zip($db, (int)$t['restaurante_id']);
            $destino = fila_pasta() . DIRECTORY_SEPARATOR . 'exp_' . $id . '_' . bin2hex(random_bytes(8)) . '.zip';
            if (!@rename($tmp, $destino)) {
                if (!@copy($tmp, $destino)) {
                    throw new RuntimeException('Não foi possível guardar o ficheiro.');
                }
                @unlink($tmp);
            }
            $nome = ($daRede ? 'dados-rede-' : 'dados-restaurante-') . (int)$t['restaurante_id'] . '-' . date('Ymd-Hi') . '.zip';
            $db->prepare("UPDATE trabalhos_fila SET estado = 'PRONTO', ficheiro = ?, nome_download = ?, bytes = ?, erro = NULL,
                    concluido_em = NOW(), expira_em = NOW() + INTERVAL " . FILA_HORAS_FICHEIRO . " HOUR, reserva = NULL WHERE id = ?")
                ->execute([basename($destino), $nome, (int)filesize($destino), $id]);
            if ($avisarPorEmail && function_exists('enc_enviar')) {
                enc_enviar($db, (int)$t['restaurante_id'], 'Os seus dados estão prontos para descarregar',
                    '<p>O ficheiro com todos os dados do seu restaurante está pronto.</p>'
                    . '<p>Entre no sistema e abra <b>Meus dados e plano</b> para o descarregar. O ficheiro fica disponível durante '
                    . FILA_HORAS_FICHEIRO . ' horas.</p>');
            }
        } catch (Throwable $e) {
            error_log('[fila] trabalho ' . $id . ': ' . $e->getMessage());
            $falhou = (int)$t['tentativas'] >= FILA_MAX_TENTATIVAS;
            $db->prepare('UPDATE trabalhos_fila SET estado = ?, erro = ?, reserva = NULL WHERE id = ?')
                ->execute([$falhou ? 'ERRO' : 'PENDENTE', mb_substr($e->getMessage(), 0, 480), $id]);
        }
        return fila_obter($db, $id) ?? $t;
    }

    /** Processa ate $max trabalhos pendentes (usado pela tarefa agendada). Devolve quantos correu. */
    function fila_processar(PDO $db, int $max = 5, bool $avisarPorEmail = true): int
    {
        fila_recuperar_presos($db);
        $n = 0;
        while ($n < $max && ($t = fila_reservar($db))) {
            fila_executar($db, $t, $avisarPorEmail);
            $n++;
        }
        return $n;
    }

    /** Apaga os ficheiros expirados e os registos com mais de 30 dias. */
    function fila_limpar(PDO $db): int
    {
        $n = 0;
        $st = $db->query("SELECT id, ficheiro FROM trabalhos_fila WHERE ficheiro IS NOT NULL AND expira_em < NOW()");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $f = fila_pasta() . DIRECTORY_SEPARATOR . basename((string)$r['ficheiro']);
            if (is_file($f)) {
                @unlink($f);
            }
            $db->prepare("UPDATE trabalhos_fila SET ficheiro = NULL, estado = IF(estado = 'PRONTO', 'EXPIRADO', estado) WHERE id = ?")->execute([(int)$r['id']]);
            $n++;
        }
        $db->exec('DELETE FROM trabalhos_fila WHERE criado_em < NOW() - INTERVAL 30 DAY');
        return $n;
    }

    /** Estado para mostrar ao utilizador (sem caminhos do servidor). */
    function fila_publico(array $t): array
    {
        return [
            'id' => (int)$t['id'],
            'estado' => (string)$t['estado'],
            'pronto' => $t['estado'] === 'PRONTO' && !empty($t['ficheiro']),
            'tamanho' => isset($t['bytes']) ? (int)$t['bytes'] : null,
            'erro' => $t['estado'] === 'ERRO' ? (string)($t['erro'] ?? '') : null,
            'expira_ts' => isset($t['expira_ts']) ? (int)$t['expira_ts'] : null,
        ];
    }
}
