<?php
/**
 * Bónus de boas-vindas no primeiro pagamento.
 *
 * Regras (decididas com o cliente):
 *  - Paga Básico       -> usa Profissional durante BONUS_DIAS dias.
 *  - Paga Profissional -> usa Empresarial  durante BONUS_DIAS dias.
 *  - Paga Empresarial  -> ganha BONUS_DIAS dias extra de plano (a validade estende-se).
 *  - Uma vez por restaurante e uma vez por dono, para sempre.
 *  - A contagem só começa quando o restaurante começa a trabalhar: na primeira
 *    venda registada ou ao fim de BONUS_ESPERA_DIAS dias, o que vier primeiro.
 *  - O plano efectivo é decidido pelas datas sempre que é lido, e não por uma
 *    tarefa agendada: se nada correr, o bónus acaba na mesma à hora certa.
 *  - No fim nada é apagado: o restaurante volta ao plano que pagou e o que
 *    criou a mais continua a existir (só não pode criar mais).
 */

if (!defined('BONUS_DIAS')) {
    define('BONUS_DIAS', 14);
}
if (!defined('BONUS_ESPERA_DIAS')) {
    // Dias à espera da primeira venda antes de a oferta arrancar sozinha.
    define('BONUS_ESPERA_DIAS', 3);
}

if (!function_exists('bonus_plano_acima')) {
    /**
     * O plano imediatamente acima, ou null quando já é o topo.
     */
    function bonus_plano_acima(string $plano): ?string
    {
        $escada = ['BASICO' => 'PROFISSIONAL', 'PROFISSIONAL' => 'EMPRESARIAL'];
        return $escada[strtoupper(trim($plano))] ?? null;
    }
}

if (!function_exists('bonus_tabela_existe')) {
    function bonus_tabela_existe(PDO $db): bool
    {
        static $existe = null;
        if ($existe !== null) {
            return $existe;
        }
        try {
            $st = $db->query("SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plano_bonus'");
            $existe = ((int)$st->fetchColumn() > 0);
        } catch (Throwable $e) {
            $existe = false;
        }
        return $existe;
    }
}

if (!function_exists('bonus_dono_chave')) {
    /**
     * Chave do dono: NUIT em hash quando existe, senão o email em hash.
     * Serve só para impedir a mesma pessoa de repetir a oferta.
     */
    function bonus_dono_chave(PDO $db, int $restauranteId): ?string
    {
        try {
            $st = $db->prepare("SELECT nuit_lookup, email_lookup FROM restaurantes WHERE id = :id");
            $st->execute([':id' => $restauranteId]);
            $linha = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return null;
        }
        $nuit = trim((string)($linha['nuit_lookup'] ?? ''));
        if ($nuit !== '') {
            return $nuit;
        }
        $email = trim((string)($linha['email_lookup'] ?? ''));
        return $email !== '' ? $email : null;
    }
}

if (!function_exists('bonus_ja_usado')) {
    /**
     * Este restaurante, ou o seu dono, já recebeu a oferta alguma vez?
     */
    function bonus_ja_usado(PDO $db, int $restauranteId, ?string $donoChave): bool
    {
        if (!bonus_tabela_existe($db)) {
            return true;
        }
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM plano_bonus WHERE restaurante_id = :rid");
            $st->execute([':rid' => $restauranteId]);
            if ((int)$st->fetchColumn() > 0) {
                return true;
            }
            if ($donoChave !== null && $donoChave !== '') {
                $st2 = $db->prepare("SELECT COUNT(*) FROM plano_bonus WHERE dono_chave = :dono");
                $st2->execute([':dono' => $donoChave]);
                if ((int)$st2->fetchColumn() > 0) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return true;
        }
        return false;
    }
}

if (!function_exists('bonus_conceder')) {
    /**
     * Regista a oferta no primeiro pagamento. Devolve o que foi concedido,
     * ou null quando não há oferta a dar (já usada, plano inválido, trial).
     *
     * O bónus fica PENDENTE: só arranca quando o restaurante começa a vender.
     * Para quem paga Empresarial a oferta é imediata (dias extra de plano) e
     * quem a aplica à data de fim é quem chama, pelo valor 'dias_extra'.
     */
    function bonus_conceder(PDO $db, int $restauranteId, string $planoPago, ?int $compraId = null, bool $ehTrial = false): ?array
    {
        if ($restauranteId <= 0 || $ehTrial || !bonus_tabela_existe($db)) {
            return null;
        }
        $planoPago = strtoupper(trim($planoPago));
        if (!in_array($planoPago, ['BASICO', 'PROFISSIONAL', 'EMPRESARIAL'], true)) {
            return null;
        }

        $donoChave = bonus_dono_chave($db, $restauranteId);
        if (bonus_ja_usado($db, $restauranteId, $donoChave)) {
            return null;
        }

        $planoAcima = bonus_plano_acima($planoPago);
        $tipo = $planoAcima === null ? 'DIAS_EXTRA' : 'UPGRADE';
        $planoBonus = $planoAcima ?? $planoPago;

        try {
            $st = $db->prepare("INSERT INTO plano_bonus
                (restaurante_id, dono_chave, plano_pago, plano_bonus, tipo, dias, estado, compra_id, inicio_em, fim_em, terminado_em, observacoes)
                VALUES (:rid, :dono, :pago, :bonus, :tipo, :dias, :estado, :compra, NULL, NULL, :terminado, :obs)");
            $st->execute([
                ':rid' => $restauranteId,
                ':dono' => $donoChave,
                ':pago' => $planoPago,
                ':bonus' => $planoBonus,
                ':tipo' => $tipo,
                ':dias' => BONUS_DIAS,
                // Os dias extra do Empresarial aplicam-se de uma vez: não há nada a correr depois.
                ':estado' => $tipo === 'DIAS_EXTRA' ? 'TERMINADO' : 'PENDENTE',
                ':compra' => $compraId,
                ':terminado' => $tipo === 'DIAS_EXTRA' ? date('Y-m-d H:i:s') : null,
                ':obs' => $tipo === 'DIAS_EXTRA'
                    ? ('Oferta de boas-vindas: ' . BONUS_DIAS . ' dias extra de plano.')
                    : ('Oferta de boas-vindas: ' . BONUS_DIAS . ' dias no plano ' . $planoBonus . '.'),
            ]);
        } catch (Throwable $e) {
            error_log('[bonus] conceder: ' . $e->getMessage());
            return null;
        }

        return [
            'tipo' => $tipo,
            'plano_pago' => $planoPago,
            'plano_bonus' => $planoBonus,
            'dias' => BONUS_DIAS,
            'dias_extra' => $tipo === 'DIAS_EXTRA' ? BONUS_DIAS : 0,
        ];
    }
}

if (!function_exists('bonus_tem_venda')) {
    /**
     * O restaurante já registou alguma venda ou pedido desde que a oferta foi dada?
     */
    function bonus_tem_venda(PDO $db, int $restauranteId, string $desde): bool
    {
        try {
            $st = $db->prepare("SELECT 1 FROM vendas WHERE restaurante_id = :rid AND criado_em >= :desde LIMIT 1");
            $st->execute([':rid' => $restauranteId, ':desde' => $desde]);
            if ($st->fetchColumn()) {
                return true;
            }
            $st2 = $db->prepare("SELECT 1 FROM pedidos WHERE restaurante_id = :rid AND criado_em >= :desde LIMIT 1");
            $st2->execute([':rid' => $restauranteId, ':desde' => $desde]);
            return (bool)$st2->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bonus_estado')) {
    /**
     * Estado da oferta deste restaurante, já com o arranque e o fim tratados
     * pelas datas. Devolve null quando não há oferta a influenciar o plano.
     *
     * Não chama plano_get_dados: é chamada de dentro dele.
     *
     * @return array|null ['ativo'=>bool,'plano_bonus'=>string,'fim_em'=>string,'dias_restantes'=>int,...]
     */
    function bonus_estado(PDO $db, int $restauranteId): ?array
    {
        if ($restauranteId <= 0 || !bonus_tabela_existe($db)) {
            return null;
        }
        try {
            $st = $db->prepare("SELECT * FROM plano_bonus WHERE restaurante_id = :rid LIMIT 1");
            $st->execute([':rid' => $restauranteId]);
            $linha = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!$linha) {
            return null;
        }

        $estado = (string)$linha['estado'];
        $hoje = new DateTimeImmutable('today');

        // PENDENTE: arranca na primeira venda ou ao fim da espera.
        if ($estado === 'PENDENTE') {
            $criado = (string)$linha['criado_em'];
            $limiteEspera = (new DateTimeImmutable($criado))->modify('+' . BONUS_ESPERA_DIAS . ' days');
            $arranca = bonus_tem_venda($db, $restauranteId, $criado) || $hoje >= $limiteEspera->setTime(0, 0);
            if (!$arranca) {
                return [
                    'ativo' => false,
                    'estado' => 'PENDENTE',
                    'plano_pago' => (string)$linha['plano_pago'],
                    'plano_bonus' => (string)$linha['plano_bonus'],
                    'dias' => (int)$linha['dias'],
                    'fim_em' => null,
                    'dias_restantes' => (int)$linha['dias'],
                ];
            }
            $fim = $hoje->modify('+' . (int)$linha['dias'] . ' days');
            try {
                $up = $db->prepare("UPDATE plano_bonus SET estado = 'ATIVO', inicio_em = :ini, fim_em = :fim
                    WHERE id = :id AND estado = 'PENDENTE'");
                $up->execute([
                    ':ini' => $hoje->format('Y-m-d'),
                    ':fim' => $fim->format('Y-m-d'),
                    ':id' => (int)$linha['id'],
                ]);
            } catch (Throwable $e) {
                error_log('[bonus] arranque: ' . $e->getMessage());
                return null;
            }
            $linha['estado'] = $estado = 'ATIVO';
            $linha['inicio_em'] = $hoje->format('Y-m-d');
            $linha['fim_em'] = $fim->format('Y-m-d');
        }

        if ($estado !== 'ATIVO') {
            return [
                'ativo' => false,
                'estado' => $estado,
                'inicio_em' => (string)($linha['inicio_em'] ?? ''),
                'plano_pago' => (string)$linha['plano_pago'],
                'plano_bonus' => (string)$linha['plano_bonus'],
                'tipo' => (string)$linha['tipo'],
                'fim_em' => $linha['fim_em'] ?? null,
                'dias_restantes' => 0,
                'resumo_visto_em' => $linha['resumo_visto_em'] ?? null,
                'aviso_fim_em' => $linha['aviso_fim_em'] ?? null,
            ];
        }

        $fimEm = (string)($linha['fim_em'] ?? '');
        $fim = $fimEm !== '' ? new DateTimeImmutable($fimEm) : $hoje;

        // Acabou: fecha pelo calendário, sem depender de tarefa agendada.
        if ($hoje > $fim) {
            try {
                $db->prepare("UPDATE plano_bonus SET estado = 'TERMINADO', terminado_em = NOW()
                    WHERE id = :id AND estado = 'ATIVO'")->execute([':id' => (int)$linha['id']]);
            } catch (Throwable $e) {
                error_log('[bonus] fecho: ' . $e->getMessage());
            }
            return [
                'ativo' => false,
                'estado' => 'TERMINADO',
                'inicio_em' => (string)($linha['inicio_em'] ?? ''),
                'plano_pago' => (string)$linha['plano_pago'],
                'plano_bonus' => (string)$linha['plano_bonus'],
                'tipo' => (string)$linha['tipo'],
                'fim_em' => $fimEm,
                'dias_restantes' => 0,
                'resumo_visto_em' => $linha['resumo_visto_em'] ?? null,
                'aviso_fim_em' => $linha['aviso_fim_em'] ?? null,
            ];
        }

        return [
            'ativo' => true,
            'estado' => 'ATIVO',
            'plano_pago' => (string)$linha['plano_pago'],
            'plano_bonus' => (string)$linha['plano_bonus'],
            'tipo' => (string)$linha['tipo'],
            'inicio_em' => (string)($linha['inicio_em'] ?? ''),
            'fim_em' => $fimEm,
            'dias_restantes' => (int)$hoje->diff($fim)->format('%a'),
            'aviso7_em' => $linha['aviso7_em'] ?? null,
            'aviso12_em' => $linha['aviso12_em'] ?? null,
        ];
    }
}

if (!function_exists('bonus_marcar_aviso')) {
    function bonus_marcar_aviso(PDO $db, int $restauranteId, string $coluna): void
    {
        if (!in_array($coluna, ['aviso7_em', 'aviso12_em', 'aviso_fim_em', 'resumo_visto_em'], true)) {
            return;
        }
        try {
            $db->prepare("UPDATE plano_bonus SET {$coluna} = NOW() WHERE restaurante_id = :rid")
               ->execute([':rid' => $restauranteId]);
        } catch (Throwable $e) {
            error_log('[bonus] aviso: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bonus_resumo_uso')) {
    /**
     * O que o restaurante fez com o plano da oferta, para lhe mostrar no fim
     * o que perde se não ficar. Só conta o que é exclusivo do plano de cima.
     *
     * @return array lista de ['rotulo' => string, 'valor' => int]
     */
    function bonus_resumo_uso(PDO $db, int $restauranteId, array $bonus): array
    {
        $inicio = (string)($bonus['inicio_em'] ?? '');
        $fim = (string)($bonus['fim_em'] ?? '');
        if ($inicio === '') {
            return [];
        }
        $fimSql = $fim !== '' ? $fim : date('Y-m-d');
        $planoBonus = strtoupper((string)($bonus['plano_bonus'] ?? ''));

        $contar = static function (string $sql) use ($db, $restauranteId, $inicio, $fimSql): int {
            try {
                $st = $db->prepare($sql);
                $st->execute([':rid' => $restauranteId, ':ini' => $inicio, ':fim' => $fimSql]);
                return (int)$st->fetchColumn();
            } catch (Throwable $e) {
                return 0;
            }
        };

        $itens = [];

        if ($planoBonus === 'PROFISSIONAL') {
            $fichas = $contar("SELECT COUNT(*) FROM estoque_fichas_tecnicas
                WHERE restaurante_id = :rid AND DATE(criado_em) BETWEEN :ini AND :fim");
            if ($fichas > 0) {
                $itens[] = ['rotulo' => $fichas === 1 ? 'ficha técnica criada' : 'fichas técnicas criadas', 'valor' => $fichas];
            }
            $promos = $contar("SELECT COUNT(*) FROM promocoes
                WHERE restaurante_id = :rid AND DATE(criado_em) BETWEEN :ini AND :fim");
            if ($promos > 0) {
                $itens[] = ['rotulo' => $promos === 1 ? 'promoção criada' : 'promoções criadas', 'valor' => $promos];
            }
        }

        if ($planoBonus === 'EMPRESARIAL') {
            $filiais = $contar("SELECT COUNT(*) FROM restaurantes
                WHERE filial_id = :rid AND DATE(criado_em) BETWEEN :ini AND :fim");
            if ($filiais > 0) {
                $itens[] = ['rotulo' => $filiais === 1 ? 'unidade da rede criada' : 'unidades da rede criadas', 'valor' => $filiais];
            }
            $transf = $contar("SELECT COUNT(*) FROM estoque_movimentos
                WHERE restaurante_id = :rid AND UPPER(origem) = 'TRANSFERENCIA'
                  AND DATE(criado_em) BETWEEN :ini AND :fim");
            if ($transf > 0) {
                $itens[] = ['rotulo' => $transf === 1 ? 'transferência de stock' : 'transferências de stock', 'valor' => $transf];
            }
        }

        // Comum aos dois: o movimento do restaurante durante a oferta.
        $pedidos = $contar("SELECT COUNT(*) FROM pedidos
            WHERE restaurante_id = :rid AND DATE(criado_em) BETWEEN :ini AND :fim");
        if ($pedidos > 0) {
            $itens[] = ['rotulo' => $pedidos === 1 ? 'pedido registado' : 'pedidos registados', 'valor' => $pedidos];
        }

        return $itens;
    }
}

if (!function_exists('bonus_acima_do_limite')) {
    /**
     * Depois da oferta, o que ficou acima do limite do plano pago.
     * Nada é apagado: isto serve só para explicar porque não dá para criar mais.
     *
     * @return array lista de ['recurso','rotulo','atual','limite']
     */
    function bonus_acima_do_limite(PDO $db, int $restauranteId, string $planoPago): array
    {
        $catalogo = require __DIR__ . '/planos.php';
        $limites = $catalogo[strtoupper($planoPago)]['limites'] ?? [];
        if (!is_array($limites) || $limites === []) {
            return [];
        }

        $consultas = [
            'produtos' => ['Produtos activos', "SELECT COUNT(*) FROM produtos WHERE restaurante_id = :rid AND ativo = 1"],
            'usuarios' => ['Utilizadores activos', "SELECT COUNT(*) FROM usuarios WHERE restaurante_id = :rid AND ativo = 1"],
            'mesas' => ['Mesas', "SELECT COUNT(*) FROM mesas WHERE restaurante_id = :rid"],
        ];

        $acima = [];
        foreach ($consultas as $recurso => $par) {
            $limite = (int)($limites[$recurso] ?? -1);
            if ($limite < 0) {
                continue;
            }
            try {
                $st = $db->prepare($par[1]);
                $st->execute([':rid' => $restauranteId]);
                $atual = (int)$st->fetchColumn();
            } catch (Throwable $e) {
                continue;
            }
            if ($atual > $limite) {
                $acima[] = [
                    'recurso' => $recurso,
                    'rotulo' => $par[0],
                    'atual' => $atual,
                    'limite' => $limite,
                ];
            }
        }
        return $acima;
    }
}

if (!function_exists('bonus_destinatario')) {
    /**
     * Email e telefone para avisar: o do administrador, senão o do restaurante.
     */
    function bonus_destinatario(PDO $db, int $restauranteId): array
    {
        $saida = ['nome' => 'Restaurante', 'email' => '', 'telefone' => ''];
        try {
            $st = $db->prepare("
                SELECT r.nome, r.email, r.telefone,
                       (SELECT u.email FROM usuarios u
                         WHERE u.restaurante_id = r.id AND UPPER(u.perfil) = 'ADMIN'
                           AND u.email IS NOT NULL AND u.email <> ''
                         ORDER BY u.id ASC LIMIT 1) AS admin_email
                FROM restaurantes r WHERE r.id = :rid LIMIT 1");
            $st->execute([':rid' => $restauranteId]);
            $linha = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return $saida;
        }

        $descodificar = static function ($valor, string $campo) {
            if (function_exists('personal_data_decrypt_result')) {
                return (string)personal_data_decrypt_result($valor, $campo);
            }
            return (string)$valor;
        };

        $saida['nome'] = trim($descodificar($linha['nome'] ?? '', 'nome')) ?: 'Restaurante';
        $email = trim($descodificar($linha['admin_email'] ?? '', 'email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = trim($descodificar($linha['email'] ?? '', 'email'));
        }
        $saida['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $saida['telefone'] = trim($descodificar($linha['telefone'] ?? '', 'telefone'));
        return $saida;
    }
}

if (!function_exists('bonus_avisar')) {
    /**
     * Envia o aviso (se houver email configurado) e marca-o como dado.
     * O aviso fica marcado mesmo que o email falhe: o sistema nunca fica
     * a insistir, e a faixa dentro do sistema já mostra o mesmo.
     */
    function bonus_avisar(PDO $db, int $restauranteId, string $coluna, array $bonus): void
    {
        $nomes = ['BASICO' => 'Básico', 'PROFISSIONAL' => 'Profissional', 'EMPRESARIAL' => 'Empresarial'];
        $planoBonus = strtoupper((string)($bonus['plano_bonus'] ?? ''));
        $planoPago = strtoupper((string)($bonus['plano_pago'] ?? ''));
        $destino = bonus_destinatario($db, $restauranteId);

        if ($destino['email'] !== '') {
            try {
                require_once __DIR__ . '/plano_notificacoes.php';
                $url = rtrim((string)(getenv('APP_URL') ?: ''), '/');
                $url = $url !== '' ? $url . '/solicitar_plano.php?plano=' . urlencode($planoBonus) : null;

                if ($coluna === 'aviso_fim_em') {
                    plano_notificar_bonus_terminado(
                        $destino['email'],
                        $destino['telefone'],
                        $destino['nome'],
                        $nomes[$planoBonus] ?? $planoBonus,
                        $nomes[$planoPago] ?? $planoPago,
                        bonus_resumo_uso($db, $restauranteId, $bonus),
                        $url
                    );
                } else {
                    plano_notificar_bonus_lembrete(
                        $destino['email'],
                        $destino['telefone'],
                        $destino['nome'],
                        $nomes[$planoBonus] ?? $planoBonus,
                        $nomes[$planoPago] ?? $planoPago,
                        (int)($bonus['dias_restantes'] ?? 0),
                        $url,
                        // A mesma lista que o email do fim ja levava. Dita
                        // agora, enquanto o cliente ainda pode decidir pagar.
                        bonus_resumo_uso($db, $restauranteId, $bonus)
                    );
                }
            } catch (Throwable $e) {
                error_log('[bonus] aviso por email: ' . $e->getMessage());
            }
        }

        bonus_marcar_aviso($db, $restauranteId, $coluna);
    }
}

if (!function_exists('bonus_processar_avisos')) {
    /**
     * Percorre as ofertas e trata os avisos em atraso: serve a tarefa agendada
     * (para o aviso sair mesmo sem ninguém abrir o sistema) e é inofensiva se
     * correr duas vezes, porque cada aviso só é dado uma vez.
     *
     * @return array contagem do que foi feito
     */
    function bonus_processar_avisos(PDO $db, bool $simular = false): array
    {
        $resumo = ['aviso7' => 0, 'aviso12' => 0, 'terminados' => 0, 'restaurantes' => []];
        if (!bonus_tabela_existe($db)) {
            return $resumo;
        }
        try {
            $st = $db->query("SELECT restaurante_id FROM plano_bonus
                WHERE tipo = 'UPGRADE' AND estado IN ('PENDENTE','ATIVO','TERMINADO')
                  AND (aviso7_em IS NULL OR aviso12_em IS NULL OR aviso_fim_em IS NULL)");
            $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return $resumo;
        }

        foreach ($ids as $rid) {
            $rid = (int)$rid;
            $estado = bonus_estado($db, $rid);
            if (!is_array($estado)) {
                continue;
            }

            if (!empty($estado['ativo'])) {
                $dias = (int)($estado['dias_restantes'] ?? 0);
                if ($dias <= (BONUS_DIAS - 12) && empty($estado['aviso12_em'])) {
                    if (!$simular) { bonus_avisar($db, $rid, 'aviso12_em', $estado); }
                    $resumo['aviso12']++;
                    $resumo['restaurantes'][] = ['restaurante_id' => $rid, 'aviso' => 'aviso12', 'dias_restantes' => $dias];
                } elseif ($dias <= (BONUS_DIAS - 7) && empty($estado['aviso7_em'])) {
                    if (!$simular) { bonus_avisar($db, $rid, 'aviso7_em', $estado); }
                    $resumo['aviso7']++;
                    $resumo['restaurantes'][] = ['restaurante_id' => $rid, 'aviso' => 'aviso7', 'dias_restantes' => $dias];
                }
                continue;
            }

            if (($estado['estado'] ?? '') === 'TERMINADO' && empty($estado['aviso_fim_em'])) {
                if (!$simular) { bonus_avisar($db, $rid, 'aviso_fim_em', $estado); }
                $resumo['terminados']++;
                $resumo['restaurantes'][] = ['restaurante_id' => $rid, 'aviso' => 'fim'];
            }
        }

        return $resumo;
    }
}
