<?php

/*

   SERVIÇO DE CATEGORIAS - SERVICE LAYER
 
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Model/Categoria.php';

class CategoriaService
{
    private $db;
    private $categoria;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->categoria = new Categoria($this->db);
    }

    /**
     * Listar categorias
     */
    public function listar($restaurante_id)
    {
        $stmt = $this->categoria->listar($restaurante_id);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Contar categorias raiz / subcategorias
     */
    public function contar($restaurante_id, $apenasSubcategorias = false)
    {
        return $this->categoria->contar($restaurante_id, $apenasSubcategorias);
    }

    /**
     * Cadastrar categoria (ou subcategoria se parent_id informado)
     */
    public function cadastrar($dados)
    {
        try {
            $parentId = isset($dados['parent_id']) ? (int)$dados['parent_id'] : 0;
            $this->categoria->restaurante_id = $dados['restaurante_id'];
            $this->categoria->parent_id = $parentId > 0 ? $parentId : null;
            $this->categoria->nome = $dados['nome'];
            $this->categoria->descricao = $dados['descricao'] ?? '';
            $this->categoria->ativo = $dados['ativo'] ?? 1;

            $id = $this->categoria->cadastrar();

            if ($id) {
                $label = $parentId > 0 ? 'Subcategoria' : 'Categoria';
                $nomeExibicao = $dados['nome'];
                if ($parentId > 0 && !empty($dados['parent_nome'])) {
                    $nomeExibicao = $dados['parent_nome'] . ' › ' . $dados['nome'];
                }
                return array(
                    "success" => true,
                    "message" => $label . " cadastrada com sucesso!",
                    "id" => $id,
                    "categoria" => array(
                        "id" => $id,
                        "nome" => $nomeExibicao,
                        "parent_id" => $parentId > 0 ? $parentId : null
                    )
                );
            }

            return array("success" => false, "message" => "Erro ao cadastrar categoria.");
        } catch (Exception $e) {
            return array("success" => false, "message" => "Erro: " . $e->getMessage());
        }
    }

    /**
     * Inativar categoria
     */
    public function deletar($id, $restaurante_id)
    {
        try {
            if ($this->categoria->deletar($id, $restaurante_id)) {
                return array("success" => true, "message" => "Categoria inativada com sucesso!");
            }
            return array("success" => false, "message" => "Erro ao inativar categoria.");
        } catch (Exception $e) {
            return array("success" => false, "message" => "Erro: " . $e->getMessage());
        }
    }
}
