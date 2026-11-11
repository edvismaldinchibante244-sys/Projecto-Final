<?php

/*
 
  SERVIÇO DE VENDAS - SERVICE LAYER
 
*/

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Model/Venda.php';
require_once __DIR__ . '/../Model/Produto.php';

class VendaService
{
    private $db;
    private $venda;
    private $produto;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->venda = new Venda($this->db);
        $this->produto = new Produto($this->db);
    }

    /**
     * Listar vendas
     */
    public function listar($restaurante_id, $data_inicio = null, $data_fim = null)
    {
        $stmt = $this->venda->listar($restaurante_id, $data_inicio, $data_fim);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Buscar venda por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        return $this->venda->buscarPorId($id, $restaurante_id);
    }

    /**
     * Criar nova venda
     */
    public function criar($dados)
    {
        $this->venda->restaurante_id = $dados['restaurante_id'];
        $this->venda->usuario_id = $dados['usuario_id'];
        $this->venda->caixa_id = $dados['caixa_id'];
        $this->venda->mesa_id = $dados['mesa_id'] ?? null;
        $this->venda->total = $dados['total'];
        $this->venda->desconto = $dados['desconto'] ?? 0;
        $this->venda->total_final = $dados['total_final'];
        $this->venda->forma_pagamento = $dados['forma_pagamento'];
        // Fluxo correto: venda criada como PENDENTE, só pode ser paga após produção
        $this->venda->status = 'PENDENTE';
        $this->venda->prepararNumeracaoFatura($dados['restaurante_id']);
        $this->venda->numero_fatura = $this->venda->gerarNumeroFatura($dados['restaurante_id']);

        $venda_id = $this->venda->criar();

        if ($venda_id) {
            // Adicionar itens e atualizar estoque
            if (isset($dados['itens']) && is_array($dados['itens'])) {
                foreach ($dados['itens'] as $item) {
                    $this->venda->adicionarItem(
                        $venda_id,
                        $item['produto_id'],
                        $item['quantidade'],
                        $item['preco_unitario'],
                        $item['promocao_id'] ?? null
                    );

                    // Atualizar estoque (saída)
                    $this->produto->atualizarEstoque($item['produto_id'], $dados['restaurante_id'], $item['quantidade'], 'SAIDA');
                }
            }

            return array(
                "success" => true,
                "id" => $venda_id,
                "numero_fatura" => $this->venda->numero_fatura,
                "message" => "Venda realizada com sucesso!"
            );
        }

        return array("success" => false, "message" => "Erro ao realizar venda.");
    }

    /**
     * Cancelar venda
     */
    public function cancelar($id, $restaurante_id)
    {
        if ($this->venda->cancelar($id, $restaurante_id)) {
            return array("success" => true, "message" => "Venda cancelada com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao cancelar venda.");
    }

    /**
     * Buscar itens da venda
     */
    public function buscarItens($venda_id)
    {
        $stmt = $this->venda->buscarItens($venda_id);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vendas de hoje
     */
    public function vendasHoje($restaurante_id)
    {
        return $this->venda->vendasHoje($restaurante_id);
    }

    /**
     * Contar vendas de hoje
     */
    public function contarVendasHoje($restaurante_id)
    {
        return $this->venda->contarVendasHoje($restaurante_id);
    }

    /**
     * Últimas vendas
     */
    public function ultimasVendas($restaurante_id, $limite = 10)
    {
        $stmt = $this->venda->ultimasVendas($restaurante_id, $limite);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vendas por dia
     */
    public function vendasPorDia($restaurante_id, $dias = 7)
    {
        $stmt = $this->venda->vendasPorDia($restaurante_id, $dias);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
