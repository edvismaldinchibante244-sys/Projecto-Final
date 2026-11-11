<?php
/**
 * Terminais de confianca do restaurante + PIN da equipa.
 *
 * Como os sistemas de restaurante (Toast, Square): o administrador marca um computador ou
 * tablet como terminal do restaurante; a equipa entra nesse terminal so com o seu PIN.
 * Seguranca:
 *  - o terminal e "algo que o restaurante tem": cookie HttpOnly com um codigo aleatorio de
 *    256 bits; na base fica so o sha256. O administrador pode revogar a qualquer momento;
 *  - o PIN e "algo que o funcionario sabe": guardado com password_hash; 5 erros -> 15 min
 *    de bloqueio; limite de tentativas por terminal;
 *  - so perfis da equipa (TERMINAL_PERFIS_PIN). Administrador e Super Admin entram sempre
 *    com email + palavra-passe (+ 2FA).
 */

require_once __DIR__ . '/security.php';

if (!defined('TERMINAL_COOKIE')) {
    define('TERMINAL_COOKIE', 'nivora_terminal');
    define('TERMINAL_VALIDADE_DIAS', 180);
    define('TERMINAL_PERFIS_PIN', ['GARCOM', 'CAIXA', 'GERENTE', 'COZINHA', 'BAR']);
    define('PIN_MAX_FALHAS', 5);
    define('PIN_BLOQUEIO_MINUTOS', 15);
}

if (!function_exists('terminal_tem_cookie')) {
    /** Verificacao barata (sem base de dados) para decidir para onde redirecionar. */
    function terminal_tem_cookie(): bool
    {
        $v = (string)($_COOKIE[TERMINAL_COOKIE] ?? '');
        return (bool)preg_match('/^[a-f0-9]{64}$/', $v);
    }

    /** Para onde mandar quem ficou sem sessao: ecra de bloqueio num terminal, login nos outros. */
    function terminal_destino_sem_sessao(string $login = 'index.php'): string
    {
        // Um so ecra para todos: "Sessao em pausa" (quem estava continua com o PIN ou a palavra-passe).
        require_once __DIR__ . '/sessao_retomar.php';
        if (sr_tem_cookie()) {
            $voltar = sr_pagina_atual();
            return 'retomar.php' . ($voltar !== '' ? '?voltar=' . rawurlencode($voltar) : '');
        }
        // Sem pausa (ex.: depois de Sair): no terminal, a equipa escolhe quem entra; nos outros, o login.
        return terminal_tem_cookie() ? 'terminal.php' : $login;
    }

    function terminal_normalizar_perfil(?string $perfil): string
    {
        $p = strtoupper(trim((string)$perfil));
        return in_array($p, ['GARÇOM', 'GARÃ‡OM'], true) ? 'GARCOM' : $p;
    }

    function terminal_perfil_usa_pin(?string $perfil): bool
    {
        return in_array(terminal_normalizar_perfil($perfil), TERMINAL_PERFIS_PIN, true);
    }

    function terminal_gravar_cookie(string $valor, int $expira): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(TERMINAL_COOKIE, $valor, [
            'expires' => $expira,
            'path' => '/',
            'secure' => security_is_production() || security_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Terminal valido deste browser (nao revogado, restaurante ativo), ou null. */
    function terminal_atual(PDO $db): ?array
    {
        if (!terminal_tem_cookie()) {
            return null;
        }
        $stmt = $db->prepare("
            SELECT t.id, t.restaurante_id, t.nome, r.nome AS restaurante_nome
            FROM terminais t
            INNER JOIN restaurantes r ON r.id = t.restaurante_id
            WHERE t.token_hash = :h AND t.revogado_em IS NULL
              AND t.criado_em >= DATE_SUB(NOW(), INTERVAL " . (int)TERMINAL_VALIDADE_DIAS . " DAY)
              AND UPPER(COALESCE(r.status, 'ATIVO')) = 'ATIVO'
            LIMIT 1
        ");
        $stmt->execute([':h' => hash('sha256', (string)$_COOKIE[TERMINAL_COOKIE])]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        return $t ?: null;
    }

    /** Marca este browser como terminal do restaurante. Devolve o id do terminal. */
    function terminal_registar(PDO $db, int $restauranteId, string $nome, int $criadoPor): int
    {
        $nome = trim(mb_substr(preg_replace('/\s+/', ' ', $nome) ?? '', 0, 80));
        if ($restauranteId <= 0 || $nome === '') {
            throw new InvalidArgumentException('Indique um nome para o terminal (ex.: Caixa 1).');
        }
        $token = bin2hex(random_bytes(32));
        $dispositivo = terminal_descrever_dispositivo((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (terminal_tem_coluna($db, 'dispositivo')) {
            $db->prepare('INSERT INTO terminais (restaurante_id, nome, dispositivo, token_hash, criado_por) VALUES (?, ?, ?, ?, ?)')
                ->execute([$restauranteId, $nome, $dispositivo, hash('sha256', $token), $criadoPor ?: null]);
        } else {
            $db->prepare('INSERT INTO terminais (restaurante_id, nome, token_hash, criado_por) VALUES (?, ?, ?, ?)')
                ->execute([$restauranteId, $nome, hash('sha256', $token), $criadoPor ?: null]);
        }
        $id = (int)$db->lastInsertId();
        terminal_gravar_cookie($token, time() + TERMINAL_VALIDADE_DIAS * 86400);
        $_COOKIE[TERMINAL_COOKIE] = $token;
        return $id;
    }

    function terminal_listar(PDO $db, int $restauranteId): array
    {
        $colDisp = terminal_tem_coluna($db, 'dispositivo') ? ', dispositivo' : '';
        $stmt = $db->prepare('SELECT id, nome' . $colDisp . ', criado_em, ultimo_uso_em FROM terminais WHERE restaurante_id = ? AND revogado_em IS NULL ORDER BY criado_em DESC');
        $stmt->execute([$restauranteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function terminal_revogar(PDO $db, int $restauranteId, int $terminalId): bool
    {
        $stmt = $db->prepare('UPDATE terminais SET revogado_em = NOW() WHERE id = ? AND restaurante_id = ? AND revogado_em IS NULL');
        $stmt->execute([$terminalId, $restauranteId]);
        return $stmt->rowCount() > 0;
    }

    /** Funcionarios que podem desbloquear este terminal (equipa ativa com PIN). */
    function terminal_funcionarios(PDO $db, int $restauranteId): array
    {
        $in = implode(',', array_fill(0, count(TERMINAL_PERFIS_PIN) + 1, '?'));
        $stmt = $db->prepare("
            SELECT id, nome, perfil, foto
            FROM usuarios
            WHERE restaurante_id = ? AND pin_hash IS NOT NULL
              AND COALESCE(ativo, 1) = 1 AND COALESCE(super_admin, 0) = 0
              AND UPPER(perfil) IN ($in)
            ORDER BY nome
        ");
        $stmt->execute(array_merge([$restauranteId], TERMINAL_PERFIS_PIN, ['GARÇOM']));
        $lista = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $nome = function_exists('personal_data_decrypt_result') ? personal_data_decrypt_result($u['nome'], 'nome') : $u['nome'];
            $lista[] = ['id' => (int)$u['id'], 'nome' => (string)$nome, 'perfil' => terminal_normalizar_perfil($u['perfil'])];
        }
        return $lista;
    }

    /** null se o PIN for aceitavel; senao a mensagem para o utilizador. */
    function pin_problema(string $pin): ?string
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            return 'O PIN deve ter 4 a 6 dígitos.';
        }
        if (preg_match('/^(\d)\1+$/', $pin) || strpos('0123456789', $pin) !== false || strpos('9876543210', $pin) !== false) {
            return 'Esse PIN é demasiado fácil (ex.: 1111 ou 1234). Escolha outro.';
        }
        return null;
    }

    function pin_definir(PDO $db, int $usuarioId, string $pin): void
    {
        $problema = pin_problema($pin);
        if ($problema !== null) {
            throw new InvalidArgumentException($problema);
        }
        $db->prepare('UPDATE usuarios SET pin_hash = ?, pin_atualizado_em = NOW(), pin_falhas = 0, pin_bloqueado_ate = NULL WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $usuarioId]);
    }

    function pin_limpar(PDO $db, int $restauranteId, int $usuarioId): bool
    {
        $stmt = $db->prepare('UPDATE usuarios SET pin_hash = NULL, pin_falhas = 0, pin_bloqueado_ate = NULL WHERE id = ? AND restaurante_id = ?');
        $stmt->execute([$usuarioId, $restauranteId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Verifica o PIN de um utilizador do restaurante, com bloqueio por tentativas erradas.
     * $elegivel recebe a linha do utilizador e diz se ele pode usar PIN neste contexto.
     * @return array{ok:bool, mensagem?:string, linha?:array}
     */
    function pin_verificar(PDO $db, int $restauranteId, int $usuarioId, string $pin, callable $elegivel, array $contextoLog = []): array
    {
        $falha = ['ok' => false, 'mensagem' => 'PIN incorreto.'];
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                SELECT u.*, r.plano, r.nome AS restaurante_nome,
                       (u.pin_bloqueado_ate IS NOT NULL AND u.pin_bloqueado_ate > NOW()) AS pin_bloqueado
                FROM usuarios u
                INNER JOIN restaurantes r ON r.id = u.restaurante_id
                WHERE u.id = ? AND u.restaurante_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$usuarioId, $restauranteId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            $ok = $u && !empty($u['pin_hash']) && (int)($u['ativo'] ?? 1) === 1
                && (int)($u['super_admin'] ?? 0) === 0 && $elegivel($u);
            if (!$ok) {
                $db->commit();
                password_verify($pin, '$2y$10$usesomesillystringforsalt$'); // tempo semelhante
                return $falha;
            }
            if ((int)$u['pin_bloqueado'] === 1) {
                $db->commit();
                return ['ok' => false, 'mensagem' => 'PIN bloqueado por tentativas erradas. Tente daqui a ' . PIN_BLOQUEIO_MINUTOS . ' minutos ou peça ao administrador.'];
            }
            if (!password_verify($pin, (string)$u['pin_hash'])) {
                $falhas = (int)$u['pin_falhas'] + 1;
                $bloquear = $falhas >= PIN_MAX_FALHAS;
                $db->prepare('UPDATE usuarios SET pin_falhas = ?, pin_bloqueado_ate = ' . ($bloquear ? 'DATE_ADD(NOW(), INTERVAL ' . (int)PIN_BLOQUEIO_MINUTOS . ' MINUTE)' : 'pin_bloqueado_ate') . ' WHERE id = ?')
                    ->execute([$bloquear ? 0 : $falhas, $usuarioId]);
                $db->commit();
                security_log_event('pin_falha', $contextoLog + ['usuario_id' => $usuarioId, 'bloqueou' => $bloquear]);
                return $bloquear
                    ? ['ok' => false, 'mensagem' => 'PIN errado ' . PIN_MAX_FALHAS . ' vezes: bloqueado durante ' . PIN_BLOQUEIO_MINUTOS . ' minutos.']
                    : $falha;
            }
            $db->prepare('UPDATE usuarios SET pin_falhas = 0, pin_bloqueado_ate = NULL WHERE id = ?')->execute([$usuarioId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'linha' => $u];
    }

    /**
     * Verifica o PIN de um funcionario NESTE terminal.
     * @return array{ok:bool, mensagem?:string, usuario?:array}
     */
    function pin_entrar(PDO $db, array $terminal, int $usuarioId, string $pin): array
    {
        if (!security_rate_limit('terminal_pin_' . (int)$terminal['id'], 30, 300)) {
            return ['ok' => false, 'mensagem' => 'Demasiadas tentativas neste terminal. Aguarde uns minutos.'];
        }
        $r = pin_verificar(
            $db,
            (int)$terminal['restaurante_id'],
            $usuarioId,
            $pin,
            static fn(array $u): bool => terminal_perfil_usa_pin($u['perfil'] ?? ''),
            ['terminal_id' => (int)$terminal['id']]
        );
        if (!$r['ok']) {
            terminal_registar_acesso($db, (int)$terminal['restaurante_id'], (int)$terminal['id'], $usuarioId, 'FALHOU');
            return $r;
        }
        $db->prepare('UPDATE terminais SET ultimo_uso_em = NOW() WHERE id = ?')->execute([(int)$terminal['id']]);
        terminal_registar_acesso($db, (int)$terminal['restaurante_id'], (int)$terminal['id'], $usuarioId, 'OK');

        $u = function_exists('personal_data_decrypt_result') ? personal_data_decrypt_result($r['linha']) : $r['linha'];
        return ['ok' => true, 'usuario' => [
            'id' => (int)$u['id'],
            'nome' => (string)$u['nome'],
            'email' => (string)($u['email'] ?? ''),
            'perfil' => terminal_normalizar_perfil($u['perfil']),
            'restaurante_id' => (int)$u['restaurante_id'],
            'restaurante_nome' => (string)($u['restaurante_nome'] ?? ''),
            'plano' => (string)($u['plano'] ?? 'BASICO'),
            'foto' => (string)($u['foto'] ?? ''),
            'super_admin' => 0,
        ]];
    }

    /** Ha uma coluna com este nome na tabela terminais? (a migracao pode ainda nao ter sido aplicada) */
    function terminal_tem_coluna(PDO $db, string $coluna): bool
    {
        static $cache = [];
        if (array_key_exists($coluna, $cache)) {
            return $cache[$coluna];
        }
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'terminais' AND COLUMN_NAME = ?");
            $stmt->execute([$coluna]);
            $cache[$coluna] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache[$coluna] = false;
        }
        return $cache[$coluna];
    }

    /** Descricao curta e legivel do aparelho, a partir do User-Agent. Sem dados pessoais. */
    function terminal_descrever_dispositivo(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return '';
        }
        $sistema = 'Outro sistema';
        foreach ([
            'Windows NT' => 'Windows',
            'Android' => 'Android',
            'iPhone' => 'iPhone',
            'iPad' => 'iPad',
            'Mac OS X' => 'Mac',
            'CrOS' => 'ChromeOS',
            'Linux' => 'Linux',
        ] as $agulha => $nome) {
            if (stripos($ua, $agulha) !== false) {
                $sistema = $nome;
                break;
            }
        }
        $navegador = 'Navegador';
        foreach ([
            'Edg/' => 'Edge',
            'OPR/' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ] as $agulha => $nome) {
            if (stripos($ua, $agulha) !== false) {
                $navegador = $nome;
                break;
            }
        }
        return mb_substr($sistema . ' · ' . $navegador, 0, 80, 'UTF-8');
    }

    /** Guarda quem desbloqueou (ou tentou desbloquear) um terminal. Nunca interrompe o login. */
    function terminal_registar_acesso(PDO $db, int $restauranteId, int $terminalId, ?int $usuarioId, string $resultado): void
    {
        try {
            $db->prepare('INSERT INTO terminal_acessos (restaurante_id, terminal_id, usuario_id, resultado) VALUES (?, ?, ?, ?)')
                ->execute([$restauranteId, $terminalId, $usuarioId ?: null, strtoupper(substr($resultado, 0, 12))]);
        } catch (Throwable $e) {
            error_log('terminal_registar_acesso: ' . $e->getMessage());
        }
    }

    /** Ultimos desbloqueios de um terminal (ou de todos, quando $terminalId e 0). */
    function terminal_acessos_recentes(PDO $db, int $restauranteId, int $terminalId = 0, int $limite = 20): array
    {
        try {
            $sql = "SELECT a.criado_em, a.resultado, a.terminal_id, u.nome AS usuario_nome, t.nome AS terminal_nome
                    FROM terminal_acessos a
                    LEFT JOIN usuarios u ON u.id = a.usuario_id
                    LEFT JOIN terminais t ON t.id = a.terminal_id
                    WHERE a.restaurante_id = :rid";
            $params = [':rid' => $restauranteId];
            if ($terminalId > 0) {
                $sql .= ' AND a.terminal_id = :tid';
                $params[':tid'] = $terminalId;
            }
            $sql .= ' ORDER BY a.criado_em DESC, a.id DESC LIMIT ' . max(1, min(100, $limite));
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('terminal_acessos_recentes: ' . $e->getMessage());
            return [];
        }
    }

    /** Desbloqueia o PIN de um funcionario sem o apagar (item 17). */
    function pin_desbloquear(PDO $db, int $restauranteId, int $usuarioId): bool
    {
        $stmt = $db->prepare('UPDATE usuarios SET pin_falhas = 0, pin_bloqueado_ate = NULL
            WHERE id = ? AND restaurante_id = ? AND pin_hash IS NOT NULL');
        $stmt->execute([$usuarioId, $restauranteId]);
        return $stmt->rowCount() > 0;
    }
}
