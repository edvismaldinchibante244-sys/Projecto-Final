<?php

/*
  Modelo de Autenticação
  Gerencia login e verificação de usuários*/
 

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/login_security.php';
require_once __DIR__ . '/../config/email_helper.php';

class Auth
{
    private const MAX_TENTATIVAS_LOGIN = 5;
    private const BLOQUEIO_MINUTOS = 10;
    private const MENSAGEM_LOGIN_INVALIDO = 'Email ou senha inválidos';

    private $db;
    private static $dummyPasswordHash;

    private function isLocalRequest(): bool
    {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_starts_with($host, 'localhost:')
            || str_starts_with($host, '127.0.0.1:')
            || str_contains($host, '[::1]');
    }

    private function passwordAlgorithm()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public function __construct($db = null)
    {
        if ($db instanceof PDO) {
            $this->db = $db;
            return;
        }

        $database = new Database();
        $this->db = $database->getConnection();
    }

    /**
     * Validar credenciais do admin do restaurante
     */
    public function login($email, $senha)
    {
        $email = trim((string)$email);
        $senha = (string)$senha;
        $this->garantirSchemaSegurancaLogin();

        $usuario = null;
        $senhaOk = false;
        $novaSenhaHash = null;
        $iniciouTransacao = false;

        try {
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $iniciouTransacao = true;
            }

            $query = "
                SELECT
                    u.*,
                    r.nome AS restaurante_nome,
                    r.plano,
                    r.status AS restaurante_status,
                    CASE
                        WHEN u.bloqueado_ate IS NOT NULL AND u.bloqueado_ate > NOW() THEN 1
                        ELSE 0
                    END AS login_bloqueado,
                    CASE
                        WHEN u.bloqueado_ate IS NOT NULL AND u.bloqueado_ate <= NOW() THEN 1
                        ELSE 0
                    END AS bloqueio_expirado
                FROM usuarios u
                LEFT JOIN restaurantes r ON u.restaurante_id = r.id
                WHERE u.email_lookup = :email_lookup
                LIMIT 1
                FOR UPDATE
            ";

            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':email_lookup', personal_data_email_lookup($email), PDO::PARAM_STR);
            $stmt->execute();

            $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$usuario) {
                if ($iniciouTransacao) {
                    $this->db->commit();
                }
                $this->executarVerificacaoDummy($senha);
                return $this->respostaFalhaLogin('invalid_credentials');
            }

            if ((int)($usuario['bloqueio_expirado'] ?? 0) === 1) {
                $this->resetarTentativasLogin((int)$usuario['id']);
                $usuario['tentativas_login'] = 0;
                $usuario['bloqueado_ate'] = null;
                $usuario['login_bloqueado'] = 0;
            }

            if ((int)($usuario['login_bloqueado'] ?? 0) === 1) {
                if ($iniciouTransacao) {
                    $this->db->commit();
                }
                $this->executarVerificacaoDummy($senha);
                return $this->respostaFalhaLogin('account_blocked', [
                    'blocked_until' => $usuario['bloqueado_ate'] ?? null,
                    'email' => $email,
                ]);
            }

            $senhaBanco = (string)($usuario['senha'] ?? '');

            if ($senhaBanco !== '' && password_verify($senha, $senhaBanco)) {
                $senhaOk = true;

                if (password_needs_rehash($senhaBanco, $this->passwordAlgorithm())) {
                    $novaSenhaHash = password_hash($senha, $this->passwordAlgorithm());
                }
            }

            if ($senhaOk) {
                $this->resetarTentativasLogin((int)$usuario['id'], $novaSenhaHash);
            } else {
                $this->registrarFalhaLogin((int)$usuario['id'], (int)($usuario['tentativas_login'] ?? 0), $email, $_SERVER['REMOTE_ADDR'] ?? null);
            }

            if ($iniciouTransacao) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($iniciouTransacao && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($usuario && $senhaOk) {
            $ehSuperAdmin = intval($usuario['super_admin'] ?? 0) === 1;

            // Verificar status do restaurante
            if (!$ehSuperAdmin && ($usuario['restaurante_status'] ?? '') !== 'ATIVO'
                && !$this->podeEntrarSoParaExportar($usuario)) {
                return $this->respostaFalhaLogin('restaurant_inactive');
            }

            if (!$ehSuperAdmin && isset($usuario['ativo']) && intval($usuario['ativo']) === 0) {
                return $this->respostaFalhaLogin('user_inactive');
            }

            $perfil = strtoupper(trim((string)($usuario['perfil'] ?? 'USER')));
            if ($perfil === 'GARÇOM') {
                $perfil = 'GARCOM';
            }

            return [
                'success' => true,
                'data' => [
                    'id' => $usuario['id'],
                    'nome' => $usuario['nome'],
                    'email' => $usuario['email'],
                    'perfil' => $perfil,
                    'restaurante_id' => $usuario['restaurante_id'],
                    'restaurante_nome' => $usuario['restaurante_nome'],
                    'plano' => $usuario['plano'],
                    'foto' => $usuario['foto'] ?? '',
                    'super_admin' => intval($usuario['super_admin'] ?? 0),
                    'two_factor_enabled' => intval($usuario['two_factor_enabled'] ?? 0),
                    'two_factor_email' => $usuario['two_factor_email'] ?: ($usuario['email'] ?? ''),
                ],
                // Compatibilidade com consumidores antigos
                'usuario' => [
                    'id' => $usuario['id'],
                    'nome' => $usuario['nome'],
                    'email' => $usuario['email'],
                    'perfil' => $perfil,
                    'restaurante_id' => $usuario['restaurante_id'],
                    'restaurante_nome' => $usuario['restaurante_nome'],
                    'plano' => $usuario['plano'],
                    'foto' => $usuario['foto'] ?? '',
                    'super_admin' => intval($usuario['super_admin'] ?? 0),
                    'two_factor_enabled' => intval($usuario['two_factor_enabled'] ?? 0),
                    'two_factor_email' => $usuario['two_factor_email'] ?: ($usuario['email'] ?? ''),
                ]
            ];
        }

        return $this->respostaFalhaLogin('invalid_credentials', [
            'email' => $email,
            'user_found' => $usuario ? 1 : 0,
        ]);
    }

    /**
     * Verificar se usuário está logado
     */
    public static function check()
    {
        return isset($_SESSION['usuario_id']) && isset($_SESSION['restaurante_id']);
    }

    /**
     * Verificar permissão de plano
     */
    public static function hasPlano($plano_required)
    {
        if (!self::check()) {
            return false;
        }

        $planos = [
            'BASICO' => 1,
            'PROFISSIONAL' => 2,
            'EMPRESARIAL' => 3
        ];

        $plano_atual = strtoupper($_SESSION['plano'] ?? 'BASICO');
        $plano_req = strtoupper($plano_required);

        return ($planos[$plano_atual] ?? 1) >= ($planos[$plano_req] ?? 1);
    }

    /**
     * Criar primeiro admin (usado na instalação)
     */
    public function criarPrimeiroAdmin($restaurante_id, $nome, $email, $senha)
    {
        $senha_hash = password_hash($senha, $this->passwordAlgorithm());

        $query = "INSERT INTO usuarios (restaurante_id, nome, email, email_lookup, senha, perfil, status) 
                  VALUES (:rid, :nome, :email, :email_lookup, :senha, 'ADMIN', 'ATIVO')";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':rid', $restaurante_id);
        $stmt->bindParam(':nome', $nome);
        $emailEncrypted = personal_data_encrypt($email);
        $emailLookup = personal_data_email_lookup($email);
        $stmt->bindParam(':email', $emailEncrypted);
        $stmt->bindParam(':email_lookup', $emailLookup);
        $stmt->bindParam(':senha', $senha_hash);

        return $stmt->execute();
    }

    /**
     * Atualizar senha do usuário
     */
    public function atualizarSenha($usuario_id, $nova_senha)
    {
        try {
            $this->garantirSchemaSegurancaLogin();
            $senha_hash = password_hash($nova_senha, $this->passwordAlgorithm());

            $query = "UPDATE usuarios SET senha = :senha, tentativas_login = 0, bloqueado_ate = NULL WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':senha', $senha_hash);
            $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (Exception $e) {
            return false;
        }
    }

    private function garantirSchemaSegurancaLogin(): void
    {
        if (!login_security_garantir_schema($this->db)) {
            throw new RuntimeException('Nao foi possivel garantir as colunas de seguranca do login.');
        }
    }

    private function resetarTentativasLogin(int $usuarioId, ?string $novaSenhaHash = null): void
    {
        if ($novaSenhaHash !== null) {
            $stmt = $this->db->prepare("
                UPDATE usuarios
                SET senha = :senha,
                    tentativas_login = 0,
                    bloqueado_ate = NULL
                WHERE id = :id
            ");
            $stmt->bindValue(':senha', $novaSenhaHash, PDO::PARAM_STR);
        } else {
            $stmt = $this->db->prepare("
                UPDATE usuarios
                SET tentativas_login = 0,
                    bloqueado_ate = NULL
                WHERE id = :id
            ");
        }

        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function registrarFalhaLogin(int $usuarioId, int $tentativasAtuais, string $email = '', ?string $ip = null): void
    {
        $novasTentativas = max(0, $tentativasAtuais) + 1;
        $ip = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bloqueou = false;
        if ($novasTentativas >= self::MAX_TENTATIVAS_LOGIN) {
            $sql = sprintf(
                "
                    UPDATE usuarios
                    SET tentativas_login = :tentativas,
                        bloqueado_ate = DATE_ADD(NOW(), INTERVAL %d MINUTE)
                    WHERE id = :id
                ",
                self::BLOQUEIO_MINUTOS
            );
            $bloqueou = true;
        } else {
            $sql = "
                UPDATE usuarios
                SET tentativas_login = :tentativas,
                    bloqueado_ate = NULL
                WHERE id = :id
            ";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tentativas', $novasTentativas, PDO::PARAM_INT);
        $stmt->bindValue(':id', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();

        // Log de segurança
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/login_security.log';
        $timestamp = date('Y-m-d H:i:s');
        $status = $novasTentativas >= self::MAX_TENTATIVAS_LOGIN ? 'BLOQUEADO' : 'FALHA';
        $linha = sprintf(
            "[%s] %s | usuario_id=%d email=%s ip=%s tentativas=%d\n",
            $timestamp,
            $status,
            $usuarioId,
            $email !== '' ? $email : '-',
            $ip,
            $novasTentativas
        );
        @file_put_contents($logFile, $linha, FILE_APPEND);

        // Alerta por e-mail ao bloquear
        if ($bloqueou) {
            $to = getenv('SUPER_ADMIN_EMAIL') ?: getenv('SECURITY_ALERT_EMAIL') ?: (getenv('SMTP_FROM') ?: '');
            if ($to !== '') {
                $assunto = 'Alerta: usuário bloqueado por tentativas de login';
                $mensagem = sprintf(
                    "<p>Um usuário foi temporariamente bloqueado por excesso de tentativas de login.</p>
                     <ul>
                       <li>Usuário ID: %d</li>
                       <li>Email: %s</li>
                       <li>IP: %s</li>
                       <li>Tentativas: %d</li>
                       <li>Data/Hora: %s</li>
                       <li>Bloqueio (min): %d</li>
                     </ul>",
                    $usuarioId,
                    $email !== '' ? htmlspecialchars($email) : '-',
                    htmlspecialchars($ip),
                    $novasTentativas,
                    htmlspecialchars($timestamp),
                    self::BLOQUEIO_MINUTOS
                );
                @saas_enviar_email($to, $assunto, $mensagem);
            }
        }
    }

    private function executarVerificacaoDummy(string $senha): void
    {
        if (!is_string(self::$dummyPasswordHash) || self::$dummyPasswordHash === '') {
            self::$dummyPasswordHash = password_hash('restaurante-saas-login-placeholder', $this->passwordAlgorithm());
        }

        password_verify($senha, self::$dummyPasswordHash);
    }

    /**
     * Termos, seccoes 4 e 5: o administrador de um restaurante cancelado, ou com o plano
     * vencido ha mais de 7 dias, pode entrar so para exportar os dados (o resto fica
     * bloqueado em plano_lifecycle_enforce_for_session). Um bloqueio manual do Super Admin
     * com plano em dia continua a impedir a entrada.
     */
    private function podeEntrarSoParaExportar(array $usuario): bool
    {
        $perfil = strtoupper(trim((string)($usuario['perfil'] ?? '')));
        $status = strtoupper((string)($usuario['restaurante_status'] ?? ''));
        if ($perfil !== 'ADMIN' || !in_array($status, ['CANCELADO', 'BLOQUEADO'], true)) {
            return false;
        }
        if ($status === 'CANCELADO') {
            return true;
        }
        try {
            if (!function_exists('plano_lifecycle_get_restaurante_snapshot')) {
                require_once __DIR__ . '/../config/plano_lifecycle.php';
            }
            $row = plano_lifecycle_get_restaurante_snapshot($this->db, (int)($usuario['restaurante_id'] ?? 0));
            if (!is_array($row)) {
                return false;
            }
            $policy = plano_lifecycle_compute_policy($row);
            return ($policy['estado'] ?? '') === 'BLOQUEADO_INADIMPLENTE';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function respostaFalhaLogin(string $reason = 'invalid_credentials', array $meta = []): array
    {
        $response = [
            'success' => false,
            'message' => self::MENSAGEM_LOGIN_INVALIDO,
            'reason' => $reason,
        ];

        if ($reason === 'account_blocked') {
            $response['message'] = 'Conta temporariamente bloqueada por excesso de tentativas.';
        } elseif ($reason === 'restaurant_inactive') {
            $response['message'] = 'Acesso do restaurante indisponivel.';
        } elseif ($reason === 'user_inactive') {
            $response['message'] = 'Usuario inativo.';
        } elseif ($reason === 'invalid_credentials' && $this->isLocalRequest() && !empty($meta['user_found'])) {
            $response['message'] = 'Email encontrado, mas a senha esta incorreta.';
        }

        if (!empty($meta['blocked_until'])) {
            $response['blocked_until'] = $meta['blocked_until'];
        }

        return $response;
    }
}
