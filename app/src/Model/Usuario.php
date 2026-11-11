<?php

/**
 * ============================================
 * CLASSE DE USUÁRIOS - MODEL
 * ============================================
 */

class Usuario
{
    private $conn;
    private $table_name = "usuarios";

    public $id;
    public $restaurante_id;
    public $nome;
    public $email;
    public $senha;
    public $perfil;
    public $ativo;
    public $foto;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    private function passwordAlgorithm()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Listar todos os usuários do restaurante
     */
    public function listar($restaurante_id)
    {
        $query = "SELECT id, restaurante_id, nome, email, perfil, ativo, foto, criado_em
                  FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id
                  ORDER BY nome ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Buscar usuário por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        $query = "SELECT id, restaurante_id, nome, email, perfil, ativo, foto, criado_em
                  FROM " . $this->table_name . " 
                  WHERE id = :id AND restaurante_id = :restaurante_id 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cadastrar novo usuário
     */
    public function cadastrar()
    {
        $query = "INSERT INTO " . $this->table_name . "
                  SET restaurante_id = :restaurante_id,
                      nome = :nome,
                      email = :email,
                      email_lookup = :email_lookup,
                      senha = :senha,
                      perfil = :perfil,
                      ativo = 1,
                      foto = :foto";

        $stmt = $this->conn->prepare($query);

        // Hash da senha
        $senha_hash = password_hash((string)$this->senha, $this->passwordAlgorithm());
        $email_encrypted = personal_data_encrypt((string)$this->email);
        $email_lookup = personal_data_email_lookup((string)$this->email);

        $stmt->bindParam(":restaurante_id", $this->restaurante_id);
        $stmt->bindParam(":nome", $this->nome);
        $stmt->bindParam(":email", $email_encrypted);
        $stmt->bindParam(":email_lookup", $email_lookup);
        $stmt->bindParam(":senha", $senha_hash);
        $stmt->bindParam(":perfil", $this->perfil);
        $stmt->bindParam(":foto", $this->foto);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Editar usuário
     */
    public function editar()
    {
        $query = "UPDATE " . $this->table_name . "
                  SET nome = :nome,
                      email = :email,
                      email_lookup = :email_lookup,
                      perfil = :perfil,
                      ativo = :ativo,
                      foto = :foto
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nome", $this->nome);
        $email_encrypted = personal_data_encrypt((string)$this->email);
        $email_lookup = personal_data_email_lookup((string)$this->email);
        $stmt->bindParam(":email", $email_encrypted);
        $stmt->bindParam(":email_lookup", $email_lookup);
        $stmt->bindParam(":perfil", $this->perfil);
        $stmt->bindParam(":ativo", $this->ativo);
        $stmt->bindParam(":foto", $this->foto);
        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":restaurante_id", $this->restaurante_id);

        return $stmt->execute();
    }

    /**
     * Inativar usuário, preservando histórico e referências de auditoria.
     */
    public function deletar($id, $restaurante_id)
    {
        $query = "UPDATE " . $this->table_name . "
                  SET ativo = 0, tentativas_login = 0, bloqueado_ate = NULL
                  WHERE id = :id AND restaurante_id = :restaurante_id AND ativo = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        return $stmt->execute();
    }

    /**
     * Atualizar senha
     */
    public function atualizarSenha($id, $senha_nova, $restaurante_id)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET senha = :senha 
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->conn->prepare($query);

        $senha_hash = password_hash((string)$senha_nova, $this->passwordAlgorithm());

        $stmt->bindParam(":senha", $senha_hash);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        return $stmt->execute();
    }

    /**
     * Contar total de usuários ativos
     */
    public function contarAtivos($restaurante_id)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . "
                  WHERE restaurante_id = :restaurante_id AND ativo = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'] ?? 0;
    }
}
