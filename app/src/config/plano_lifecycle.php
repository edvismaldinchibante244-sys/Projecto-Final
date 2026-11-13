<?php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/plano_notificacoes.php';
require_once __DIR__ . '/plano_downgrade.php';
require_once __DIR__ . '/migration_state.php';

if (!defined('PLANO_LIFECYCLE_SCHEMA_MIGRATION_ID')) {
    define('PLANO_LIFECYCLE_SCHEMA_MIGRATION_ID', '20260814_1345_plano_lifecycle');
}

// Carregado sob demanda para evitar ciclo de dependência (filiais_helper requer plano_check).
// A função filiais_sincronizar_plano_status_matriz_para_filiais é usada para propagar
// o status (ATIVO/BLOQUEADO) da matriz para as suas filiais.
require_once __DIR__ . '/filiais_helper.php';

if (!function_exists('plano_lifecycle_app_url')) {
    function plano_lifecycle_app_url(): string
    {
        $env = trim((string)(getenv('APP_URL') ?: ''));
        if ($env !== '') {
            $env = rtrim($env, '/');
            // Protege ambiente local: nunca usar https em localhost/127.0.0.1
            if (preg_match('#^https://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', $env) === 1) {
                $env = preg_replace('#^https://#i', 'http://', $env);
            }
            return $env;
        }

        $scheme = security_is_https() ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Quando em requisição web, usa o diretório real do script atual.
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '') {
            $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            if ($basePath !== '' && $basePath !== '.') {
                return $scheme . '://' . $host . $basePath;
            }
        }

        // Fallback para CLI/local: priorizar caminho mais comum em XAMPP.
        return $scheme . '://' . $host . '/V0001/V00/src/public';
    }
}

if (!function_exists('plano_lifecycle_payment_url')) {
    function plano_lifecycle_payment_url(int $restauranteId): string
    {
        return plano_lifecycle_app_url() . '/configuracoes.php?secao=plano&rid=' . $restauranteId;
    }
}

if (!function_exists('plano_lifecycle_ensure_tables')) {
    function plano_lifecycle_ensure_tables(PDO $db): void
    {
        static $done = [];
        $key = spl_object_id($db);
        if (isset($done[$key])) {
            return;
        }

        if (migration_esta_aplicada($db, PLANO_LIFECYCLE_SCHEMA_MIGRATION_ID)) {
            $done[$key] = true;
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS plano_cobranca_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                evento VARCHAR(80) NOT NULL,
                severidade VARCHAR(20) NOT NULL DEFAULT 'INFO',
                detalhes_json JSON NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pcl_restaurante (restaurante_id),
                INDEX idx_pcl_evento (evento),
                INDEX idx_pcl_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS plano_lifecycle_notificacoes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                restaurante_id INT NOT NULL,
                data_fim DATE NOT NULL,
                dias_restantes INT NOT NULL,
                canal VARCHAR(20) NOT NULL DEFAULT 'email',
                destinatario VARCHAR(190) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_lifecycle_notif (restaurante_id, data_fim, dias_restantes, canal),
                INDEX idx_lifecycle_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $done[$key] = true;
    }
}

if (!function_exists('plano_lifecycle_log')) {
    function plano_lifecycle_log(PDO $db, int $restauranteId, string $evento, array $detalhes = [], string $severidade = 'INFO'): void
    {
        plano_lifecycle_ensure_tables($db);
        $stmt = $db->prepare("
            INSERT INTO plano_cobranca_logs (restaurante_id, evento, severidade, detalhes_json)
            VALUES (:rid, :evento, :sev, :detalhes)
        ");
        $stmt->execute([
            ':rid' => $restauranteId,
            ':evento' => substr($evento, 0, 80),
            ':sev' => strtoupper(trim($severidade)) ?: 'INFO',
            ':detalhes' => json_encode($detalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        security_log_event('plano_lifecycle_' . $evento, ['restaurante_id' => $restauranteId] + $detalhes);
        audit_log($db, 'plano_lifecycle_' . $evento, ['restaurante_id' => $restauranteId] + $detalhes, $severidade);
    }
}

if (!function_exists('plano_lifecycle_get_restaurante_snapshot')) {
    function plano_lifecycle_get_restaurante_snapshot(PDO $db, int $restauranteId): ?array
    {
        $stmt = $db->prepare("
            SELECT r.id, r.nome, r.email, r.telefone, r.plano, r.status,
                   DATE(COALESCE(
                        (SELECT MAX(rp.data_fim) FROM restaurante_planos rp WHERE rp.restaurante_id = r.id AND rp.status = 'ATIVO'),
                        r.data_fim
                   )) AS data_fim
            FROM restaurantes r
            WHERE r.id = :rid
            LIMIT 1
        ");
        $stmt->execute([':rid' => $restauranteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!defined('PLANO_DIAS_MODO_LIMITADO')) {
    define('PLANO_DIAS_MODO_LIMITADO', 7);
}

if (!function_exists('plano_lifecycle_compute_policy')) {
    function plano_lifecycle_compute_policy(array $row): array
    {
        if (strtoupper((string)($row['status'] ?? '')) === 'CANCELADO') {
            // Conta encerrada: so o administrador entra, e apenas para exportar os dados.
            return ['estado' => 'CANCELADO', 'dias_restantes' => -9999, 'limite_nivel' => 'FULL_BLOCK'];
        }
        $dataFim = (string)($row['data_fim'] ?? '');
        if ($dataFim === '') {
            return ['estado' => 'ATIVO', 'dias_restantes' => 9999, 'limite_nivel' => 'NONE'];
        }

        $hoje = new DateTimeImmutable('today');
        $fim = new DateTimeImmutable($dataFim);
        $dias = (int)$hoje->diff($fim)->format('%r%a');

        // Termos, seccao 4: nos 7 dias seguintes ao vencimento a conta fica em modo limitado
        // (consulta e exportacao, sem vendas). Depois disso, bloqueio total excepto exportar dados.
        if ($dias < -PLANO_DIAS_MODO_LIMITADO) {
            return ['estado' => 'BLOQUEADO_INADIMPLENTE', 'dias_restantes' => $dias, 'limite_nivel' => 'FULL_BLOCK'];
        }
        if ($dias < 0) {
            return ['estado' => 'VENCIDO_GRACE', 'dias_restantes' => $dias, 'limite_nivel' => 'READ_ONLY'];
        }
        if ($dias <= 1) {
            // data_fim e inclusiva: no ultimo dia o plano ainda permanece ativo.
            return ['estado' => 'ALERTA_1D', 'dias_restantes' => $dias, 'limite_nivel' => 'NOTICE_ONLY'];
        }
        if ($dias <= 3) {
            return ['estado' => 'ALERTA_3D', 'dias_restantes' => $dias, 'limite_nivel' => 'LIMITED_NON_CRITICAL'];
        }
        if ($dias <= 7) {
            return ['estado' => 'ALERTA_7D', 'dias_restantes' => $dias, 'limite_nivel' => 'NOTICE_ONLY'];
        }
        return ['estado' => 'ATIVO', 'dias_restantes' => $dias, 'limite_nivel' => 'NONE'];
    }
}

if (!function_exists('plano_lifecycle_apply_status')) {
    function plano_lifecycle_apply_status(PDO $db, int $restauranteId, string $estado): void
    {
        $map = [
            'ATIVO' => 'ATIVO',
            'ALERTA_7D' => 'ATIVO',
            'ALERTA_3D' => 'ATIVO',
            'ALERTA_1D' => 'ATIVO',
            // Modo limitado: o login continua possivel; as vendas sao travadas em plano_lifecycle_can_access().
            'VENCIDO_GRACE' => 'ATIVO',
            'BLOQUEADO_INADIMPLENTE' => 'BLOQUEADO',
        ];
        if ($estado === 'CANCELADO') {
            return;
        }
        $status = $map[$estado] ?? 'ATIVO';
        // Uma conta cancelada nunca volta a ATIVO/BLOQUEADO por calculo automatico.
        // PENDENTE tambem fica de fora: um restaurante que ainda nao foi aprovado
        // nao pode ser activado por um calculo de validade do plano. So a
        // aprovacao o tira desse estado.
        $stmt = $db->prepare("UPDATE restaurantes SET status = :status
            WHERE id = :rid AND COALESCE(status, '') NOT IN ('CANCELADO', 'PENDENTE')");
        $stmt->execute([':status' => $status, ':rid' => $restauranteId]);

        // Propaga o status da matriz para todas as filiais (is_matriz = 0).
        // Isso garante que, quando a matriz for bloqueada por vencimento ou reativada
        // por renovação, as filiais acompanhem o mesmo status — evitando filiais
        // presas em BLOQUEADO com a matriz ATIVA (ou vice-versa).
        try {
            if (function_exists('filiais_sincronizar_plano_status_matriz_para_filiais')) {
                filiais_sincronizar_plano_status_matriz_para_filiais($db, $restauranteId);
            }
        } catch (Throwable $e) {
            error_log('[PLANO_LIFECYCLE][SYNC_FILIAIS] ' . $e->getMessage());
        }
    }
}

if (!function_exists('plano_lifecycle_maybe_notify')) {
    function plano_lifecycle_maybe_notify(PDO $db, array $row, array $policy): void
    {
        $dias = (int)($policy['dias_restantes'] ?? 9999);
        if (!in_array($dias, [7, 3, 1], true)) {
            return;
        }

        plano_lifecycle_ensure_tables($db);

        $rid = (int)($row['id'] ?? 0);
        $dataFim = (string)($row['data_fim'] ?? '');
        $email = trim((string)($row['email'] ?? ''));
        if ($rid <= 0 || $dataFim === '' || $email === '') {
            return;
        }

        $check = $db->prepare("
            SELECT id FROM plano_lifecycle_notificacoes
            WHERE restaurante_id = :rid AND data_fim = :data_fim AND dias_restantes = :dias AND canal = 'email'
            LIMIT 1
        ");
        $check->execute([':rid' => $rid, ':data_fim' => $dataFim, ':dias' => $dias]);
        if ($check->fetchColumn()) {
            return;
        }

        $paymentUrl = plano_lifecycle_payment_url($rid);
        $sent = plano_notificar_vencimento_proximo(
            $email,
            (string)($row['telefone'] ?? ''),
            (string)($row['nome'] ?? 'Restaurante'),
            (string)($row['plano'] ?? 'BASICO'),
            $dataFim,
            $dias,
            $paymentUrl
        );

        plano_lifecycle_log($db, $rid, 'notificacao_renovacao', [
            'dias_restantes' => $dias,
            'email' => $email,
            'payment_url' => $paymentUrl,
            'sent' => $sent,
        ], $sent ? 'INFO' : 'WARN');

        if ($sent) {
            $ins = $db->prepare("
                INSERT INTO plano_lifecycle_notificacoes (restaurante_id, data_fim, dias_restantes, canal, destinatario)
                VALUES (:rid, :data_fim, :dias, 'email', :dest)
            ");
            $ins->execute([':rid' => $rid, ':data_fim' => $dataFim, ':dias' => $dias, ':dest' => $email]);
        }
    }
}

if (!function_exists('plano_lifecycle_evaluate_restaurante')) {
    function plano_lifecycle_evaluate_restaurante(PDO $db, int $restauranteId): ?array
    {
        plano_downgrade_processar_pendentes($db, $restauranteId);
        $row = plano_lifecycle_get_restaurante_snapshot($db, $restauranteId);
        if (!is_array($row)) {
            return null;
        }

        $policy = plano_lifecycle_compute_policy($row);
        plano_lifecycle_apply_status($db, $restauranteId, (string)$policy['estado']);
        plano_lifecycle_maybe_notify($db, $row, $policy);
        return $policy + ['restaurante_id' => $restauranteId, 'data_fim' => $row['data_fim'] ?? null];
    }
}

if (!function_exists('plano_lifecycle_can_access')) {
    function plano_lifecycle_can_access(string $limiteNivel, string $uri, bool $isApi, ?string $metodo = null): bool
    {
        $uri = strtolower($uri);
        $metodo = strtoupper($metodo ?? (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $safe = [
            '/logout.php',
            '/configuracoes.php',
            '/meus_dados.php',
            '/api/plano_',
            '/api/super_admin_compras_',
            '/api/online_ping.php',
            '/api/online_offline.php',
        ];
        foreach ($safe as $fragment) {
            if (str_contains($uri, $fragment)) {
                return true;
            }
        }

        if ($limiteNivel === 'NONE' || $limiteNivel === 'NOTICE_ONLY') {
            return true;
        }
        if ($limiteNivel === 'LIMITED_NON_CRITICAL') {
            $nonCriticalBlocked = ['/api/backup_', '/api/marketing', '/api/relatorio_'];
            foreach ($nonCriticalBlocked as $fragment) {
                if (str_contains($uri, $fragment)) {
                    return false;
                }
            }
            return true;
        }
        if ($limiteNivel === 'READ_ONLY') {
            // Consultar tudo; gravar so o turno (sem turno nao se entra nas paginas).
            if (in_array($metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
                return true;
            }
            foreach (['/iniciar_turno.php', '/finalizar_turno.php', '/api/turno_'] as $fragment) {
                if (str_contains($uri, $fragment)) {
                    return true;
                }
            }
            return false;
        }
        if ($limiteNivel === 'ADMIN_RESTRICTED') {
            if (str_contains($uri, '/admin') || str_contains($uri, '/usuarios.php') || str_contains($uri, '/filiais.php')) {
                return false;
            }
            if ($isApi && (str_contains($uri, '/api/usuario_') || str_contains($uri, '/api/restaurante_'))) {
                return false;
            }
            return true;
        }
        if ($limiteNivel === 'FULL_BLOCK') {
            return false;
        }
        return true;
    }
}

if (!function_exists('plano_lifecycle_enforce_for_session')) {
    function plano_lifecycle_enforce_for_session(bool $isApi): void
    {
        if ((int)($_SESSION['super_admin'] ?? 0) === 1) {
            return;
        }

        $ridContexto = (int)($_SESSION['restaurante_id'] ?? 0);
        if ($ridContexto <= 0) {
            return;
        }

        $ridPlano = $ridContexto;
        if (function_exists('session_restaurante_capability_id')) {
            $ridPlano = (int)session_restaurante_capability_id();
            if ($ridPlano <= 0) {
                $ridPlano = $ridContexto;
            }
        }

        try {
            // Reavaliar o plano (e gravar o estado) em todos os pedidos pesava muito com muitos ecras abertos.
            // Agora reavalia no maximo a cada 60 s por sessao; entre avaliacoes usa o estado guardado.
            $anterior = $_SESSION['plano_lifecycle'] ?? null;
            $recente = is_array($anterior)
                && (int)($anterior['restaurante_id'] ?? 0) === $ridPlano
                && (time() - (int)($anterior['_avaliado_em'] ?? 0)) < 60
                && PHP_SAPI !== 'cli';
            $db = null;
            if ($recente) {
                $policy = $anterior;
            } else {
                $db = Database::infra();
                if (!$db instanceof PDO) {
                    return;
                }
                $policy = plano_lifecycle_evaluate_restaurante($db, $ridPlano);
                if (!is_array($policy)) {
                    return;
                }
                $policy['_avaliado_em'] = time();
            }

            $_SESSION['plano_lifecycle'] = $policy;
            $_SESSION['plano_alerta'] = [
                'estado' => $policy['estado'],
                'dias_restantes' => $policy['dias_restantes'],
                'data_fim' => $policy['data_fim'] ?? null,
                'payment_url' => plano_lifecycle_payment_url($ridPlano),
                'restaurante_plano_id' => $ridPlano,
                'restaurante_contexto_id' => $ridContexto,
            ];

            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            if (plano_lifecycle_can_access((string)$policy['limite_nivel'], strtolower($uri), $isApi)) {
                return;
            }

            if (!$db instanceof PDO) {
                $db = Database::infra();
            }
            plano_lifecycle_log($db, $ridPlano, 'acesso_restrito_automatico', [
                'estado' => $policy['estado'],
                'limite_nivel' => $policy['limite_nivel'],
                'uri' => $uri,
                'restaurante_contexto_id' => $ridContexto,
            ], 'WARN');

            $msg = ($policy['limite_nivel'] ?? '') === 'READ_ONLY'
                ? 'O plano venceu: pode consultar e exportar os dados, mas nao registar vendas. Renove para voltar a vender.'
                : 'Acesso restrito por vencimento ou encerramento do plano. Pode exportar os seus dados em Meus dados.';
            if ($isApi) {
                security_json([
                    'success' => false,
                    'message' => $msg,
                    'code' => 'PLANO_RESTRITO',
                    'plano_lifecycle' => $policy,
                    'payment_url' => plano_lifecycle_payment_url($ridPlano),
                ], 402);
            }

            if (($policy['limite_nivel'] ?? '') === 'READ_ONLY' && !$isApi) {
                header('Location: configuracoes.php?secao=plano&erro=plano_modo_limitado');
                exit;
            }
            header('Location: ' . (($policy['estado'] ?? '') === 'CANCELADO' ? 'meus_dados.php' : 'configuracoes.php?secao=plano&erro=plano_restrito'));
            exit;
        } catch (Throwable $e) {
            security_log_event('plano_lifecycle_enforce_error', ['error' => $e->getMessage()]);
        }
    }
}
