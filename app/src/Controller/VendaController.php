<?php

/*
 
  CONTROLADOR DE VENDAS - CONTROLLER LAYER
 
*/

require_once __DIR__ . '/../Service/VendaService.php';

class VendaController
{
    private $vendaService;

    public function __construct()
    {
        $this->vendaService = new VendaService();
    }

    /**
     * Listar vendas
     */
    public function listar($restaurante_id, $data_inicio = null, $data_fim = null)
    {
        return $this->vendaService->listar($restaurante_id, $data_inicio, $data_fim);
    }

    /**
     * Buscar venda por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        return $this->vendaService->buscarPorId($id, $restaurante_id);
    }

    /**
     * Criar venda
     */
    public function criar($dados)
    {
        return $this->vendaService->criar($dados);
    }

    /**
     * Cancelar venda
     */
    public function cancelar($id, $restaurante_id)
    {
        return $this->vendaService->cancelar($id, $restaurante_id);
    }

    /**
     * Buscar itens da venda
     */
    public function buscarItens($venda_id)
    {
        return $this->vendaService->buscarItens($venda_id);
    }

    /**
     * Vendas de hoje
     */
    public function vendasHoje($restaurante_id)
    {
        return $this->vendaService->vendasHoje($restaurante_id);
    }

    /**
     * Contar vendas de hoje
     */
    public function contarVendasHoje($restaurante_id)
    {
        return $this->vendaService->contarVendasHoje($restaurante_id);
    }

    /**
     * Últimas vendas
     */
    public function ultimasVendas($restaurante_id, $limite = 10)
    {
        return $this->vendaService->ultimasVendas($restaurante_id, $limite);
    }

    /**
     * Vendas por dia
     */
    public function vendasPorDia($restaurante_id, $dias = 7)
    {
        return $this->vendaService->vendasPorDia($restaurante_id, $dias);
    }
}
