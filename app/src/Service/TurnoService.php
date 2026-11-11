<?php

if (!class_exists('Turno')) {
    include_once __DIR__ . '/../Model/Turno.php';
}
include_once __DIR__ . '/../config/turno_schema.php';
include_once __DIR__ . '/../config/turno_helpers.php';
include_once __DIR__ . '/../config/presenca_online.php';

class TurnoService
{
    private $db;
    private $turno;

    public function __construct($database)
    {
        $this->db = $database->getConnection();
        $this->turno = new Turno($this->db);
        $this->garantirSchema();
    }

    private function garantirSchema(): void
    {
        if ($this->db instanceof PDO) {
            turno_schema_garantir($this->db);
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    private function normalizarStatus(?string $status): string
    {
        $valor = strtoupper(trim((string)$status));
        $mapa = [
            'FINALIZADO' => 'ENCERRADO',
            'ABERTO' => 'ATIVO',
            'FECHADO' => 'ENCERRADO',
            '' => 'ATIVO',
        ];

        return $mapa[$valor] ?? $valor;
    }

    private function normalizarTurno(?string $turno): string
    {
        $valor = strtoupper(trim((string)$turno));
        if ($valor === '') {
            return turno_detectar_tipo_atual($this->now());
        }

        $mapa = [
            'MANHÃ' => 'MANHA',
            'TARDE' => 'MANHA',
        ];

        return $mapa[$valor] ?? $valor;
    }

    private function turnoValido(string $turno): bool
    {
        return in_array($turno, ['MANHA', 'NOITE', 'INTEGRAL'], true);
    }

    private function statusValido(string $status): bool
    {
        return in_array($status, ['PLANEJADO', 'ATIVO', 'ENCERRADO', 'AUSENTE'], true);
    }

    private function validarData(string $data): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $data);
        return $dt instanceof DateTime && $dt->format('Y-m-d') === $data;
    }

    private function normalizarHora(?string $hora): ?string
    {
        if ($hora === null) {
            return null;
        }

        $hora = trim($hora);
        if ($hora === '') {
            return null;
        }

        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora)) {
            return null;
        }

        if (strlen($hora) === 5) {
            $hora .= ':00';
        }

        return $hora;
    }

    private function calcularDataSaida(string $data, ?string $horaEntrada, ?string $horaSaida): ?string
    {
        if ($horaSaida === null) {
            return null;
        }

        if ($horaEntrada !== null && $horaSaida < $horaEntrada) {
            return date('Y-m-d', strtotime($data . ' +1 day'));
        }

        return $data;
    }

    private function montarDuracaoMinutos(array $turno): int
    {
        if (empty($turno['data']) || empty($turno['hora_entrada'])) {
            return 0;
        }

        $inicio = strtotime($turno['data'] . ' ' . $turno['hora_entrada']);
        $fimData = $turno['data_saida'] ?? $turno['data'];
        $fimHora = $turno['hora_saida'] ?? $this->now()->format('H:i:s');
        $fim = strtotime($fimData . ' ' . $fimHora);

        if ($fim < $inicio) {
            $fim = strtotime($fimData . ' ' . $fimHora . ' +1 day');
        }

        return max(0, (int)floor(($fim - $inicio) / 60));
    }

    private function minutosParaHoras(int $minutos): float
    {
        return round($minutos / 60, 2);
    }

    private function formatarDuracaoHumana(int $minutos): string
    {
        $minutos = max(0, $minutos);
        $horas = intdiv($minutos, 60);
        $restante = $minutos % 60;

        if ($horas <= 0) {
            return $restante . ' min';
        }

        if ($restante === 0) {
            return $horas . ' h';
        }

        return $horas . 'h ' . str_pad((string)$restante, 2, '0', STR_PAD_LEFT) . 'min';
    }

    private function turnoInicioEsperado(string $turno): string
    {
        switch ($turno) {
            case 'NOITE':
                return '16:00:00';
            case 'INTEGRAL':
                return '08:00:00';
            case 'MANHA':
            default:
                return '08:00:00';
        }
    }

    private function formatarTurno(array $turno): array
    {
        $turno['status'] = $this->normalizarStatus($turno['status'] ?? 'ATIVO');
        $turno['turno'] = $this->normalizarTurno($turno['turno'] ?? 'MANHA');
        $turno['cargo'] = turno_normalizar_perfil($turno['cargo'] ?? ($turno['funcionario_perfil'] ?? ''));
        $turno['duracao_minutos'] = isset($turno['duracao_minutos'])
            ? (int)$turno['duracao_minutos']
            : $this->montarDuracaoMinutos($turno);
        $turno['horas_trabalhadas'] = $this->minutosParaHoras((int)$turno['duracao_minutos']);
        $turno['duracao_formatada'] = $this->formatarDuracaoHumana((int)$turno['duracao_minutos']);
        $turno['online'] = false;

        if (!empty($turno['ultimo_acesso'])) {
            $turno['online'] = strtotime((string)$turno['ultimo_acesso']) >= strtotime('-3 minutes');
        }

        $horaEntrada = $turno['hora_entrada'] ?? null;
        $inicioEsperado = $this->turnoInicioEsperado($turno['turno']);
        $turno['inicio_previsto'] = $inicioEsperado;
        $turno['atrasado'] = $horaEntrada !== null && $horaEntrada > $inicioEsperado;
        $turno['minutos_atraso'] = $turno['atrasado']
            ? max(0, (int)floor((strtotime($horaEntrada) - strtotime($inicioEsperado)) / 60))
            : 0;

        return $turno;
    }

    private function buscarUsuarioOuFalha(int $usuarioId, int $restauranteId): array
    {
        $usuario = $this->turno->buscarUsuario($usuarioId, $restauranteId);
        if (!$usuario || (int)($usuario['ativo'] ?? 0) !== 1) {
            throw new RuntimeException('Funcionário inválido para este restaurante.');
        }

        return $usuario;
    }

    private function registrarAuditoria(
        int $restauranteId,
        ?int $turnoId,
        int $responsavelId,
        int $funcionarioAfetadoId,
        string $tipoAcao,
        string $motivo,
        array $payload = []
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO auditoria_turnos
                (restaurante_id, turno_id, responsavel_id, funcionario_afetado_id, tipo_acao, motivo, payload_json)
            VALUES
                (:restaurante_id, :turno_id, :responsavel_id, :funcionario_afetado_id, :tipo_acao, :motivo, :payload_json)
        ");
        $stmt->bindValue(':restaurante_id', $restauranteId, PDO::PARAM_INT);
        if ($turnoId !== null) {
            $stmt->bindValue(':turno_id', $turnoId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':turno_id', null, PDO::PARAM_NULL);
        }
        $stmt->bindValue(':responsavel_id', $responsavelId, PDO::PARAM_INT);
        $stmt->bindValue(':funcionario_afetado_id', $funcionarioAfetadoId, PDO::PARAM_INT);
        $stmt->bindValue(':tipo_acao', $tipoAcao, PDO::PARAM_STR);
        $stmt->bindValue(':motivo', trim($motivo), PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $payload ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    }

    public function obterTurnoAtivoUsuario(int $usuarioId, int $restauranteId): ?array
    {
        $turno = $this->turno->buscarTurnoAtivoUsuario($usuarioId, $restauranteId);
        return $turno ? $this->formatarTurno($turno) : null;
    }

    public function usuarioPossuiTurnoAtivo(int $usuarioId, int $restauranteId): bool
    {
        return $this->obterTurnoAtivoUsuario($usuarioId, $restauranteId) !== null;
    }

    public function validarPermissaoTurno(array $contexto, int $funcionarioAfetadoId, bool $manual = false): array
    {
        $responsavelId = (int)($contexto['usuario_id'] ?? 0);
        $restauranteId = (int)($contexto['restaurante_id'] ?? 0);
        $perfil = turno_normalizar_perfil($contexto['perfil'] ?? '');

        if ($responsavelId <= 0 || $restauranteId <= 0) {
            return ['success' => false, 'message' => 'Sessão inválida.'];
        }

        if ($manual && $responsavelId !== $funcionarioAfetadoId && !turno_pode_intervir_em_outro_funcionario($perfil)) {
            return ['success' => false, 'message' => 'Sem permissão para intervir no turno de outro funcionário.'];
        }

        if (!$manual && $responsavelId !== $funcionarioAfetadoId && $perfil !== 'ADMIN') {
            return ['success' => false, 'message' => 'Você só pode operar o próprio turno.'];
        }

        return ['success' => true];
    }

    public function iniciarTurno(int $usuarioId, int $restauranteId, ?string $turno = null, ?string $cargo = null, array $opcoes = []): array
    {
        $usuario = $this->buscarUsuarioOuFalha($usuarioId, $restauranteId);
        $turnoTipo = $this->normalizarTurno($turno ?: turno_detectar_tipo_atual($this->now()));
        $cargo = turno_normalizar_perfil($cargo ?: ($usuario['perfil'] ?? 'GARCOM'));

        if (!$this->turnoValido($turnoTipo)) {
            return ['success' => false, 'message' => 'Turno inválido.'];
        }

        $ativo = $this->turno->buscarTurnoAtivoUsuario($usuarioId, $restauranteId);
        if ($ativo) {
            return [
                'success' => true,
                'message' => 'Turno já estava ativo.',
                'turno' => $this->formatarTurno($ativo),
                'already_active' => true,
            ];
        }

        $agora = $this->now();
        $manual = !empty($opcoes['manual']);
        $motivo = trim((string)($opcoes['motivo'] ?? ''));
        $responsavelId = (int)($opcoes['responsavel_id'] ?? $usuarioId);

        if ($manual && $motivo === '') {
            return ['success' => false, 'message' => 'Motivo obrigatório para abertura manual de turno.'];
        }

        $this->turno->usuario_id = $usuarioId;
        $this->turno->restaurante_id = $restauranteId;
        $this->turno->cargo = $cargo;
        $this->turno->data = $agora->format('Y-m-d');
        $this->turno->data_saida = null;
        $this->turno->turno = $turnoTipo;
        $this->turno->hora_entrada = $agora->format('H:i:s');
        $this->turno->hora_saida = null;
        $this->turno->status = 'ATIVO';
        $this->turno->observacoes = $opcoes['observacoes'] ?? null;
        $this->turno->motivo_intervencao = $motivo !== '' ? $motivo : null;
        $this->turno->responsavel_abertura_id = $responsavelId;
        $this->turno->responsavel_fechamento_id = null;
        $this->turno->abertura_manual = $manual ? 1 : 0;
        $this->turno->fechamento_manual = 0;

        if (!$this->turno->criar()) {
            return ['success' => false, 'message' => 'Erro ao iniciar turno.'];
        }

        if ($manual) {
            $this->registrarAuditoria(
                $restauranteId,
                (int)$this->turno->id,
                $responsavelId,
                $usuarioId,
                'ABERTURA_MANUAL',
                $motivo,
                ['turno' => $turnoTipo, 'cargo' => $cargo]
            );
        }

        $criado = $this->obterTurnoAtivoUsuario($usuarioId, $restauranteId);

        return [
            'success' => true,
            'message' => $manual ? 'Turno aberto manualmente com sucesso.' : 'Turno iniciado com sucesso.',
            'turno' => $criado,
            'id' => (int)$this->turno->id,
        ];
    }

    public function encerrarTurno(int $usuarioId, int $restauranteId, array $opcoes = []): array
    {
        $turnoAtivo = null;
        $turnoIdAlvo = (int)($opcoes['turno_id'] ?? 0);
        if ($turnoIdAlvo > 0) {
            $stAlvo = $this->db->prepare("
                SELECT * FROM funcionarios_turnos
                WHERE id = :id AND usuario_id = :uid AND restaurante_id = :rid AND UPPER(status) = 'ATIVO'
                LIMIT 1
            ");
            $stAlvo->execute([':id' => $turnoIdAlvo, ':uid' => $usuarioId, ':rid' => $restauranteId]);
            $turnoAtivo = $stAlvo->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $turnoAtivo = $this->turno->buscarTurnoAtivoUsuario($usuarioId, $restauranteId);
        }
        if (!$turnoAtivo) {
            return ['success' => false, 'message' => 'Nenhum turno ativo encontrado para este funcionário.'];
        }

        $manual = !empty($opcoes['manual']);
        $motivo = trim((string)($opcoes['motivo'] ?? ''));
        $responsavelId = (int)($opcoes['responsavel_id'] ?? $usuarioId);
        if ($manual && $motivo === '') {
            return ['success' => false, 'message' => 'Motivo obrigatório para fechamento manual de turno.'];
        }

        if (!$this->turno->ler((int)$turnoAtivo['id'], $restauranteId)) {
            return ['success' => false, 'message' => 'Turno não encontrado.'];
        }

        $agora = $this->now();
        $this->turno->data_saida = $agora->format('Y-m-d');
        $this->turno->hora_saida = $agora->format('H:i:s');
        $this->turno->status = 'ENCERRADO';
        $this->turno->responsavel_fechamento_id = $responsavelId;
        $this->turno->fechamento_manual = $manual ? 1 : 0;
        if ($manual && $motivo !== '') {
            $this->turno->motivo_intervencao = $motivo;
        }

        if (!$this->turno->atualizar()) {
            return ['success' => false, 'message' => 'Erro ao encerrar turno.'];
        }

        if ($manual) {
            $this->registrarAuditoria(
                $restauranteId,
                (int)$turnoAtivo['id'],
                $responsavelId,
                $usuarioId,
                'FECHAMENTO_MANUAL',
                $motivo,
                ['turno' => $turnoAtivo['turno'] ?? null]
            );
        }

        return [
            'success' => true,
            'message' => $manual ? 'Turno encerrado manualmente com sucesso.' : 'Turno encerrado com sucesso.',
            'turno_id' => (int)$turnoAtivo['id'],
        ];
    }

    public function abrirTurnoManual(array $contexto, int $funcionarioAfetadoId, string $motivo, ?string $turno = null): array
    {
        $permissao = $this->validarPermissaoTurno($contexto, $funcionarioAfetadoId, true);
        if (empty($permissao['success'])) {
            return $permissao;
        }

        return $this->iniciarTurno(
            $funcionarioAfetadoId,
            (int)$contexto['restaurante_id'],
            $turno,
            null,
            [
                'manual' => true,
                'motivo' => $motivo,
                'responsavel_id' => (int)$contexto['usuario_id'],
            ]
        );
    }

    public function fecharTurnoManual(array $contexto, int $funcionarioAfetadoId, string $motivo, ?int $turnoId = null): array
    {
        $permissao = $this->validarPermissaoTurno($contexto, $funcionarioAfetadoId, true);
        if (empty($permissao['success'])) {
            return $permissao;
        }

        return $this->encerrarTurno(
            $funcionarioAfetadoId,
            (int)$contexto['restaurante_id'],
            [
                'manual' => true,
                'motivo' => $motivo,
                'responsavel_id' => (int)$contexto['usuario_id'],
                'turno_id' => $turnoId,
            ]
        );
    }

    public function criarTurno(array $data): array
    {
        $restauranteId = (int)($data['restaurante_id'] ?? 0);
        $usuarioId = (int)($data['usuario_id'] ?? 0);
        $status = $this->normalizarStatus($data['status'] ?? 'ATIVO');
        $turno = $this->normalizarTurno($data['turno'] ?? 'MANHA');
        $cargo = turno_normalizar_perfil($data['cargo'] ?? '');

        if ($restauranteId <= 0 || $usuarioId <= 0) {
            return ['success' => false, 'message' => 'Dados incompletos.'];
        }
        if (!$this->turnoValido($turno) || !$this->statusValido($status)) {
            return ['success' => false, 'message' => 'Turno ou status inválido.'];
        }

        if ($status === 'ATIVO') {
            return $this->iniciarTurno($usuarioId, $restauranteId, $turno, $cargo ?: null, [
                'manual' => !empty($data['manual']),
                'motivo' => $data['motivo'] ?? '',
                'responsavel_id' => (int)($data['responsavel_id'] ?? $usuarioId),
                'observacoes' => $data['observacoes'] ?? null,
            ]);
        }

        $payload = [
            'usuario_id' => $usuarioId,
            'restaurante_id' => $restauranteId,
            'cargo' => $cargo ?: turno_normalizar_perfil(($this->buscarUsuarioOuFalha($usuarioId, $restauranteId)['perfil'] ?? 'GARCOM')),
            'data' => trim((string)($data['data'] ?? date('Y-m-d'))),
            'data_saida' => trim((string)($data['data_saida'] ?? '')) ?: null,
            'turno' => $turno,
            'hora_entrada' => $this->normalizarHora($data['hora_entrada'] ?? date('H:i:s')),
            'hora_saida' => $this->normalizarHora($data['hora_saida'] ?? null),
            'status' => $status,
            'observacoes' => trim((string)($data['observacoes'] ?? '')) ?: null,
        ];

        if (!$this->validarData($payload['data'])) {
            return ['success' => false, 'message' => 'Data inválida.'];
        }

        if ($payload['status'] === 'ENCERRADO' && $payload['hora_saida'] === null) {
            return ['success' => false, 'message' => 'Hora de saída obrigatória para turno encerrado.'];
        }

        $payload['data_saida'] = $payload['data_saida'] ?: $this->calcularDataSaida($payload['data'], $payload['hora_entrada'], $payload['hora_saida']);

        $this->turno->usuario_id = $payload['usuario_id'];
        $this->turno->restaurante_id = $payload['restaurante_id'];
        $this->turno->cargo = $payload['cargo'];
        $this->turno->data = $payload['data'];
        $this->turno->data_saida = $payload['data_saida'];
        $this->turno->turno = $payload['turno'];
        $this->turno->hora_entrada = $payload['hora_entrada'];
        $this->turno->hora_saida = $payload['hora_saida'];
        $this->turno->status = $payload['status'];
        $this->turno->observacoes = $payload['observacoes'];
        $this->turno->motivo_intervencao = trim((string)($data['motivo'] ?? '')) ?: null;
        $this->turno->responsavel_abertura_id = (int)($data['responsavel_id'] ?? $usuarioId);
        $this->turno->responsavel_fechamento_id = $payload['status'] === 'ENCERRADO' ? (int)($data['responsavel_id'] ?? $usuarioId) : null;
        $this->turno->abertura_manual = !empty($data['manual']) ? 1 : 0;
        $this->turno->fechamento_manual = $payload['status'] === 'ENCERRADO' && !empty($data['manual']) ? 1 : 0;

        if (!$this->turno->criar()) {
            return ['success' => false, 'message' => 'Erro ao criar turno.'];
        }

        return ['success' => true, 'message' => 'Turno criado com sucesso.', 'id' => (int)$this->turno->id];
    }

    public function atualizarTurno(int $id, array $data, int $restauranteId): array
    {
        if (!$this->turno->ler($id, $restauranteId)) {
            return ['success' => false, 'message' => 'Turno não encontrado.'];
        }

        $status = array_key_exists('status', $data) ? $this->normalizarStatus($data['status']) : $this->normalizarStatus($this->turno->status);
        $turno = array_key_exists('turno', $data) ? $this->normalizarTurno($data['turno']) : $this->normalizarTurno($this->turno->turno);
        if (!$this->turnoValido($turno) || !$this->statusValido($status)) {
            return ['success' => false, 'message' => 'Turno ou status inválido.'];
        }

        $this->turno->usuario_id = (int)($data['usuario_id'] ?? $this->turno->usuario_id);
        $this->turno->cargo = turno_normalizar_perfil($data['cargo'] ?? $this->turno->cargo);
        $this->turno->data = trim((string)($data['data'] ?? $this->turno->data));
        $this->turno->turno = $turno;
        $this->turno->hora_entrada = $this->normalizarHora($data['hora_entrada'] ?? $this->turno->hora_entrada);
        $this->turno->hora_saida = $this->normalizarHora($data['hora_saida'] ?? $this->turno->hora_saida);
        $this->turno->status = $status;
        $this->turno->observacoes = isset($data['observacoes']) ? trim((string)$data['observacoes']) : $this->turno->observacoes;
        $this->turno->motivo_intervencao = isset($data['motivo']) ? trim((string)$data['motivo']) : $this->turno->motivo_intervencao;
        $this->turno->data_saida = $this->calcularDataSaida($this->turno->data, $this->turno->hora_entrada, $this->turno->hora_saida);

        if (!$this->validarData($this->turno->data)) {
            return ['success' => false, 'message' => 'Data inválida.'];
        }
        if ($status === 'ENCERRADO' && $this->turno->hora_saida === null) {
            return ['success' => false, 'message' => 'Hora de saída obrigatória para turno encerrado.'];
        }

        if (!$this->turno->atualizar()) {
            return ['success' => false, 'message' => 'Erro ao atualizar turno.'];
        }

        return ['success' => true, 'message' => 'Turno atualizado com sucesso.'];
    }

    public function listar($restauranteId, $data = null)
    {
        return $this->turno->listar($restauranteId, $data);
    }

    public function listarArray($restauranteId, $data = null): array
    {
        $stmt = $this->listar($restauranteId, $data);
        $lista = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function (array $turno): array {
            return $this->formatarTurno($turno);
        }, $lista);
    }

    public function ativosHoje($restauranteId)
    {
        return $this->turno->ativosHoje($restauranteId);
    }

    public function ativosHojeArray($restauranteId): array
    {
        $stmt = $this->ativosHoje($restauranteId);
        $lista = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ids = array_values(array_unique(array_map(static function (array $turno): int {
            return (int)($turno['usuario_id'] ?? 0);
        }, $lista)));

        $onlineMap = [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtOnline = $this->db->prepare("
                SELECT id, ultimo_acesso
                FROM usuarios
                WHERE restaurante_id = ?
                  AND id IN ({$placeholders})
            ");
            $params = array_merge([(int)$restauranteId], $ids);
            $stmtOnline->execute($params);
            foreach ($stmtOnline->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $onlineMap[(int)$row['id']] = $row['ultimo_acesso'] ?? null;
            }
        }

        return array_map(function (array $turno) use ($onlineMap): array {
            $turno['ultimo_acesso'] = $onlineMap[(int)($turno['usuario_id'] ?? 0)] ?? null;
            return $this->formatarTurno($turno);
        }, $lista);
    }

    public function listarAuditoria(int $restauranteId, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        $orderCol = 'a.id';
        try {
            $colsStmt = $this->db->query("SHOW COLUMNS FROM auditoria_turnos");
            $cols = [];
            while ($c = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = (string)($c['Field'] ?? '');
            }
            if (in_array('criado_em', $cols, true)) {
                $orderCol = 'a.criado_em';
            } elseif (in_array('created_at', $cols, true)) {
                $orderCol = 'a.created_at';
            }
        } catch (Throwable $e) {
            // fallback seguro por id quando nao for possivel inspecionar schema
        }

        $stmt = $this->db->prepare("
            SELECT
                a.*,
                ur.nome AS responsavel_nome,
                uf.nome AS funcionario_nome
            FROM auditoria_turnos a
            LEFT JOIN usuarios ur ON ur.id = a.responsavel_id
            LEFT JOIN usuarios uf ON uf.id = a.funcionario_afetado_id
            WHERE a.restaurante_id = :restaurante_id
            ORDER BY {$orderCol} DESC, a.id DESC
            LIMIT {$limite}
        ");
        $stmt->bindValue(':restaurante_id', $restauranteId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function obterMetricasDashboard(int $restauranteId): array
    {
        $ativos = $this->ativosHojeArray($restauranteId);
        $online = presenca_buscar_equipa_online($this->db, $restauranteId, 100);

        $stmtNaoEncerrados = $this->db->prepare("
            SELECT COUNT(*)
            FROM funcionarios_turnos
            WHERE restaurante_id = :restaurante_id
              AND UPPER(status) = 'ATIVO'
              AND data < CURDATE()
        ");
        $stmtNaoEncerrados->bindValue(':restaurante_id', $restauranteId, PDO::PARAM_INT);
        $stmtNaoEncerrados->execute();
        $naoEncerrados = (int)$stmtNaoEncerrados->fetchColumn();

        $atrasados = 0;
        $tempoTotal = 0;
        foreach ($ativos as $turno) {
            if (!empty($turno['atrasado'])) {
                $atrasados++;
            }
            $tempoTotal += (int)($turno['duracao_minutos'] ?? 0);
        }

        return [
            'funcionarios_ativos' => count($ativos),
            'tempo_turno_minutos' => $tempoTotal,
            'tempo_turno_horas' => $this->minutosParaHoras($tempoTotal),
            'tempo_turno_formatado' => $this->formatarDuracaoHumana($tempoTotal),
            'atrasos' => $atrasados,
            'turnos_nao_encerrados' => $naoEncerrados,
            'online' => (int)($online['online'] ?? 0),
            'offline' => max(0, (int)($online['total'] ?? 0) - (int)($online['online'] ?? 0)),
        ];
    }

    public function vincularCaixaAoTurno(int $restauranteId, int $caixaId, int $turnoId, int $usuarioId): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO caixa_turnos
                (restaurante_id, caixa_id, turno_id, usuario_id, status, data_abertura)
            VALUES
                (:restaurante_id, :caixa_id, :turno_id, :usuario_id, 'ABERTO', NOW())
        ");
        $stmt->execute([
            'restaurante_id' => $restauranteId,
            'caixa_id' => $caixaId,
            'turno_id' => $turnoId,
            'usuario_id' => $usuarioId,
        ]);
    }

    public function encerrarVinculoCaixa(int $restauranteId, int $caixaId): void
    {
        $stmt = $this->db->prepare("
            UPDATE caixa_turnos
            SET status = 'FECHADO',
                data_fechamento = NOW()
            WHERE restaurante_id = :restaurante_id
              AND caixa_id = :caixa_id
              AND status = 'ABERTO'
        ");
        $stmt->execute([
            'restaurante_id' => $restauranteId,
            'caixa_id' => $caixaId,
        ]);
    }
}
