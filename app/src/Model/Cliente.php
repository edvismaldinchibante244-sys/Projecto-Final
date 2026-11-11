<?php

/**
 * Model: Cliente
 * Gerencia clientes e CRM
 */

namespace App\Model;

class Cliente
{
    private $db;

    public function __construct($database)
    {
        $this->db = $database;
    }

    /**
     * Criar/Registrar novo cliente
     */
    public function criar($dados)
    {
        $hoje = date('Y-m-d');
        $query = "INSERT INTO clientes 
                  (restaurante_id, nome, email, telefone, data_nascimento, tipo_cliente, 
                   endereco, cidade, provincia, pais, data_primeira_visita, data_ultima_visita)
                  VALUES 
                  (:restaurante_id, :nome, :email, :telefone, :data_nascimento, :tipo_cliente,
                   :endereco, :cidade, :provincia, :pais, :data_primeira_visita, :data_ultima_visita)";

        $stmt = $this->db->prepare($query);

        return $stmt->execute([
            ':restaurante_id' => $dados['restaurante_id'],
            ':nome' => $dados['nome'],
            ':email' => $dados['email'] ?? null,
            ':telefone' => $dados['telefone'] ?? null,
            ':data_nascimento' => $dados['data_nascimento'] ?? null,
            ':tipo_cliente' => $dados['tipo_cliente'] ?? 'comum',
            ':endereco' => $dados['endereco'] ?? null,
            ':cidade' => $dados['cidade'] ?? null,
            ':provincia' => $dados['provincia'] ?? null,
            ':pais' => $dados['pais'] ?? null,
            ':data_primeira_visita' => $hoje,
            ':data_ultima_visita' => $hoje,
        ]);
    }

    /**
     * Atualizar informações do cliente
     */
    public function atualizar($id, $dados)
    {
        $campos = ['nome', 'email', 'telefone', 'tipo_cliente', 'endereco', 'cidade', 'provincia', 'pais'];
        $set = [];
        $params = [':id' => $id];

        foreach ($campos as $campo) {
            if (isset($dados[$campo])) {
                $set[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $dados[$campo];
            }
        }

        if (empty($set)) return false;

        $query = "UPDATE clientes SET " . implode(', ', $set) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }

    /**
     * Obter cliente por ID ou telefone
     */
    public function obter($id = null, $telefone = null, $email = null)
    {
        if ($id) {
            $query = "SELECT * FROM clientes WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':id' => $id]);
        } elseif ($telefone) {
            $query = "SELECT * FROM clientes WHERE telefone = :telefone LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':telefone' => $telefone]);
        } elseif ($email) {
            $query = "SELECT * FROM clientes WHERE email = :email LIMIT 1";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':email' => $email]);
        }

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Obter clientes VIP
     */
    public function obterVips($restaurante_id, $limite = 10)
    {
        $query = "SELECT * FROM clientes 
                  WHERE restaurante_id = :restaurante_id 
                  AND tipo_cliente = 'vip'
                  AND ativo = TRUE
                  ORDER BY ticket_medio DESC
                  LIMIT :limite";

        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':restaurante_id', $restaurante_id);
        $stmt->bindParam(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Calcular estatísticas do cliente
     */
    public function calcularEstatisticas($cliente_id)
    {
        $query = "SELECT 
                  COUNT(DISTINCT DATE(chc.data_compra)) as total_visitas,
                  SUM(chc.valor_total) as valor_total_gasto,
                  AVG(chc.valor_total) as ticket_medio,
                  MAX(chc.data_compra) as data_ultima_visita,
                  SUM(chc.pontos_ganhos) as pontos_totais
                  FROM cliente_historico_compras chc
                  WHERE chc.cliente_id = :cliente_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':cliente_id' => $cliente_id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Bloquear cliente (por no-shows)
     */
    public function bloquear($cliente_id, $motivo)
    {
        $query = "UPDATE clientes 
                  SET bloqueado = TRUE, motivo_bloqueio = :motivo 
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);
        return $stmt->execute([
            ':id' => $cliente_id,
            ':motivo' => $motivo
        ]);
    }

    /**
     * Desbloquear cliente
     */
    public function desbloquear($cliente_id)
    {
        $query = "UPDATE clientes 
                  SET bloqueado = FALSE, motivo_bloqueio = NULL
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);
        return $stmt->execute([':id' => $cliente_id]);
    }
}
