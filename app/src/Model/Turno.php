<?php

class Turno
{
    private $conn;
    private $table_name = 'funcionarios_turnos';

    public $id;
    public $usuario_id;
    public $restaurante_id;
    public $cargo;
    public $data;
    public $data_saida;
    public $turno;
    public $hora_entrada;
    public $hora_saida;
    public $status;
    public $observacoes;
    public $motivo_intervencao;
    public $responsavel_abertura_id;
    public $responsavel_fechamento_id;
    public $abertura_manual;
    public $fechamento_manual;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    private function sanitizeString($valor): string
    {
        return htmlspecialchars(strip_tags((string)$valor), ENT_QUOTES, 'UTF-8');
    }

    private function sanitizeNullableString($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string)$valor);
        if ($texto === '') {
            return null;
        }

        return htmlspecialchars(strip_tags($texto), ENT_QUOTES, 'UTF-8');
    }

    private function bindNullableValue(PDOStatement $stmt, string $param, $valor, int $tipoNaoNulo = PDO::PARAM_STR): void
    {
        if ($valor === null || $valor === '') {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($param, $valor, $tipoNaoNulo);
    }

    public function criar()
    {
        $query = "INSERT INTO {$this->table_name}
            (usuario_id, restaurante_id, cargo, data, data_saida, turno, hora_entrada, hora_saida, status, observacoes,
             motivo_intervencao, responsavel_abertura_id, responsavel_fechamento_id, abertura_manual, fechamento_manual)
            VALUES
            (:usuario_id, :restaurante_id, :cargo, :data, :data_saida, :turno, :hora_entrada, :hora_saida, :status, :observacoes,
             :motivo_intervencao, :responsavel_abertura_id, :responsavel_fechamento_id, :abertura_manual, :fechamento_manual)";

        $stmt = $this->conn->prepare($query);
        $this->bindBaseFields($stmt);

        if ($stmt->execute()) {
            $this->id = (int)$this->conn->lastInsertId();
            return true;
        }

        return false;
    }

    public function atualizar()
    {
        $query = "UPDATE {$this->table_name}
            SET usuario_id = :usuario_id,
                restaurante_id = :restaurante_id,
                cargo = :cargo,
                data = :data,
                data_saida = :data_saida,
                turno = :turno,
                hora_entrada = :hora_entrada,
                hora_saida = :hora_saida,
                status = :status,
                observacoes = :observacoes,
                motivo_intervencao = :motivo_intervencao,
                responsavel_abertura_id = :responsavel_abertura_id,
                responsavel_fechamento_id = :responsavel_fechamento_id,
                abertura_manual = :abertura_manual,
                fechamento_manual = :fechamento_manual
            WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$this->id, PDO::PARAM_INT);
        $this->bindBaseFields($stmt);

        return $stmt->execute();
    }

    private function bindBaseFields(PDOStatement $stmt): void
    {
        $stmt->bindValue(':usuario_id', (int)$this->usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(':restaurante_id', (int)$this->restaurante_id, PDO::PARAM_INT);
        $stmt->bindValue(':cargo', $this->sanitizeString($this->cargo), PDO::PARAM_STR);
        $stmt->bindValue(':data', $this->sanitizeString($this->data), PDO::PARAM_STR);
        $this->bindNullableValue($stmt, ':data_saida', $this->sanitizeNullableString($this->data_saida));
        $stmt->bindValue(':turno', $this->sanitizeString($this->turno), PDO::PARAM_STR);
        $this->bindNullableValue($stmt, ':hora_entrada', $this->sanitizeNullableString($this->hora_entrada));
        $this->bindNullableValue($stmt, ':hora_saida', $this->sanitizeNullableString($this->hora_saida));
        $stmt->bindValue(':status', $this->sanitizeString($this->status), PDO::PARAM_STR);
        $this->bindNullableValue($stmt, ':observacoes', $this->sanitizeNullableString($this->observacoes));
        $this->bindNullableValue($stmt, ':motivo_intervencao', $this->sanitizeNullableString($this->motivo_intervencao));
        $this->bindNullableValue($stmt, ':responsavel_abertura_id', $this->responsavel_abertura_id !== null ? (int)$this->responsavel_abertura_id : null, PDO::PARAM_INT);
        $this->bindNullableValue($stmt, ':responsavel_fechamento_id', $this->responsavel_fechamento_id !== null ? (int)$this->responsavel_fechamento_id : null, PDO::PARAM_INT);
        $stmt->bindValue(':abertura_manual', (int)($this->abertura_manual ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':fechamento_manual', (int)($this->fechamento_manual ?? 0), PDO::PARAM_INT);
    }

    public function listar($restaurante_id, $data = null)
    {
        $query = "
            SELECT
                t.*,
                u.nome AS funcionario_nome,
                u.perfil AS funcionario_perfil,
                ua.nome AS responsavel_abertura_nome,
                uf.nome AS responsavel_fechamento_nome,
                TIMESTAMPDIFF(
                    MINUTE,
                    TIMESTAMP(t.data, COALESCE(t.hora_entrada, '00:00:00')),
                    COALESCE(TIMESTAMP(COALESCE(t.data_saida, CURDATE()), COALESCE(t.hora_saida, CURRENT_TIME())), NOW())
                ) AS duracao_minutos
            FROM {$this->table_name} t
            LEFT JOIN usuarios u ON u.id = t.usuario_id
            LEFT JOIN usuarios ua ON ua.id = t.responsavel_abertura_id
            LEFT JOIN usuarios uf ON uf.id = t.responsavel_fechamento_id
            WHERE t.restaurante_id = :restaurante_id
        ";

        if ($data) {
            $query .= " AND t.data = :data";
        }

        $query .= " ORDER BY t.data DESC, t.hora_entrada DESC, t.id DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':restaurante_id', (int)$restaurante_id, PDO::PARAM_INT);
        if ($data) {
            $stmt->bindValue(':data', $data, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt;
    }

    public function ativosHoje($restaurante_id)
    {
        $query = "
            SELECT
                t.*,
                u.nome AS funcionario_nome,
                u.perfil AS funcionario_perfil
            FROM {$this->table_name} t
            LEFT JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.restaurante_id = :restaurante_id
              AND UPPER(t.status) = 'ATIVO'
            ORDER BY t.hora_entrada ASC, t.id DESC
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':restaurante_id', (int)$restaurante_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function ler($id, $restaurante_id = null)
    {
        $query = "SELECT * FROM {$this->table_name} WHERE id = :id";
        if ($restaurante_id !== null) {
            $query .= " AND restaurante_id = :restaurante_id";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
        if ($restaurante_id !== null) {
            $stmt->bindValue(':restaurante_id', (int)$restaurante_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        foreach ($row as $campo => $valor) {
            if (property_exists($this, $campo)) {
                $this->{$campo} = $valor;
            }
        }

        return true;
    }

    public function buscarTurnoAtivoUsuario(int $usuario_id, int $restaurante_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT t.*, u.nome AS funcionario_nome, u.perfil AS funcionario_perfil
            FROM {$this->table_name} t
            INNER JOIN usuarios u ON u.id = t.usuario_id
            WHERE t.usuario_id = :usuario_id
              AND t.restaurante_id = :restaurante_id
              AND UPPER(t.status) = 'ATIVO'
            ORDER BY t.hora_entrada DESC, t.id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(':restaurante_id', $restaurante_id, PDO::PARAM_INT);
        $stmt->execute();

        $turno = $stmt->fetch(PDO::FETCH_ASSOC);
        return $turno ?: null;
    }

    public function usuarioPertenceAoRestaurante(int $usuario_id, int $restaurante_id): bool
    {
        $stmt = $this->conn->prepare("
            SELECT 1
            FROM usuarios
            WHERE id = :usuario_id
              AND restaurante_id = :restaurante_id
              AND ativo = 1
            LIMIT 1
        ");
        $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(':restaurante_id', $restaurante_id, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    public function buscarUsuario(int $usuario_id, int $restaurante_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, restaurante_id, nome, perfil, ultimo_acesso, ativo
            FROM usuarios
            WHERE id = :usuario_id
              AND restaurante_id = :restaurante_id
            LIMIT 1
        ");
        $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(':restaurante_id', $restaurante_id, PDO::PARAM_INT);
        $stmt->execute();

        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        return $usuario ?: null;
    }

    public function existeConflito(int $usuario_id, int $restaurante_id, string $data, string $turno, ?int $ignorar_id = null): bool
    {
        $query = "
            SELECT 1
            FROM {$this->table_name}
            WHERE usuario_id = :usuario_id
              AND restaurante_id = :restaurante_id
              AND data = :data
              AND UPPER(turno) = :turno
        ";

        if ($ignorar_id !== null) {
            $query .= " AND id <> :ignorar_id";
        }

        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':usuario_id', $usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(':restaurante_id', $restaurante_id, PDO::PARAM_INT);
        $stmt->bindValue(':data', $data, PDO::PARAM_STR);
        $stmt->bindValue(':turno', strtoupper($turno), PDO::PARAM_STR);
        if ($ignorar_id !== null) {
            $stmt->bindValue(':ignorar_id', $ignorar_id, PDO::PARAM_INT);
        }
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }
}
