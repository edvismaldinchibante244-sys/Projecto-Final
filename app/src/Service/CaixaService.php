<?php

/*
   
   Service de Caixa - FIX DEFINITIVO

*/
class CaixaService
{
    private $db;
    private $caixa;

    public function __construct($caixa = null)
    {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../Model/Caixa.php';
        $database = new Database();
        $this->db = $database->getConnection();
        $this->caixa = $caixa ?: new Caixa($this->db);
    }

    public function buscarAberto($restaurante_id)
    {
        return $this->caixa->buscarAberto($restaurante_id);
    }

    public function abrir($dados)
    {
        $existente = $this->caixa->buscarAberto($dados['restaurante_id']);
        if ($existente) {
            return ['success' => false, 'message' => 'Caixa já aberto hoje'];
        }

        $this->caixa->restaurante_id = $dados['restaurante_id'];
        $this->caixa->usuario_id = $dados['usuario_id'] ?? $_SESSION['usuario_id'];
        $this->caixa->saldo_inicial = $dados['abertura'];

        $id = $this->caixa->abrir();
        if ($id) {
            return ['success' => true, 'message' => 'Caixa aberto com sucesso!', 'id' => $id];
        }
        return ['success' => false, 'message' => 'Erro ao abrir caixa - verifique banco'];
    }

    public function fechar($restaurante_id, $fechamento)
    {
        $aberto = $this->caixa->buscarAberto($restaurante_id);
        if (!$aberto) {
            return ['success' => false, 'message' => 'Nenhum caixa aberto encontrado'];
        }

        $sucesso = $this->caixa->fechar($aberto['id'], $restaurante_id, $fechamento);
        if ($sucesso) {
            return ['success' => true, 'message' => 'Caixa fechado com sucesso!'];
        }
        return ['success' => false, 'message' => 'Erro ao fechar caixa'];
    }

    public function listar($restaurante_id, $limit = 15)
    {
        return $this->caixa->listar($restaurante_id, $limit);
    }

    public function totalVendas($caixa_id)
    {
        return $this->caixa->totalVendas($caixa_id);
    }
}
