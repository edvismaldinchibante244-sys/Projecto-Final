<?php

/**
 * Model: Reserva
 * Gerencia reservas de mesas
 */

namespace App\Model;

class Reserva
{
    private $db;

    public function __construct($database)
    {
        $this->db = $database;
    }

    /**
     * Criar nova reserva
     */
    public function criar($dados)
    {
        $query = "INSERT INTO reservas 
                  (restaurante_id, cliente_id, nome_cliente, email_cliente, telefone_cliente,
                   data_reserva, hora_reserva, quantidade_pessoas, observacoes, status, origem, mesa_atribuida)
                  VALUES 
                  (:restaurante_id, :cliente_id, :nome_cliente, :email_cliente, :telefone_cliente,
                   :data_reserva, :hora_reserva, :quantidade_pessoas, :observacoes, :status, :origem, :mesa_atribuida)";

        $stmt = $this->db->prepare($query);

        return $stmt->execute([
            ':restaurante_id' => $dados['restaurante_id'],
            ':cliente_id' => $dados['cliente_id'] ?? null,
            ':nome_cliente' => $dados['nome_cliente'],
            ':email_cliente' => $dados['email_cliente'] ?? null,
            ':telefone_cliente' => $dados['telefone_cliente'] ?? null,
            ':data_reserva' => $dados['data_reserva'],
            ':hora_reserva' => $dados['hora_reserva'],
            ':quantidade_pessoas' => $dados['quantidade_pessoas'],
            ':observacoes' => $dados['observacoes'] ?? null,
            ':status' => $dados['status'] ?? 'pendente',
            ':origem' => $dados['origem'] ?? 'app',
            ':mesa_atribuida' => $dados['mesa_atribuida'] ?? null,
        ]);
    }

    /**
     * Obter reservas por restaurante e data
     */
    public function obterPorData($restaurante_id, $data)
    {
        $query = "SELECT r.*, 
                  CASE WHEN m.id IS NOT NULL THEN m.numero ELSE 'Por atribuir' END as mesa_numero
                  FROM reservas r
                  LEFT JOIN mesas m ON r.mesa_atribuida = m.id
                  WHERE r.restaurante_id = :restaurante_id 
                  AND r.data_reserva = :data_reserva
                  AND r.status NOT IN ('cancelado', 'no-show')
                  ORDER BY r.hora_reserva ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':restaurante_id' => $restaurante_id,
            ':data_reserva' => $data
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Confirmar reserva e enviar notificação
     */
    public function confirmar($reserva_id, $restaurante_id, $telefone = null)
    {
        $query = "UPDATE reservas 
                  SET status = 'confirmado', 
                  atualizado_em = NOW()
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->db->prepare($query);
        $confirmado = $stmt->execute([
            ':id' => $reserva_id,
            ':restaurante_id' => $restaurante_id,
        ]);

        if (!$confirmado) {
            return false;
        }

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $reservaAtual = $this->obter($reserva_id, $restaurante_id);
        if (!$reservaAtual) {
            return false;
        }

        return strtolower((string)($reservaAtual['status'] ?? '')) === 'confirmado';
    }

    /**
     * Obter detalhes da reserva
     */
    public function obter($id, $restaurante_id = null)
    {
        $query = "SELECT * FROM reservas WHERE id = :id";
        $params = [':id' => $id];

        if ($restaurante_id !== null) {
            $query .= " AND restaurante_id = :restaurante_id";
            $params[':restaurante_id'] = $restaurante_id;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Atribuir mesa à reserva
     */
    public function atribuirMesa($reserva_id, $mesa_id, $restaurante_id)
    {
        $query = "UPDATE reservas SET mesa_atribuida = :mesa_id, atualizado_em = NOW() WHERE id = :id AND restaurante_id = :restaurante_id";
        $stmt = $this->db->prepare($query);
        $ok = $stmt->execute([
            ':mesa_id' => $mesa_id,
            ':id' => $reserva_id,
            ':restaurante_id' => $restaurante_id,
        ]);

        return $ok;
    }

    /**
     * Check-in: cliente chegou e está no salão.
     */
    public function marcarCheckIn($reserva_id, $restaurante_id)
    {
        $query = "UPDATE reservas
                  SET status = 'chegou',
                      atualizado_em = NOW()
                  WHERE id = :id AND restaurante_id = :restaurante_id
                    AND status IN ('pendente', 'confirmado', 'chegou')";
        $stmt = $this->db->prepare($query);
        $ok = $stmt->execute([
            ':id' => $reserva_id,
            ':restaurante_id' => $restaurante_id,
        ]);

        if (!$ok) {
            return false;
        }

        if ($stmt->rowCount() > 0) {
            return true;
        }

        $reservaAtual = $this->obter($reserva_id, $restaurante_id);
        return $reservaAtual && strtolower((string)($reservaAtual['status'] ?? '')) === 'chegou';
    }

    /**
     * Após pagamento: reserva sai da lista ativa.
     */
    public function marcarConcluido($reserva_id, $restaurante_id)
    {
        $query = "UPDATE reservas
                  SET status = 'concluido',
                      atualizado_em = NOW()
                  WHERE id = :id AND restaurante_id = :restaurante_id
                    AND status = 'chegou'";
        $stmt = $this->db->prepare($query);
        $ok = $stmt->execute([
            ':id' => $reserva_id,
            ':restaurante_id' => $restaurante_id,
        ]);

        if (!$ok) {
            return false;
        }
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $atual = $this->obter($reserva_id, $restaurante_id);
        return $atual && strtolower((string)($atual['status'] ?? '')) === 'concluido';
    }

    /**
     * Marcar como no-show
     */
    public function marcarNoShow($reserva_id, $restaurante_id)
    {
        $query = "UPDATE reservas SET status = 'no-show', atualizado_em = NOW() WHERE id = :id AND restaurante_id = :restaurante_id";
        $stmt = $this->db->prepare($query);
        $ok = $stmt->execute([
            ':id' => $reserva_id,
            ':restaurante_id' => $restaurante_id,
        ]);

        return $ok && $stmt->rowCount() > 0;
    }

    public function cancelar($reserva_id, $restaurante_id)
    {
        $query = "UPDATE reservas SET status = 'cancelado', atualizado_em = NOW() WHERE id = :id AND restaurante_id = :restaurante_id";
        $stmt = $this->db->prepare($query);
        $ok = $stmt->execute([
            ':id' => $reserva_id,
            ':restaurante_id' => $restaurante_id,
        ]);

        return $ok && $stmt->rowCount() > 0;
    }
}
