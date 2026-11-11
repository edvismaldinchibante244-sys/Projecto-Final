<?php

/**
 * ============================================
 * CLASSE DE MESAS - MODEL
 * ============================================
 */

class Mesa
{
    private $conn;
    private $table_name = "mesas";

    public $id;
    public $restaurante_id;
    public $numero;
    public $status;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Listar todas as mesas
     */
    public function listar($restaurante_id)
    {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  ORDER BY 
                    CASE WHEN numero REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                    CASE WHEN numero REGEXP '^[0-9]+$' THEN CAST(numero AS UNSIGNED) ELSE 999999 END,
                    numero ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Mesas livres
     */
    public function mesasLivres($restaurante_id)
    {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND status = 'LIVRE'
                  ORDER BY 
                    CASE WHEN numero REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
                    CASE WHEN numero REGEXP '^[0-9]+$' THEN CAST(numero AS UNSIGNED) ELSE 999999 END,
                    numero ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Atualizar status da mesa
     */
    public function atualizarStatus($id, $restaurante_id, $status)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET status = :status 
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        return $stmt->execute();
    }
}
