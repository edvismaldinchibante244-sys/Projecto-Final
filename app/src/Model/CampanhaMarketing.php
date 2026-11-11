<?php

/**
 * Model: CampanhaMarketing
 * Gerencia campanhas, cupons e fidelidade
 */

namespace App\Model;

class CampanhaMarketing
{
    private $db;

    public function __construct($database)
    {
        $this->db = $database;
    }

    /**
     * Criar campanha de marketing
     */
    public function criarCampanha($dados)
    {
        $query = "INSERT INTO campanhas_marketing
                  (restaurante_id, nome, descricao, tipo, data_inicio, data_fim, desconto_valor, desconto_percentual)
                  VALUES
                  (:restaurante_id, :nome, :descricao, :tipo, :data_inicio, :data_fim, :desconto_valor, :desconto_percentual)";

        $stmt = $this->db->prepare($query);

        return $stmt->execute([
            ':restaurante_id' => $dados['restaurante_id'],
            ':nome' => $dados['nome'],
            ':descricao' => $dados['descricao'] ?? null,
            ':tipo' => $dados['tipo'] ?? 'cupom',
            ':data_inicio' => $dados['data_inicio'] ?? date('Y-m-d'),
            ':data_fim' => $dados['data_fim'],
            ':desconto_valor' => $dados['desconto_valor'] ?? 0,
            ':desconto_percentual' => $dados['desconto_percentual'] ?? 0
        ]);
    }

    /**
     * Criar cupom de desconto
     */
    public function criarCupom($dados)
    {
        $query = "INSERT INTO cupons_desconto
                  (restaurante_id, campanha_id, codigo, descricao, tipo, valor_desconto, 
                   quantidade_total, data_inicio, data_fim, cliente_id)
                  VALUES
                  (:restaurante_id, :campanha_id, :codigo, :descricao, :tipo, :valor_desconto,
                   :quantidade_total, :data_inicio, :data_fim, :cliente_id)";

        $stmt = $this->db->prepare($query);

        return $stmt->execute([
            ':restaurante_id' => $dados['restaurante_id'],
            ':campanha_id' => $dados['campanha_id'] ?? null,
            ':codigo' => strtoupper($dados['codigo']),
            ':descricao' => $dados['descricao'] ?? null,
            ':tipo' => $dados['tipo'] ?? 'percentual',
            ':valor_desconto' => $dados['valor_desconto'],
            ':quantidade_total' => $dados['quantidade_total'] ?? 9999,
            ':data_inicio' => $dados['data_inicio'] ?? date('Y-m-d'),
            ':data_fim' => $dados['data_fim'],
            ':cliente_id' => $dados['cliente_id'] ?? null
        ]);
    }

    /**
     * Validar e aplicar cupom
     */
    public function validarCupom($restaurante_id, $codigo)
    {
        $query = "SELECT * FROM cupons_desconto 
                  WHERE restaurante_id = :restaurante_id 
                  AND codigo = :codigo 
                  AND ativo = TRUE
                  AND data_inicio <= CURDATE()
                  AND data_fim >= CURDATE()
                  AND quantidade_usada < quantidade_total";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':restaurante_id' => $restaurante_id,
            ':codigo' => strtoupper($codigo)
        ]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Usar cupom (incrementar contador)
     */
    public function usarCupom($cupom_id)
    {
        $query = "UPDATE cupons_desconto 
                  SET quantidade_usada = quantidade_usada + 1 
                  WHERE id = :id";

        $stmt = $this->db->prepare($query);
        return $stmt->execute([':id' => $cupom_id]);
    }

    /**
     * Gerenciar pontos de fidelidade
     */
    public function creditarPontos($cliente_id, $restaurante_id, $quantidade, $motivo, $venda_id = null)
    {
        // Obter ou criar registro de pontos
        $query_check = "SELECT id FROM pontos_fidelidade 
                        WHERE cliente_id = :cliente_id AND restaurante_id = :restaurante_id";
        $stmt_check = $this->db->prepare($query_check);
        $stmt_check->execute([
            ':cliente_id' => $cliente_id,
            ':restaurante_id' => $restaurante_id
        ]);

        $pontos = $stmt_check->fetch(\PDO::FETCH_ASSOC);

        if (!$pontos) {
            $query_insert = "INSERT INTO pontos_fidelidade (cliente_id, restaurante_id, saldo_pontos)
                            VALUES (:cliente_id, :restaurante_id, :quantidade)";
            $stmt_insert = $this->db->prepare($query_insert);
            $stmt_insert->execute([
                ':cliente_id' => $cliente_id,
                ':restaurante_id' => $restaurante_id,
                ':quantidade' => $quantidade
            ]);
            $pontos_id = $this->db->lastInsertId();
        } else {
            $pontos_id = $pontos['id'];
            $query_update = "UPDATE pontos_fidelidade 
                            SET saldo_pontos = saldo_pontos + :quantidade,
                            pontos_totais_ganhos = pontos_totais_ganhos + :quantidade
                            WHERE id = :id";
            $stmt_update = $this->db->prepare($query_update);
            $stmt_update->execute([
                ':quantidade' => $quantidade,
                ':id' => $pontos_id
            ]);
        }

        // Registrar transação
        $query_trans = "INSERT INTO transacoes_pontos (pontos_id, tipo, quantidade, motivo, referencia_venda)
                       VALUES (:pontos_id, 'ganho', :quantidade, :motivo, :venda_id)";
        $stmt_trans = $this->db->prepare($query_trans);
        return $stmt_trans->execute([
            ':pontos_id' => $pontos_id,
            ':quantidade' => $quantidade,
            ':motivo' => $motivo,
            ':venda_id' => $venda_id
        ]);
    }

    /**
     * Usar pontos
     */
    public function usarPontos($cliente_id, $restaurante_id, $quantidade, $motivo)
    {
        $query = "UPDATE pontos_fidelidade 
                  SET saldo_pontos = saldo_pontos - :quantidade,
                  pontos_totais_gastos = pontos_totais_gastos + :quantidade
                  WHERE cliente_id = :cliente_id 
                  AND restaurante_id = :restaurante_id
                  AND saldo_pontos >= :quantidade";

        $stmt = $this->db->prepare($query);
        $usado = $stmt->execute([
            ':quantidade' => $quantidade,
            ':cliente_id' => $cliente_id,
            ':restaurante_id' => $restaurante_id
        ]);

        if ($usado) {
            // Registrar transação
            $query_trans = "SELECT id FROM pontos_fidelidade 
                           WHERE cliente_id = :cliente_id AND restaurante_id = :restaurante_id";
            $stmt_trans = $this->db->prepare($query_trans);
            $stmt_trans->execute([
                ':cliente_id' => $cliente_id,
                ':restaurante_id' => $restaurante_id
            ]);
            $pontos = $stmt_trans->fetch(\PDO::FETCH_ASSOC);

            $query_log = "INSERT INTO transacoes_pontos (pontos_id, tipo, quantidade, motivo)
                         VALUES (:pontos_id, 'gasto', :quantidade, :motivo)";
            $stmt_log = $this->db->prepare($query_log);
            $stmt_log->execute([
                ':pontos_id' => $pontos['id'],
                ':quantidade' => $quantidade,
                ':motivo' => $motivo
            ]);
        }

        return $usado;
    }

    /**
     * Obter saldo de pontos do cliente
     */
    public function obterSaldoPontos($cliente_id, $restaurante_id)
    {
        $query = "SELECT saldo_pontos, nivel_cliente FROM pontos_fidelidade 
                  WHERE cliente_id = :cliente_id AND restaurante_id = :restaurante_id";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':cliente_id' => $cliente_id,
            ':restaurante_id' => $restaurante_id
        ]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Obter campanhas ativas
     */
    public function obterCampanhasAtivas($restaurante_id)
    {
        $query = "SELECT * FROM campanhas_marketing 
                  WHERE restaurante_id = :restaurante_id 
                  AND ativo = TRUE
                  AND data_inicio <= CURDATE()
                  AND data_fim >= CURDATE()
                  ORDER BY data_fim ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':restaurante_id' => $restaurante_id]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
