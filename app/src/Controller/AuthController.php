<?php

/*

   CONTROLADOR DE AUTENTICAÇÃO - CONTROLLER LAYER
 
 */

require_once __DIR__ . '/../Service/AuthService.php';

class AuthController
{
    private $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * Realizar login
     */
    public function login($email, $senha)
    {
        return $this->authService->login($email, $senha);
    }

    /**
     * Cadastrar usuário
     */
    public function cadastrar($dados)
    {
        return $this->authService->cadastrar($dados);
    }

    /**
     * Atualizar senha
     */
    public function atualizarSenha($usuario_id, $senha_nova)
    {
        return $this->authService->atualizarSenha($usuario_id, $senha_nova);
    }
}
