<?php

class Caixa
{
    private $conn;
    private $table_name = "caixas";

    // Propriedades para abrir caixa
    public $restaurante_id;
    public $usuario_id;
    public $saldo_inicial;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function buscarAberto($restaurante_id)
    {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND status = 'ABERTO'
                  ORDER BY data_abertura DESC
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function abrir()
    {
        $query = "INSERT INTO " . $this->table_name . "
                  SET restaurante_id = :restaurante_id,
                      usuario_id = :usuario_id,
                      saldo_inicial = :saldo_inicial,
                      status = 'ABERTO',
                      data_abertura = NOW()";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $this->restaurante_id);
        $stmt->bindParam(":usuario_id", $this->usuario_id);
        $stmt->bindParam(":saldo_inicial", $this->saldo_inicial);
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    public function fechar($id, $restaurante_id, $saldo_final)
    {
        $query = "UPDATE " . $this->table_name . "
                  SET saldo_final = :saldo_final,
                      status = 'FECHADO',
                      data_fechamento = NOW()
                  WHERE id = :id AND restaurante_id = :restaurante_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":saldo_final", $saldo_final);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        return $stmt->execute();
    }

    public function listar($restaurante_id, $limit = 15)
    {
        $query = "SELECT c.*, u.nome as usuario_nome
                  FROM " . $this->table_name . " c
                  LEFT JOIN usuarios u ON c.usuario_id = u.id
                  WHERE c.restaurante_id = :restaurante_id
                  ORDER BY c.data_abertura DESC
                  LIMIT :limit";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function totalVendas($caixa_id)
    {
        $query = "SELECT COALESCE(SUM(total_final), 0) as total 
                  FROM vendas 
                  WHERE caixa_id = :caixa_id AND status = 'PAGO'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":caixa_id", $caixa_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return floatval($row['total']);
    }
}
