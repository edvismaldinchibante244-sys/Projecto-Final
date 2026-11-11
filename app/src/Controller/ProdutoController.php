<?php

/*
 
   CONTROLADOR DE PRODUTOS - CONTROLLER LAYER
 
*/

require_once __DIR__ . '/../Service/ProdutoService.php';

class ProdutoController
{
    private $produtoService;

    public function __construct()
    {
        $this->produtoService = new ProdutoService();
    }

    /**
     * Listar produtos
     */
    public function listar($restaurante_id)
    {
        return $this->produtoService->listar($restaurante_id);
    }

    /**
     * Buscar produto por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        return $this->produtoService->buscarPorId($id, $restaurante_id);
    }

    /**
     * Cadastrar produto
     */
    public function cadastrar($dados)
    {
        $avisoImagem = null;
        if (isset($_FILES['imagem'])) {
            $uploadMsg = $this->produtoService->mensagemErroUpload($_FILES['imagem']);
            if ($uploadMsg !== null) {
                return ['success' => false, 'message' => $uploadMsg];
            }
            $temFicheiro = (($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
                && (int)($_FILES['imagem']['size'] ?? 0) > 0;
            if ($temFicheiro) {
                $path = $this->produtoService->processarImagem($_FILES['imagem']);
                if ($path) {
                    $dados['imagem'] = $path;
                } else {
                    // Não bloqueia o cadastro: grava produto sem foto
                    $avisoImagem = 'Produto guardado, mas a imagem não pôde ser processada.';
                }
            }
        }

        $resultado = $this->produtoService->cadastrar($dados);
        if (!empty($resultado['success']) && $avisoImagem) {
            $resultado['message'] = trim(($resultado['message'] ?? 'Produto cadastrado.') . ' ' . $avisoImagem);
            $resultado['warning'] = $avisoImagem;
        }
        return $resultado;
    }

    /**
     * Editar produto
     */
    public function editar($dados)
    {
        $avisoImagem = null;
        if (isset($_FILES['imagem'])) {
            $uploadMsg = $this->produtoService->mensagemErroUpload($_FILES['imagem']);
            if ($uploadMsg !== null) {
                return ['success' => false, 'message' => $uploadMsg];
            }
            $temFicheiro = (($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
                && (int)($_FILES['imagem']['size'] ?? 0) > 0;
            if ($temFicheiro) {
                $path = $this->produtoService->processarImagem($_FILES['imagem']);
                if ($path) {
                    $dados['imagem'] = $path;
                } else {
                    // Mantém a foto atual e continua a guardar os outros campos
                    $avisoImagem = 'Dados guardados, mas a nova imagem não pôde ser processada.';
                }
            }
        }

        $resultado = $this->produtoService->editar($dados);
        if (!empty($resultado['success']) && $avisoImagem) {
            $resultado['message'] = trim(($resultado['message'] ?? 'Produto atualizado.') . ' ' . $avisoImagem);
            $resultado['warning'] = $avisoImagem;
        }
        return $resultado;
    }

    /**
     * Excluir produto
     */
    public function deletar($id, $restaurante_id)
    {
        return $this->produtoService->deletar($id, $restaurante_id);
    }

    /**
     * Atualizar estoque
     */
    public function atualizarEstoque($id, $restaurante_id, $quantidade, $tipo = 'ENTRADA')
    {
        return $this->produtoService->atualizarEstoque($id, $restaurante_id, $quantidade, $tipo);
    }

    /**
     * Estoque baixo
     */
    public function estoqueBaixo($restaurante_id)
    {
        return $this->produtoService->estoqueBaixo($restaurante_id);
    }
}
