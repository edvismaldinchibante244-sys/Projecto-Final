<?php

namespace App\Service;

require_once __DIR__ . '/../config/mesa_display_helper.php';
require_once __DIR__ . '/ListaEsperaService.php';

use App\Model\Reserva;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

class ReservaService
{
    private const JANELA_CONFLITO_MINUTOS = 120;
    private const PRE_BLOQUEIO_MINUTOS = 5;
    private const ORIGENS_VALIDAS = ['app', 'telefone', 'presencial', 'qr'];

    private PDO $db;
    private Reserva $modelReserva;
    private DateTimeZone $timezone;

    public function __construct(PDO $db, ?Reserva $modelReserva = null)
    {
        $this->db = $db;
        $this->modelReserva = $modelReserva ?? new Reserva($db);
        $this->timezone = new DateTimeZone(date_default_timezone_get() ?: 'Africa/Maputo');
    }

    public function criarReserva(array $dados): array
    {
        $dadosNormalizados = $this->validarPayloadReserva($dados);
        $restauranteId = (int)$dadosNormalizados['restaurante_id'];
        $mesaId = isset($dadosNormalizados['mesa_atribuida']) ? (int)$dadosNormalizados['mesa_atribuida'] : 0;
        $sessaoToken = trim((string)($dados['sessao_token'] ?? ''));

        try {
            $mesasDisponiveis = $this->validarDisponibilidade(
                $restauranteId,
                $dadosNormalizados['data_reserva'],
                $dadosNormalizados['hora_reserva'],
                (int)$dadosNormalizados['quantidade_pessoas'],
                0,
                $sessaoToken
            );
        } catch (Throwable $e) {
            error_log('[RESERVAS] Nao foi possivel verificar a disponibilidade agora. ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nao foi possivel verificar a disponibilidade agora.',
                'error' => 'erro_interno',
            ];
        }

        if (empty($mesasDisponiveis)) {
            return [
                'success' => false,
                'message' => 'Nao ha mesas disponiveis para este horario e quantidade de pessoas.',
                'code' => 'SEM_DISPONIBILIDADE',
            ];
        }

        if ($mesaId > 0) {
            $mesaSelecionada = $this->mesaDisponivelNaLista($mesasDisponiveis, $mesaId);
            if (!$mesaSelecionada) {
                return [
                    'success' => false,
                    'message' => 'A mesa escolhida nao esta disponivel para esta reserva.',
                    'code' => 'MESA_INDISPONIVEL',
                ];
            }
        } else {
            $mesaSelecionada = $mesasDisponiveis[0];
            $mesaId = (int)$mesaSelecionada['id'];
        }

        $dadosNormalizados['mesa_atribuida'] = $mesaId;

        try {
            $this->db->beginTransaction();
            $dadosNormalizados['cliente_id'] = $this->sincronizarClienteReserva($dadosNormalizados);

            $criado = $this->modelReserva->criar($dadosNormalizados);
            if (!$criado) {
                throw new \RuntimeException('Falha ao persistir a reserva.');
            }

            $reservaId = (int)$this->db->lastInsertId();
            if (!empty($dadosNormalizados['cliente_id'])) {
                $this->marcarVisitaCliente(
                    (int)$dadosNormalizados['cliente_id'],
                    $restauranteId,
                    (string)($dadosNormalizados['data_reserva'] ?? date('Y-m-d'))
                );
            }
            $this->sincronizarStatusMesaReservaHoje($restauranteId, $mesaId);
            $this->liberarPreBloqueioPorToken($restauranteId, $sessaoToken);
            $this->db->commit();

            $this->agendarLembrete(
                $reservaId,
                $dadosNormalizados['data_reserva'] . ' ' . $dadosNormalizados['hora_reserva']
            );

            return [
                'success' => true,
                'message' => 'Reserva criada com sucesso.',
                'reserva_id' => $reservaId,
                'cliente_id' => $dadosNormalizados['cliente_id'],
                'mesa_atribuida' => $mesaId,
                'mesa_numero' => $mesaSelecionada['numero'] ?? null,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[RESERVAS] Nao foi possivel criar a reserva agora. ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nao foi possivel criar a reserva agora.',
                'error' => 'erro_interno',
            ];
        }
    }

    /**
     * @param int $mesaPreferida Mesa escolhida pelo restaurante ao confirmar.
     *                           0 mantem a mesa ja atribuida ou escolhe uma.
     */
    public function confirmarReserva(int $reservaId, int $restauranteId, int $mesaPreferida = 0): array
    {
        $reserva = $this->modelReserva->obter($reservaId, $restauranteId);
        if (!$reserva) {
            return ['success' => false, 'message' => 'Reserva nao encontrada.'];
        }

        if (in_array($reserva['status'], ['cancelado', 'no-show'], true)) {
            return ['success' => false, 'message' => 'A reserva nao pode mais ser confirmada.'];
        }

        // A mesa escolhida pelo restaurante ao confirmar tem precedencia sobre
        // a que estivesse guardada: e ela que vai na mensagem ao cliente.
        $mesaId = $mesaPreferida > 0 ? $mesaPreferida : (int)($reserva['mesa_atribuida'] ?? 0);
        $mesasDisponiveis = $this->validarDisponibilidade(
            $restauranteId,
            (string)$reserva['data_reserva'],
            (string)$reserva['hora_reserva'],
            (int)$reserva['quantidade_pessoas'],
            $reservaId
        );

        if ($mesaId > 0 && !$this->mesaDisponivelNaLista($mesasDisponiveis, $mesaId)) {
            if ($mesaPreferida > 0) {
                // Pedida explicitamente: avisar em vez de trocar em silencio,
                // porque o numero da mesa vai ser comunicado ao cliente.
                return [
                    'success' => false,
                    'message' => 'A mesa escolhida nao esta disponivel para esta reserva.',
                ];
            }
            $mesaId = 0;
        }

        if ($mesaId <= 0) {
            if (empty($mesasDisponiveis)) {
                return ['success' => false, 'message' => 'Nao ha mesa disponivel para confirmar esta reserva.'];
            }
            $mesaId = (int)$mesasDisponiveis[0]['id'];
        }

        $this->db->beginTransaction();
        try {
            if (!$this->modelReserva->atribuirMesa($reservaId, $mesaId, $restauranteId)) {
                throw new \RuntimeException('Falha ao atribuir mesa.');
            }

            if (!$this->modelReserva->confirmar($reservaId, $restauranteId, (string)($reserva['telefone_cliente'] ?? ''))) {
                throw new \RuntimeException('Falha ao confirmar reserva.');
            }

            $clienteIdConfirm = (int)($reserva['cliente_id'] ?? 0);
            if ($clienteIdConfirm > 0) {
                $this->marcarVisitaCliente(
                    $clienteIdConfirm,
                    $restauranteId,
                    (string)($reserva['data_reserva'] ?? date('Y-m-d'))
                );
            }

            $this->sincronizarStatusMesaReservaHoje($restauranteId, $mesaId);
            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Reserva confirmada com sucesso.',
                'mesa_atribuida' => $mesaId,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[RESERVAS] Nao foi possivel confirmar a reserva. ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nao foi possivel confirmar a reserva.',
                'error' => 'erro_interno',
            ];
        }
    }

    public function fazerCheckIn(int $reservaId, int $mesaId, int $restauranteId): array
    {
        if (function_exists('reserva_ensure_status_chegou')) {
            reserva_ensure_status_chegou($this->db);
        }

        $reserva = $this->modelReserva->obter($reservaId, $restauranteId);
        if (!$reserva) {
            return ['success' => false, 'message' => 'Reserva nao encontrada.'];
        }

        $statusAtual = strtolower((string)($reserva['status'] ?? ''));
        if (in_array($statusAtual, ['cancelado', 'no-show'], true)) {
            return ['success' => false, 'message' => 'Esta reserva nao permite check-in.'];
        }

        if ($statusAtual === 'chegou') {
            $prePedido = $this->prePedidoDaReserva($reserva);
            return [
                'success' => true,
                'message' => $prePedido
                    ? 'Cliente ja esta no salao. A abrir o pre-pedido #' . $prePedido['numero_pedido'] . '.'
                    : 'Cliente ja esta no salao. A abrir o pedido da mesa.',
                'mesa_atribuida' => (int)($reserva['mesa_atribuida'] ?? $mesaId),
                'ja_checkin' => true,
                'abrir_pedido' => true,
                'pedido_id' => $prePedido ? (int)$prePedido['id'] : null,
            ];
        }

        $mesa = $this->obterMesa($mesaId, $restauranteId);
        if (!$mesa) {
            return ['success' => false, 'message' => 'Mesa invalida para check-in.'];
        }

        if ((int)$mesa['capacidade'] < (int)$reserva['quantidade_pessoas']) {
            return ['success' => false, 'message' => 'A mesa selecionada nao comporta a quantidade de pessoas da reserva.'];
        }

        $mesaJaAtribuida = (int)($reserva['mesa_atribuida'] ?? 0) === $mesaId;
        $statusMesa = strtoupper((string)($mesa['status'] ?? ''));

        // Mesa já ligada a esta reserva: permite check-in mesmo se OCUPADA/RESERVADA.
        // Outra mesa: bloqueia se OCUPADA; senão valida conflito de horário.
        if (!$mesaJaAtribuida) {
            if ($statusMesa === 'OCUPADA') {
                return ['success' => false, 'message' => 'A mesa escolhida ja esta ocupada.'];
            }

            $mesasDisponiveis = $this->validarDisponibilidade(
                $restauranteId,
                (string)$reserva['data_reserva'],
                (string)$reserva['hora_reserva'],
                (int)$reserva['quantidade_pessoas'],
                $reservaId
            );

            if (!$this->mesaDisponivelNaLista($mesasDisponiveis, $mesaId)) {
                return ['success' => false, 'message' => 'A mesa escolhida nao esta disponivel para check-in.'];
            }
        }

        // Pre-pedido em espera (feito pelo QR ao reservar): vai para a cozinha agora.
        require_once __DIR__ . '/../api/pre_pedido_reserva.php';
        if (function_exists('pedido_sequencia_preparar')) {
            pedido_sequencia_preparar($this->db, $restauranteId); // fora da transacao
        }

        $this->db->beginTransaction();
        try {
            if (!$this->modelReserva->atribuirMesa($reservaId, $mesaId, $restauranteId)) {
                throw new \RuntimeException('Falha ao vincular mesa.');
            }

            if (!$this->modelReserva->marcarCheckIn($reservaId, $restauranteId)) {
                throw new \RuntimeException('Falha ao registar check-in.');
            }

            $stmtMesa = $this->db->prepare("UPDATE mesas SET status = 'OCUPADA' WHERE id = :id AND restaurante_id = :restaurante_id");
            $stmtMesa->execute([
                ':id' => $mesaId,
                ':restaurante_id' => $restauranteId,
            ]);

            // O cliente ja fez o pedido pelo QR: usar esse pedido (na mesa do check-in), nao abrir outro.
            // 1) pre-pedido em espera -> passa agora a pedido real e vai para a cozinha;
            // 2) pre-pedidos antigos, ja gravados como pedido -> muda-os para a mesa do check-in.
            $prePedido = reserva_pre_pedido_enviar($this->db, $restauranteId, $reservaId, $mesaId)
                ?? $this->prePedidoDaReserva($reserva);
            if ($prePedido && (int)$prePedido['mesa_id'] !== $mesaId) {
                $this->db->prepare('UPDATE pedidos SET mesa_id = :mesa, atualizado_em = NOW() WHERE id = :id AND restaurante_id = :rid')
                    ->execute([':mesa' => $mesaId, ':id' => (int)$prePedido['id'], ':rid' => $restauranteId]);
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => $prePedido
                    ? 'Check-in realizado. O cliente ja tinha o pre-pedido #' . $prePedido['numero_pedido'] . ' — a abrir esse pedido.'
                    : 'Check-in realizado. Mesa ocupada — a abrir o pedido.',
                'mesa_atribuida' => $mesaId,
                'abrir_pedido' => true,
                'pedido_id' => $prePedido ? (int)$prePedido['id'] : null,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[RESERVAS] Nao foi possivel realizar o check-in. ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nao foi possivel realizar o check-in.',
                'error' => 'erro_interno',
            ];
        }
    }

    /**
     * Pre-pedido em aberto que o cliente fez pelo QR da reserva, ou null.
     * Usa a ligacao pedido_qr_meta.reserva_id; para reservas anteriores a ela,
     * procura um pedido QR em aberto na mesa da reserva com o mesmo telefone ou nome.
     */
    public function prePedidoDaReserva(array $reserva): ?array
    {
        $reservaId = (int)($reserva['id'] ?? 0);
        $restauranteId = (int)($reserva['restaurante_id'] ?? 0);
        if ($reservaId <= 0 || $restauranteId <= 0) {
            return null;
        }
        $aberto = "UPPER(TRIM(COALESCE(p.status, ''))) NOT IN ('PAGO', 'CANCELADO')";
        try {
            if (function_exists('reserva_qr_coluna_existe') && reserva_qr_coluna_existe($this->db, 'pedido_qr_meta', 'reserva_id')) {
                $stmt = $this->db->prepare("
                    SELECT p.id, p.numero_pedido, p.mesa_id
                    FROM pedido_qr_meta m
                    INNER JOIN pedidos p ON p.id = m.pedido_id AND p.restaurante_id = m.restaurante_id
                    WHERE m.restaurante_id = :rid AND m.reserva_id = :reserva AND {$aberto}
                    ORDER BY p.id DESC LIMIT 1
                ");
                $stmt->execute([':rid' => $restauranteId, ':reserva' => $reservaId]);
                $linha = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($linha) {
                    return $linha;
                }
            }

            $mesaReserva = (int)($reserva['mesa_atribuida'] ?? 0);
            $telefone = preg_replace('/\D+/', '', (string)($reserva['telefone_cliente'] ?? ''));
            $nome = mb_strtolower(trim((string)($reserva['nome_cliente'] ?? '')));
            if ($mesaReserva <= 0 || ($telefone === '' && $nome === '')) {
                return null;
            }
            $semLigacao = (function_exists('reserva_qr_coluna_existe') && reserva_qr_coluna_existe($this->db, 'pedido_qr_meta', 'reserva_id'))
                ? 'AND m.reserva_id IS NULL' : '';
            $stmt = $this->db->prepare("
                SELECT p.id, p.numero_pedido, p.mesa_id, m.cliente_nome, m.cliente_telefone
                FROM pedido_qr_meta m
                INNER JOIN pedidos p ON p.id = m.pedido_id AND p.restaurante_id = m.restaurante_id
                WHERE m.restaurante_id = :rid AND p.mesa_id = :mesa {$semLigacao}
                  AND m.created_at >= DATE_SUB(:criada, INTERVAL 1 DAY)
                  AND {$aberto}
                ORDER BY p.id DESC LIMIT 10
            ");
            $stmt->execute([':rid' => $restauranteId, ':mesa' => $mesaReserva, ':criada' => (string)($reserva['criado_em'] ?? date('Y-m-d H:i:s'))]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $telC = preg_replace('/\D+/', '', (string)($c['cliente_telefone'] ?? ''));
                $mesmoTel = $telefone !== '' && $telC !== '' && substr($telC, -9) === substr($telefone, -9);
                $mesmoNome = $nome !== '' && mb_strtolower(trim((string)($c['cliente_nome'] ?? ''))) === $nome;
                if ($mesmoTel || $mesmoNome) {
                    return ['id' => $c['id'], 'numero_pedido' => $c['numero_pedido'], 'mesa_id' => $c['mesa_id']];
                }
            }
        } catch (Throwable $e) {
            error_log('[RESERVAS] Pre-pedido da reserva ' . $reservaId . ': ' . $e->getMessage());
        }
        return null;
    }

    public function cancelarReserva(int $reservaId, int $restauranteId): array
    {
        $reserva = $this->modelReserva->obter($reservaId, $restauranteId);
        if (!$reserva) {
            return ['success' => false, 'message' => 'Reserva nao encontrada.'];
        }

        $this->db->beginTransaction();
        try {
            if (!$this->modelReserva->cancelar($reservaId, $restauranteId)) {
                throw new \RuntimeException('Falha ao cancelar.');
            }

            $mesaId = (int)($reserva['mesa_atribuida'] ?? 0);
            if ($mesaId > 0) {
                $this->sincronizarStatusMesaReservaHoje($restauranteId, $mesaId);
            }

            $this->db->commit();

            $resposta = ['success' => true, 'message' => 'Reserva cancelada com sucesso.'];

            // Uma mesa que se liberta e a razao de existir da lista de espera:
            // procurar quem estava a espera dela. Fora da transacao, porque
            // uma falha aqui nao pode desfazer o cancelamento.
            $vaga = $this->ofereceVagaAListaDeEspera($restauranteId, $reserva, $mesaId);
            if ($vaga !== null) {
                $resposta['lista_espera'] = $vaga;
                $resposta['message'] .= ' ' . $vaga['message'];
            }

            return $resposta;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[RESERVAS] Nao foi possivel cancelar a reserva. ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nao foi possivel cancelar a reserva.',
                'error' => 'erro_interno',
            ];
        }
    }

    /**
     * Ve se alguem na lista de espera aproveita a mesa que acabou de vagar.
     *
     * Nao cria a reserva sozinho: quem decide e o restaurante, porque pode
     * haver razoes que o sistema nao conhece. Limita-se a assinalar o
     * candidato e a marca-lo como avisado.
     */
    private function ofereceVagaAListaDeEspera(int $restauranteId, array $reserva, int $mesaId): ?array
    {
        try {
            if (!ListaEsperaService::disponivelNoPlano($restauranteId)) {
                return null;
            }

            $capacidade = 0;
            if ($mesaId > 0) {
                $stmt = $this->db->prepare('SELECT capacidade FROM mesas WHERE id = :id AND restaurante_id = :rid');
                $stmt->execute([':id' => $mesaId, ':rid' => $restauranteId]);
                $capacidade = (int)$stmt->fetchColumn();
            }
            if ($capacidade <= 0) {
                // Sem mesa conhecida, usa-se a dimensao do grupo que desistiu:
                // e o tamanho que de certeza voltou a ficar livre.
                $capacidade = (int)($reserva['quantidade_pessoas'] ?? 0);
            }
            if ($capacidade <= 0) {
                return null;
            }

            $espera = new ListaEsperaService($this->db);
            $candidato = $espera->proximoDaFila(
                $restauranteId,
                (string)($reserva['data_reserva'] ?? ''),
                (string)($reserva['hora_reserva'] ?? ''),
                $capacidade
            );

            if (!$candidato) {
                return null;
            }

            $espera->marcarAvisado((int)$candidato['id'], $restauranteId);

            return [
                'espera_id' => (int)$candidato['id'],
                'nome' => (string)($candidato['nome_cliente'] ?? ''),
                'telefone' => (string)($candidato['telefone_cliente'] ?? ''),
                'email' => (string)($candidato['email_cliente'] ?? ''),
                'quantidade_pessoas' => (int)($candidato['quantidade_pessoas'] ?? 0),
                'hora_reserva' => substr((string)($candidato['hora_reserva'] ?? ''), 0, 5),
                'message' => 'Ha alguem na lista de espera para esta hora: '
                    . (string)($candidato['nome_cliente'] ?? '') . '.',
            ];
        } catch (Throwable $e) {
            error_log('[LISTA_ESPERA] Falha ao oferecer vaga: ' . $e->getMessage());
            return null;
        }
    }

    public function marcarNoShow(int $reservaId, int $restauranteId): array
    {
        $reserva = $this->modelReserva->obter($reservaId, $restauranteId);
        if (!$reserva) {
            return ['success' => false, 'message' => 'Reserva nao encontrada.'];
        }

        $this->db->beginTransaction();
        try {
            if (!$this->modelReserva->marcarNoShow($reservaId, $restauranteId)) {
                throw new \RuntimeException('Falha ao marcar no-show.');
            }

            $mesaId = (int)($reserva['mesa_atribuida'] ?? 0);
            if ($mesaId > 0) {
                $this->sincronizarStatusMesaReservaHoje($restauranteId, $mesaId);
            }

            $this->db->commit();

            return ['success' => true, 'message' => 'Reserva marcada como no-show.'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[RESERVAS] Nao foi possivel marcar no-show. ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Nao foi possivel marcar no-show.',
                'error' => 'erro_interno',
            ];
        }
    }

    public function validarDisponibilidade(
        int $restauranteId,
        string $data,
        string $hora,
        int $quantidade,
        int $ignorarReservaId = 0,
        string $ignorarPreBloqueioToken = ''
    ): array
    {
        $this->limparPreBloqueiosExpirados();

        if ($quantidade <= 0) {
            return [];
        }

        if (!$this->dataValida($data) || !$this->horaValida($hora)) {
            return [];
        }

        // Uma mesa ocupada AGORA so bloqueia reservas para as proximas horas (janela de conflito).
        // Antes bloqueava sempre: com o salao cheio ao almoco, nao se reservava para o jantar nem para amanha.
        $reservaEm = DateTimeImmutable::createFromFormat('Y-m-d H:i', $data . ' ' . substr($hora, 0, 5), $this->timezone);
        $limiteOcupacaoAtual = (new DateTimeImmutable('now', $this->timezone))
            ->modify('+' . self::JANELA_CONFLITO_MINUTOS . ' minutes');
        $excluirOcupadas = !$reservaEm || $reservaEm <= $limiteOcupacaoAtual;

        $sqlMesas = "SELECT id, numero, capacidade, status
            FROM mesas
            WHERE restaurante_id = :restaurante_id
              AND capacidade >= :quantidade"
            . ($excluirOcupadas ? " AND status <> 'OCUPADA'" : "") . "
            ORDER BY capacidade ASC, numero ASC";

        $stmtMesas = $this->db->prepare($sqlMesas);
        $stmtMesas->execute([
            ':restaurante_id' => $restauranteId,
            ':quantidade' => $quantidade,
        ]);
        $mesas = array_values(array_filter(
            $stmtMesas->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn(array $mesa): bool => !mesa_eh_balcao($mesa['numero'] ?? '')
        ));

        if (empty($mesas)) {
            return [];
        }

        $sqlConflitos = "SELECT mesa_atribuida
            FROM reservas
            WHERE restaurante_id = :restaurante_id
              AND data_reserva = :data_reserva
              AND status IN ('pendente', 'confirmado', 'chegou')
              AND mesa_atribuida IS NOT NULL
              AND ABS(TIME_TO_SEC(TIMEDIFF(hora_reserva, :hora_reserva))) < :janela_segundos";

        $params = [
            ':restaurante_id' => $restauranteId,
            ':data_reserva' => $data,
            ':hora_reserva' => $hora,
            ':janela_segundos' => self::JANELA_CONFLITO_MINUTOS * 60,
        ];

        if ($ignorarReservaId > 0) {
            $sqlConflitos .= " AND id <> :ignorar_id";
            $params[':ignorar_id'] = $ignorarReservaId;
        }

        $stmtConflitos = $this->db->prepare($sqlConflitos);
        $stmtConflitos->execute($params);

        $mesasOcupadasNoHorario = [];
        while ($row = $stmtConflitos->fetch(PDO::FETCH_ASSOC)) {
            $mesaId = (int)($row['mesa_atribuida'] ?? 0);
            if ($mesaId > 0) {
                $mesasOcupadasNoHorario[$mesaId] = true;
            }
        }

        try {
            $sqlPreBloqueios = "SELECT mesa_id
                FROM reserva_pre_bloqueios
                WHERE restaurante_id = :restaurante_id
                  AND data_reserva = :data_reserva
                  AND expires_at > NOW()
                  AND ABS(TIME_TO_SEC(TIMEDIFF(hora_reserva, :hora_reserva))) < :janela_segundos";

            $ignorarPreBloqueioToken = trim($ignorarPreBloqueioToken);
            if ($ignorarPreBloqueioToken !== '') {
                $sqlPreBloqueios .= " AND token_sessao <> :ignorar_token_sessao_hash";
                if (function_exists('reserva_qr_hold_legacy_plaintext_compat_enabled') && reserva_qr_hold_legacy_plaintext_compat_enabled()) {
                    $sqlPreBloqueios .= " AND token_sessao <> :ignorar_token_sessao_legacy";
                }
            }

            $stmtPreBloqueios = $this->db->prepare($sqlPreBloqueios);
            $paramsPreBloqueios = [
                ':restaurante_id' => $restauranteId,
                ':data_reserva' => $data,
                ':hora_reserva' => $hora,
                ':janela_segundos' => self::JANELA_CONFLITO_MINUTOS * 60,
            ];
            if ($ignorarPreBloqueioToken !== '') {
                $paramsPreBloqueios[':ignorar_token_sessao_hash'] = function_exists('reserva_qr_hold_token_hash')
                    ? reserva_qr_hold_token_hash($ignorarPreBloqueioToken)
                    : security_hash_secret('reserva_qr_hold:' . $ignorarPreBloqueioToken);
                if (function_exists('reserva_qr_hold_legacy_plaintext_compat_enabled') && reserva_qr_hold_legacy_plaintext_compat_enabled()) {
                    $paramsPreBloqueios[':ignorar_token_sessao_legacy'] = $ignorarPreBloqueioToken;
                }
            }

            $stmtPreBloqueios->execute($paramsPreBloqueios);

            while ($row = $stmtPreBloqueios->fetch(PDO::FETCH_ASSOC)) {
                $mesaId = (int)($row['mesa_id'] ?? 0);
                if ($mesaId > 0) {
                    $mesasOcupadasNoHorario[$mesaId] = true;
                }
            }
        } catch (Throwable $e) {
            // Mantem compatibilidade caso a tabela ainda nao exista nesta requisicao.
        }

        $mesasFiltradas = array_values(array_filter($mesas, static function (array $mesa) use ($mesasOcupadasNoHorario) {
            return !isset($mesasOcupadasNoHorario[(int)$mesa['id']]);
        }));

        return $this->incluirMesaDaReservaAtual($mesasFiltradas, $restauranteId, $ignorarReservaId, $quantidade);
    }

    private function incluirMesaDaReservaAtual(array $mesasDisponiveis, int $restauranteId, int $reservaId, int $quantidade): array
    {
        if ($reservaId <= 0) {
            return $mesasDisponiveis;
        }

        $stmt = $this->db->prepare("
            SELECT mesa_atribuida
            FROM reservas
            WHERE id = :id
              AND restaurante_id = :restaurante_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $reservaId,
            ':restaurante_id' => $restauranteId,
        ]);
        $mesaReservaId = (int)$stmt->fetchColumn();
        if ($mesaReservaId <= 0 || $this->mesaDisponivelNaLista($mesasDisponiveis, $mesaReservaId)) {
            return $mesasDisponiveis;
        }

        $mesaReserva = $this->obterMesa($mesaReservaId, $restauranteId);
        if (!$mesaReserva || (int)$mesaReserva['capacidade'] < $quantidade) {
            return $mesasDisponiveis;
        }

        $mesasDisponiveis[] = $mesaReserva;

        return $mesasDisponiveis;
    }

    public function criarPreBloqueio(int $restauranteId, string $sessaoToken, string $data, string $hora, int $quantidade, ?int $mesaId = null, int $duracaoMinutos = self::PRE_BLOQUEIO_MINUTOS): array
    {
        $sessaoToken = trim($sessaoToken);
        if ($restauranteId <= 0 || $sessaoToken === '') {
            return [
                'success' => false,
                'message' => 'Sessao de reserva invalida para pre-bloqueio.',
            ];
        }

        if (!$this->dataValida($data) || !$this->horaValida($hora) || $quantidade <= 0) {
            return [
                'success' => false,
                'message' => 'Dados invalidos para pre-bloqueio da reserva.',
            ];
        }

        $dataHoraReserva = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $data . ' ' . substr($hora, 0, 5),
            $this->timezone
        );
        if (!$dataHoraReserva || $dataHoraReserva < new DateTimeImmutable('now', $this->timezone)) {
            return [
                'success' => false,
                'message' => 'Escolha uma data e hora futuras para pre-bloquear a mesa.',
            ];
        }

        $this->limparPreBloqueiosExpirados();
        $this->liberarPreBloqueioPorToken($restauranteId, $sessaoToken);

        $mesasDisponiveis = $this->validarDisponibilidade($restauranteId, $data, $hora, $quantidade);
        if (empty($mesasDisponiveis)) {
            return [
                'success' => false,
                'message' => 'Nao ha mesas disponiveis para este horario.',
                'code' => 'SEM_DISPONIBILIDADE',
            ];
        }

        $mesaSelecionada = null;
        if ($mesaId !== null && $mesaId > 0) {
            $mesaSelecionada = $this->mesaDisponivelNaLista($mesasDisponiveis, $mesaId);
            if (!$mesaSelecionada) {
                return [
                    'success' => false,
                    'message' => 'A mesa escolhida nao esta disponivel para pre-bloqueio.',
                    'code' => 'MESA_INDISPONIVEL',
                ];
            }
        } else {
            $mesaSelecionada = $mesasDisponiveis[0];
            $mesaId = (int)$mesaSelecionada['id'];
        }

        $duracaoMinutos = max(2, min(20, $duracaoMinutos));
        // Em UTC, como o resto do QR: e comparado com NOW() (sessao MySQL em UTC) e com
        // reserva_qr_now_string(). Na hora local do PHP o bloqueio durava horas a mais.
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $duracaoMinutos . ' minutes')
            ->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
            INSERT INTO reserva_pre_bloqueios (
                restaurante_id, mesa_id, token_sessao, data_reserva, hora_reserva, quantidade_pessoas, expires_at
            ) VALUES (
                :restaurante_id, :mesa_id, :token_sessao, :data_reserva, :hora_reserva, :quantidade_pessoas, :expires_at
            )
        ");
        $stmt->execute([
            ':restaurante_id' => $restauranteId,
            ':mesa_id' => $mesaId,
            // A coluna existente comporta o HMAC SHA-256 (64 chars); não exige migração.
            ':token_sessao' => function_exists('reserva_qr_hold_token_hash')
                ? reserva_qr_hold_token_hash($sessaoToken)
                : security_hash_secret('reserva_qr_hold:' . $sessaoToken),
            ':data_reserva' => $data,
            ':hora_reserva' => substr($hora, 0, 5) . ':00',
            ':quantidade_pessoas' => $quantidade,
            ':expires_at' => $expiresAt,
        ]);

        return [
            'success' => true,
            'message' => 'Mesa pre-bloqueada temporariamente.',
            'mesa' => $mesaSelecionada,
            'expires_at' => $expiresAt,
            'duracao_minutos' => $duracaoMinutos,
        ];
    }

    public function liberarPreBloqueioPorToken(int $restauranteId, string $sessaoToken): void
    {
        $sessaoToken = trim($sessaoToken);
        if ($restauranteId <= 0 || $sessaoToken === '') {
            return;
        }

        try {
            $tokenHash = function_exists('reserva_qr_hold_token_hash')
                ? reserva_qr_hold_token_hash($sessaoToken)
                : security_hash_secret('reserva_qr_hold:' . $sessaoToken);
            $legacySql = function_exists('reserva_qr_hold_legacy_plaintext_compat_enabled')
                && reserva_qr_hold_legacy_plaintext_compat_enabled()
                ? " OR token_sessao = :token_sessao_legacy"
                : "";
            $stmt = $this->db->prepare("
                DELETE FROM reserva_pre_bloqueios
                WHERE restaurante_id = :restaurante_id
                  AND (token_sessao = :token_sessao_hash{$legacySql})
            ");
            $params = [
                ':restaurante_id' => $restauranteId,
                ':token_sessao_hash' => $tokenHash,
            ];
            if ($legacySql !== '') {
                $params[':token_sessao_legacy'] = $sessaoToken;
            }
            $stmt->execute($params);
        } catch (Throwable $e) {
            // Mantem compatibilidade se a tabela ainda nao existir.
        }
    }

    public function calcularOcupacao(int $restauranteId, string $data): array
    {
        $stmtTotalMesas = $this->db->prepare("SELECT COUNT(*) FROM mesas WHERE restaurante_id = :restaurante_id");
        $stmtTotalMesas->execute([':restaurante_id' => $restauranteId]);
        $totalMesas = (int)$stmtTotalMesas->fetchColumn();

        $stmtReservas = $this->db->prepare("
            SELECT status, COUNT(*) AS total
            FROM reservas
            WHERE restaurante_id = :restaurante_id
              AND data_reserva = :data_reserva
            GROUP BY status
        ");
        $stmtReservas->execute([
            ':restaurante_id' => $restauranteId,
            ':data_reserva' => $data,
        ]);

        $totais = [
            'pendente' => 0,
            'confirmado' => 0,
            'cancelado' => 0,
            'no-show' => 0,
        ];

        while ($row = $stmtReservas->fetch(PDO::FETCH_ASSOC)) {
            $status = (string)$row['status'];
            if (array_key_exists($status, $totais)) {
                $totais[$status] = (int)$row['total'];
            }
        }

        $ativas = $totais['pendente'] + $totais['confirmado'];
        $percentual = $totalMesas > 0 ? round(($ativas / $totalMesas) * 100, 1) : 0.0;

        return [
            'total_mesas' => $totalMesas,
            'reservas_ativas' => $ativas,
            'pendentes' => $totais['pendente'],
            'confirmadas' => $totais['confirmado'],
            'canceladas' => $totais['cancelado'],
            'no_show' => $totais['no-show'],
            'percentual' => $percentual,
        ];
    }

    public function agendarLembrete(int $reservaId, string $dataHoraReserva): bool
    {
        return $reservaId > 0 && $dataHoraReserva !== '';
    }

    private function validarPayloadReserva(array $dados): array
    {
        $restauranteId = (int)($dados['restaurante_id'] ?? 0);
        $clienteId = !empty($dados['cliente_id']) ? (int)$dados['cliente_id'] : null;
        $clienteExistente = null;
        $dataReserva = trim((string)($dados['data_reserva'] ?? $dados['data'] ?? ''));
        $horaReserva = trim((string)($dados['hora_reserva'] ?? $dados['hora'] ?? ''));
        $quantidadePessoas = (int)($dados['quantidade_pessoas'] ?? $dados['pessoas'] ?? 1);
        $status = $this->normalizarStatus((string)($dados['status'] ?? $dados['novo_status'] ?? 'pendente'));
        $origem = $this->normalizarOrigem($dados['origem'] ?? 'app');
        $statusValidos = ['pendente', 'confirmado', 'cancelado', 'no-show'];

        if ($restauranteId <= 0) {
            throw new \InvalidArgumentException('Restaurante invalido para a reserva.');
        }

        if ($clienteId !== null) {
            $clienteExistente = $this->buscarClientePorIdNoRestaurante($clienteId, $restauranteId);
            if (!$clienteExistente) {
                throw new \InvalidArgumentException('Cliente informado nao pertence ao restaurante.');
            }
        }

        $nomeCliente = trim((string)($dados['nome_cliente'] ?? $dados['nome'] ?? ($clienteExistente['nome'] ?? '')));
        $emailCliente = $dados['email_cliente'] ?? $dados['email'] ?? ($clienteExistente['email'] ?? null);
        $telefoneCliente = $dados['telefone_cliente'] ?? $dados['telefone'] ?? ($clienteExistente['telefone'] ?? null);

        if ($nomeCliente === '') {
            throw new \InvalidArgumentException('O nome do cliente e obrigatorio.');
        }

        if (!$this->dataValida($dataReserva)) {
            throw new \InvalidArgumentException('Data da reserva invalida.');
        }

        if (!$this->horaValida($horaReserva)) {
            throw new \InvalidArgumentException('Hora da reserva invalida.');
        }

        if ($quantidadePessoas <= 0) {
            throw new \InvalidArgumentException('A quantidade de pessoas deve ser maior que zero.');
        }

        if (!in_array($status, $statusValidos, true)) {
            $status = 'pendente';
        }

        $reservaDateTime = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $dataReserva . ' ' . substr($horaReserva, 0, 5),
            $this->timezone
        );

        if (!$reservaDateTime) {
            throw new \InvalidArgumentException('Nao foi possivel interpretar a data e hora da reserva.');
        }

        if ($reservaDateTime < new DateTimeImmutable('now', $this->timezone)) {
            throw new \InvalidArgumentException('Nao e permitido criar reservas em horario passado.');
        }

        return [
            'restaurante_id' => $restauranteId,
            'cliente_id' => $clienteId,
            'nome_cliente' => $nomeCliente,
            'email_cliente' => $this->normalizarEmail($emailCliente),
            'telefone_cliente' => $this->normalizarTelefone($telefoneCliente),
            'data_reserva' => $dataReserva,
            'hora_reserva' => substr($horaReserva, 0, 5) . ':00',
            'quantidade_pessoas' => $quantidadePessoas,
            'observacoes' => $this->normalizarTexto($dados['observacoes'] ?? $dados['observacao'] ?? null, 500),
            'status' => $status,
            'origem' => $origem,
            'mesa_atribuida' => !empty($dados['mesa_atribuida']) ? (int)$dados['mesa_atribuida'] : (!empty($dados['mesa_id']) ? (int)$dados['mesa_id'] : null),
        ];
    }

    private function sincronizarClienteReserva(array &$dados): ?int
    {
        $restauranteId = (int)$dados['restaurante_id'];
        $clienteId = (int)($dados['cliente_id'] ?? 0);
        $cliente = null;

        if ($clienteId > 0) {
            $cliente = $this->buscarClientePorIdNoRestaurante($clienteId, $restauranteId);
        } else {
            $cliente = $this->buscarClientePorContato(
                $restauranteId,
                $dados['telefone_cliente'] ?? null,
                $dados['email_cliente'] ?? null
            );
        }

        if ($cliente) {
            $this->validarClienteDisponivelParaReserva($cliente);

            if ($dados['email_cliente'] === null && !empty($cliente['email'])) {
                $dados['email_cliente'] = (string)$cliente['email'];
            }

            if ($dados['telefone_cliente'] === null && !empty($cliente['telefone'])) {
                $dados['telefone_cliente'] = (string)$cliente['telefone'];
            }

            $this->atualizarClienteParaReserva((int)$cliente['id'], $restauranteId, $dados, $cliente);
            return (int)$cliente['id'];
        }

        if ($clienteId > 0) {
            throw new \InvalidArgumentException('Cliente informado nao pertence ao restaurante.');
        }

        if (($dados['telefone_cliente'] ?? null) === null && ($dados['email_cliente'] ?? null) === null) {
            return null;
        }

        return $this->criarClienteParaReserva($restauranteId, $dados);
    }

    private function obterMesa(int $mesaId, int $restauranteId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, numero, capacidade, status
            FROM mesas
            WHERE id = :id AND restaurante_id = :restaurante_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $mesaId,
            ':restaurante_id' => $restauranteId,
        ]);

        $mesa = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mesa && mesa_eh_balcao($mesa['numero'] ?? '')) {
            return null;
        }
        return $mesa ?: null;
    }

    private function mesaDisponivelNaLista(array $mesasDisponiveis, int $mesaId): ?array
    {
        foreach ($mesasDisponiveis as $mesa) {
            if ((int)$mesa['id'] === $mesaId) {
                return $mesa;
            }
        }

        return null;
    }

    private function sincronizarStatusMesaReservaHoje(int $restauranteId, int $mesaId): void
    {
        if ($mesaId <= 0) {
            return;
        }

        $mesa = $this->obterMesa($mesaId, $restauranteId);
        if (!$mesa || strtoupper((string)($mesa['status'] ?? '')) === 'OCUPADA') {
            return;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM reservas
            WHERE restaurante_id = :restaurante_id
              AND mesa_atribuida = :mesa_id
              AND data_reserva = CURDATE()
              AND status IN ('pendente', 'confirmado')
        ");
        $stmt->execute([
            ':restaurante_id' => $restauranteId,
            ':mesa_id' => $mesaId,
        ]);
        $temReservaHoje = (int)$stmt->fetchColumn() > 0;

        $novoStatus = $temReservaHoje ? 'RESERVADA' : 'LIVRE';
        $stmtMesa = $this->db->prepare("
            UPDATE mesas
            SET status = :status
            WHERE id = :id
              AND restaurante_id = :restaurante_id
              AND status <> 'OCUPADA'
        ");
        $stmtMesa->execute([
            ':status' => $novoStatus,
            ':id' => $mesaId,
            ':restaurante_id' => $restauranteId,
        ]);
    }

    private function dataValida(string $data): bool
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $data, $this->timezone);
        return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $data;
    }

    private function horaValida(string $hora): bool
    {
        $horaCurta = substr($hora, 0, 5);
        $dt = DateTimeImmutable::createFromFormat('H:i', $horaCurta, $this->timezone);
        return $dt instanceof DateTimeImmutable && $dt->format('H:i') === $horaCurta;
    }

    private function normalizarEmail($valor): ?string
    {
        $email = trim((string)$valor);
        if ($email === '') {
            return null;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email do cliente invalido.');
        }

        return $email;
    }

    private function normalizarTelefone($valor): ?string
    {
        $telefone = trim((string)$valor);
        if ($telefone === '') {
            return null;
        }

        $prefixo = str_starts_with($telefone, '+') ? '+' : '';
        $digitos = preg_replace('/\D+/', '', $telefone);
        if ($digitos === '' || strlen($digitos) < 8) {
            throw new \InvalidArgumentException('Telefone do cliente invalido.');
        }

        return substr($prefixo . $digitos, 0, 30);
    }

    private function normalizarTexto($valor, int $limite): ?string
    {
        $texto = trim((string)$valor);
        if ($texto === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($texto, 0, $limite) : substr($texto, 0, $limite);
    }

    private function normalizarStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'confirmada' => 'confirmado',
            'cancelada' => 'cancelado',
            'no_show', 'noshow' => 'no-show',
            default => $status !== '' ? $status : 'pendente',
        };
    }

    private function normalizarOrigem($origem): string
    {
        $origem = strtolower(trim((string)$origem));
        return in_array($origem, self::ORIGENS_VALIDAS, true) ? $origem : 'app';
    }

    private function limparPreBloqueiosExpirados(): void
    {
        try {
            $this->db->exec("DELETE FROM reserva_pre_bloqueios WHERE expires_at <= NOW()");
        } catch (Throwable $e) {
            // Compatibilidade com instalacoes antigas sem a tabela.
        }
    }

    private function buscarClientePorIdNoRestaurante(int $clienteId, int $restauranteId): ?array
    {
        if ($clienteId <= 0 || $restauranteId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM clientes
            WHERE id = :id
              AND restaurante_id = :restaurante_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $clienteId,
            ':restaurante_id' => $restauranteId,
        ]);

        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        return $cliente ?: null;
    }

    private function buscarClientePorContato(int $restauranteId, ?string $telefone, ?string $email): ?array
    {
        $condicoes = [];
        $params = [':restaurante_id' => $restauranteId];

        if ($telefone !== null && $telefone !== '') {
            $condicoes[] = 'telefone = :telefone';
            $params[':telefone'] = $telefone;
        }

        if ($email !== null && $email !== '') {
            $condicoes[] = 'LOWER(email) = LOWER(:email)';
            $params[':email'] = $email;
        }

        if (empty($condicoes)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM clientes
            WHERE restaurante_id = :restaurante_id
              AND (" . implode(' OR ', $condicoes) . ")
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute($params);

        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
        return $cliente ?: null;
    }

    private function validarClienteDisponivelParaReserva(array $cliente): void
    {
        if (isset($cliente['ativo']) && (int)$cliente['ativo'] === 0) {
            throw new \InvalidArgumentException('O cliente informado esta inativo.');
        }

        if (isset($cliente['bloqueado']) && (int)$cliente['bloqueado'] === 1) {
            $motivo = trim((string)($cliente['motivo_bloqueio'] ?? ''));
            throw new \InvalidArgumentException(
                $motivo !== ''
                    ? 'O cliente esta bloqueado: ' . $motivo
                    : 'O cliente informado esta bloqueado.'
            );
        }
    }

    private function atualizarClienteParaReserva(int $clienteId, int $restauranteId, array $dados, array $clienteAtual): void
    {
        $set = [];
        $params = [
            ':id' => $clienteId,
            ':restaurante_id' => $restauranteId,
        ];

        $nome = trim((string)($dados['nome_cliente'] ?? ''));
        $email = $dados['email_cliente'] ?? null;
        $telefone = $dados['telefone_cliente'] ?? null;

        if ($nome !== '' && trim((string)($clienteAtual['nome'] ?? '')) !== $nome) {
            $set[] = 'nome = :nome';
            $params[':nome'] = $nome;
        }

        if ($email !== null && trim((string)($clienteAtual['email'] ?? '')) !== $email) {
            $set[] = 'email = :email';
            $params[':email'] = $email;
        }

        if ($telefone !== null && trim((string)($clienteAtual['telefone'] ?? '')) !== $telefone) {
            $set[] = 'telefone = :telefone';
            $params[':telefone'] = $telefone;
        }

        if (empty($set)) {
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE clientes
            SET " . implode(', ', $set) . "
            WHERE id = :id
              AND restaurante_id = :restaurante_id
        ");
        $stmt->execute($params);
    }

    /**
     * Actualiza data_ultima_visita (e primeira se vazia) a partir da data da reserva.
     */
    private function marcarVisitaCliente(int $clienteId, int $restauranteId, string $dataReserva): void
    {
        if ($clienteId <= 0 || $restauranteId <= 0) {
            return;
        }

        $data = preg_match('/^\d{4}-\d{2}-\d{2}/', $dataReserva)
            ? substr($dataReserva, 0, 10)
            : date('Y-m-d');

        $stmt = $this->db->prepare("
            UPDATE clientes
            SET data_ultima_visita = CASE
                    WHEN data_ultima_visita IS NULL OR data_ultima_visita < :data THEN :data2
                    ELSE data_ultima_visita
                END,
                data_primeira_visita = COALESCE(data_primeira_visita, :data3)
            WHERE id = :id
              AND restaurante_id = :restaurante_id
        ");
        $stmt->execute([
            ':data' => $data,
            ':data2' => $data,
            ':data3' => $data,
            ':id' => $clienteId,
            ':restaurante_id' => $restauranteId,
        ]);
    }

    private function criarClienteParaReserva(int $restauranteId, array $dados): int
    {
        $dataVisita = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($dados['data_reserva'] ?? ''))
            ? substr((string)$dados['data_reserva'], 0, 10)
            : date('Y-m-d');

        $stmt = $this->db->prepare("
            INSERT INTO clientes (
                restaurante_id, nome, email, telefone, tipo_cliente,
                data_primeira_visita, data_ultima_visita
            )
            VALUES (
                :restaurante_id, :nome, :email, :telefone, 'comum',
                :data_primeira, :data_ultima
            )
        ");
        $stmt->execute([
            ':restaurante_id' => $restauranteId,
            ':nome' => $dados['nome_cliente'],
            ':email' => $dados['email_cliente'] ?? null,
            ':telefone' => $dados['telefone_cliente'] ?? null,
            ':data_primeira' => $dataVisita,
            ':data_ultima' => $dataVisita,
        ]);

        return (int)$this->db->lastInsertId();
    }
}
