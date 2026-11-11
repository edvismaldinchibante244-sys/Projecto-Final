<?php

/*
   SERVIÇO DE AUTENTICAÇÃO - SERVICE LAYER
 
*/

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Model/Auth.php';

class AuthService
{
    private $db;
    private $auth;

    public function __construct()
    {
        try {
            $database = new Database();
            $this->db = $database->getConnection();
            if (!$this->db) {
                throw new Exception("Falha ao conectar ao banco de dados");
            }
            $this->auth = new Auth($this->db);
        } catch (Exception $e) {
            error_log("AuthService Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Realizar login
     */
    public function login($email, $senha)
    {
        try {
            if (empty($email) || empty($senha)) {
                return array("success" => false, "message" => "Preencha todos os campos.");
            }

            $resultado = $this->auth->login($email, $senha);

            if ($resultado['success']) {
                $userData = $resultado['data'] ?? $resultado['usuario'] ?? null;
                if (!is_array($userData)) {
                    return array("success" => false, "message" => "Resposta de autenticacao invalida.");
                }

                return array(
                    "success" => true,
                    "data" => $userData,
                    "message" => "Login realizado com sucesso!"
                );
            }

            return $resultado;
        } catch (Exception $e) {
            error_log("Login Service Error: " . $e->getMessage());
            return array("success" => false, "message" => "Erro ao processar login");
        }
    }

    /**
     * Cadastrar novo usuário
     */
    public function cadastrar($dados)
    {
        // Verificar se email já existe
        if ($this->auth->emailExiste($dados['email'])) {
            return array("success" => false, "message" => "Email já está em uso.");
        }

        $this->auth->restaurante_id = $dados['restaurante_id'];
        $this->auth->nome = $dados['nome'];
        $this->auth->email = $dados['email'];
        $this->auth->senha = $dados['senha'];
        $this->auth->perfil = $dados['perfil'] ?? 'FUNCIONARIO';
        $this->auth->foto = $dados['foto'] ?? null;

        if ($this->auth->cadastrar()) {
            return array("success" => true, "message" => "Usuário cadastrado com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao cadastrar usuário.");
    }

    /**
     * Atualizar senha
     */
    public function atualizarSenha($usuario_id, $senha_nova)
    {
        if ($this->auth->atualizarSenha($usuario_id, $senha_nova)) {
            return array("success" => true, "message" => "Senha atualizada com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao atualizar senha.");
    }

    /**
     * Verificar se email existe
     */
    public function emailExiste($email)
    {
        return $this->auth->emailExiste($email);
    }
}
