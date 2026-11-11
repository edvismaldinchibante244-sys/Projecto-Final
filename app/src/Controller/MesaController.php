<?php

/*
   Controller de Mesas
   Arquitetura N-Tier
 */

require_once __DIR__ . '/../Model/Mesa.php';
require_once __DIR__ . '/../Service/MesaService.php';

class MesaController
{
    private $service;

    public function __construct()
    {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();

        $model = new Mesa($db);
        $this->service = new MesaService($model);
    }

    /**
     * Listar mesas
     */
    public function listar($restaurante_id)
    {
        return $this->service->listar($restaurante_id);
    }

    /**
     * Listar mesas livres
     */
    public function mesasLivres($restaurante_id)
    {
        return $this->service->mesasLivres($restaurante_id);
    }

    /**
     * Atualizar status da mesa
     */
    public function atualizarStatus($id, $restaurante_id, $status)
    {
        return $this->service->atualizarStatus($id, $restaurante_id, $status);
    }

    /**
     * Cadastrar nova mesa
     */
    public function cadastrar($dados, $restaurante_id, $plano_restaurante_id = 0)
    {
        return $this->service->cadastrar($dados, $restaurante_id, $plano_restaurante_id);
    }
}
