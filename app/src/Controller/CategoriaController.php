<?php

/*
   Controller de Categoria
   Arquitetura N-Tier
 */

require_once __DIR__ . '/../Model/Categoria.php';
require_once __DIR__ . '/../Service/CategoriaService.php';

class CategoriaController
{
    private $service;

    public function __construct()
    {
        $this->service = new CategoriaService();
    }

    /**
     * Listar todas as categorias
     */
    public function listar($restaurante_id)
    {
        return $this->service->listar($restaurante_id);
    }

    /**
     * Cadastrar nova categoria
     */
    public function cadastrar($dados)
    {
        return $this->service->cadastrar($dados);
    }

    /**
     * Inativar categoria
     */
    public function deletar($dados)
    {
        return $this->service->deletar($dados['id'], $dados['restaurante_id']);
    }
}
