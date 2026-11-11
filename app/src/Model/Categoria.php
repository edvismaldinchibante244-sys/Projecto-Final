<?php


 
  /*CLASSE DE CATEGORIAS - MODEL*/
  
 

class Categoria
{
    private $conn;
    private $table_name = "categorias";

    public $id;
    public $restaurante_id;
    public $parent_id;
    public $nome;
    public $descricao;
    public $ativo;

    public function __construct($db)
    {
        $this->conn = $db;
        if (is_file(__DIR__ . '/../config/categoria_schema.php')) {
            require_once __DIR__ . '/../config/categoria_schema.php';
            if (function_exists('categoria_schema_garantir')) {
                categoria_schema_garantir($this->conn);
            }
        }
    }

    /**
     * Listar todas as categorias
     */
    public function listar($restaurante_id)
    {
        $query = "SELECT c.*, p.nome AS parent_nome
                  FROM " . $this->table_name . " c
                  LEFT JOIN " . $this->table_name . " p ON p.id = c.parent_id
                  WHERE c.restaurante_id = :restaurante_id
                    AND COALESCE(c.ativo, 1) = 1
                  ORDER BY COALESCE(p.nome, c.nome) ASC, c.parent_id IS NOT NULL ASC, c.nome ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Contar categorias raiz ou subcategorias
     */
    public function contar($restaurante_id, $apenasSubcategorias = false)
    {
        if ($apenasSubcategorias) {
            $query = "SELECT COUNT(*) FROM " . $this->table_name . "
                      WHERE restaurante_id = :restaurante_id
                        AND parent_id IS NOT NULL
                        AND COALESCE(ativo, 1) = 1";
        } else {
            $query = "SELECT COUNT(*) FROM " . $this->table_name . "
                      WHERE restaurante_id = :restaurante_id
                        AND parent_id IS NULL
                        AND COALESCE(ativo, 1) = 1";
        }
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Cadastrar nova categoria
     */
    public function cadastrar()
    {
        $query = "INSERT INTO " . $this->table_name . "
                  SET restaurante_id = :restaurante_id,
                      parent_id = :parent_id,
                      nome = :nome,
                      descricao = :descricao,
                      ativo = :ativo";

        $stmt = $this->conn->prepare($query);

        $parentId = ($this->parent_id !== null && (int)$this->parent_id > 0) ? (int)$this->parent_id : null;

        $stmt->bindParam(":restaurante_id", $this->restaurante_id);
        if ($parentId === null) {
            $stmt->bindValue(":parent_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":parent_id", $parentId, PDO::PARAM_INT);
        }
        $stmt->bindParam(":nome", $this->nome);
        $stmt->bindParam(":descricao", $this->descricao);
        $stmt->bindParam(":ativo", $this->ativo);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Inativar categoria
     */
    public function deletar($id, $restaurante_id)
    {
        try {
            $query = "UPDATE " . $this->table_name . "
                      SET ativo = 0
                      WHERE id = :id AND restaurante_id = :restaurante_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->bindParam(":restaurante_id", $restaurante_id, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (Exception $e) {
            return false;
        }
    }
}
