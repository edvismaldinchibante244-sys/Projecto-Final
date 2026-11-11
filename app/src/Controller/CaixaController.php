<?php

/*
   Controller de Caixa
   Arquitetura N-Tier
 */

require_once __DIR__ . '/../Model/Caixa.php';
require_once __DIR__ . '/../Service/CaixaService.php';

class CaixaController
{
    private $service;

    public function __construct(?PDO $db = null)
    {
        if ($db === null) {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
        }

        $model = new Caixa($db);
        $this->service = new CaixaService($model);
    }

    /**
     * Buscar caixa aberto
     */
    public function buscarAberto($restaurante_id)
    {
        try {
            return $this->service->buscarAberto($restaurante_id);
        } catch (Exception $e) {
            error_log("Erro buscarAberto: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Abrir caixa
     */
    public function abrir($dados)
    {
        try {
            return $this->service->abrir($dados);
        } catch (Exception $e) {
            error_log("Erro abrir caixa: " . $e->getMessage());
            return array("success" => false, "message" => "Erro: " . $e->getMessage());
        }
    }

    /**
     * Fechar caixa
     */
    public function fechar($restaurante_id, $fechamento)
    {
        try {
            return $this->service->fechar($restaurante_id, $fechamento);
        } catch (Exception $e) {
            error_log("Erro fechar caixa: " . $e->getMessage());
            return array("success" => false, "message" => "Erro: " . $e->getMessage());
        }
    }

    /**
     * Listar caixas
     */
    public function listar($restaurante_id, $data_inicio = null, $data_fim = null)
    {
        return $this->service->listar($restaurante_id, $data_inicio, $data_fim);
    }
}
