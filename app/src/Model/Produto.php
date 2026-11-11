<?php

/**
 * ============================================
 * CLASSE DE PRODUTOS - MODEL
 * ============================================
 */

class Produto
{
    private $conn;
    private $table_name = "produtos";

    public $id;
    public $restaurante_id;
    public $categoria_id;
    public $nome;
    public $descricao;
    public $preco;
    public $custo;
    public $estoque;
    public $estoque_minimo;
    public $ativo;
    public $destaque;
    public $imagem;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    private function debugLog(string $message): void
    {
        $logDir = sys_get_temp_dir();
        if (!$logDir || !is_dir($logDir) || !is_writable($logDir)) {
            return;
        }

        $logFile = rtrim($logDir, "/\\") . DIRECTORY_SEPARATOR . 'debug_produto_listar.log';
        @file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . ' | ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private function ensureDestaqueColumn(): void
    {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table_name . " LIKE 'destaque'");
            if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->conn->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN destaque TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo");
            }
        } catch (Throwable $e) {
            $this->debugLog('ensureDestaqueColumn: ' . $e->getMessage());
        }
    }

    /**
     * Listar todos os produtos do restaurante
     */
    public function listar($restaurante_id)
    {
        $query = "SELECT p.*, c.nome as categoria_nome 
                  FROM " . $this->table_name . " p
                  LEFT JOIN categorias c ON p.categoria_id = c.id
                  WHERE p.restaurante_id = :restaurante_id
                  ORDER BY p.nome ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Buscar produto por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE id = :id AND restaurante_id = :restaurante_id 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cadastrar novo produto
     */
    public function cadastrar()
    {
        $this->ensureDestaqueColumn();

        $query = "INSERT INTO " . $this->table_name . "
                  SET restaurante_id = :restaurante_id,
                      categoria_id   = :categoria_id,
                      nome           = :nome,
                      descricao      = :descricao,
                      preco          = :preco,
                      custo          = :custo,
                      estoque        = :estoque,
                      estoque_minimo = :estoque_minimo,
                      ativo          = :ativo,
                      destaque       = :destaque,
                      imagem         = :imagem";

        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(":restaurante_id", (int)$this->restaurante_id, PDO::PARAM_INT);
        if ($this->categoria_id === null || $this->categoria_id === '') {
            $stmt->bindValue(":categoria_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":categoria_id", (int)$this->categoria_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(":nome", (string)$this->nome);
        $stmt->bindValue(":descricao", (string)($this->descricao ?? ''));
        $stmt->bindValue(":preco", (float)$this->preco);
        $stmt->bindValue(":custo", (float)($this->custo ?? 0));
        $stmt->bindValue(":estoque", (int)($this->estoque ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(":estoque_minimo", (int)($this->estoque_minimo ?? 5), PDO::PARAM_INT);
        $stmt->bindValue(":ativo", (int)($this->ativo ?? 1), PDO::PARAM_INT);
        $stmt->bindValue(":destaque", (int)($this->destaque ?? 0), PDO::PARAM_INT);
        if ($this->imagem === null || $this->imagem === '') {
            $stmt->bindValue(":imagem", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":imagem", (string)$this->imagem);
        }

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * Editar produto
     */
    public function editar()
    {
        $this->ensureDestaqueColumn();

        $query = "UPDATE " . $this->table_name . "
                  SET categoria_id   = :categoria_id,
                      nome           = :nome,
                      descricao      = :descricao,
                      preco          = :preco,
                      custo          = :custo,
                      estoque        = :estoque,
                      estoque_minimo = :estoque_minimo,
                      ativo          = :ativo,
                      destaque       = :destaque,
                      imagem         = :imagem
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->conn->prepare($query);

        if ($this->categoria_id === null || $this->categoria_id === '') {
            $stmt->bindValue(":categoria_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":categoria_id", (int)$this->categoria_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(":nome", (string)$this->nome);
        $stmt->bindValue(":descricao", (string)($this->descricao ?? ''));
        $stmt->bindValue(":preco", (float)$this->preco);
        $stmt->bindValue(":custo", (float)($this->custo ?? 0));
        $stmt->bindValue(":estoque", (int)($this->estoque ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(":estoque_minimo", (int)($this->estoque_minimo ?? 5), PDO::PARAM_INT);
        $stmt->bindValue(":ativo", (int)($this->ativo ?? 1), PDO::PARAM_INT);
        $stmt->bindValue(":destaque", (int)($this->destaque ?? 0), PDO::PARAM_INT);
        if ($this->imagem === null || $this->imagem === '') {
            $stmt->bindValue(":imagem", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":imagem", (string)$this->imagem);
        }
        $stmt->bindValue(":id", (int)$this->id, PDO::PARAM_INT);
        $stmt->bindValue(":restaurante_id", (int)$this->restaurante_id, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->rowCount() >= 0; // execute ok (0 = sem alterações, mas válido)
    }

    /**
     * Excluir produto
     */
    public function deletar($id, $restaurante_id)
    {
        $query = "DELETE FROM " . $this->table_name . "
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        return $stmt->execute();
    }

    /**
     * Atualizar estoque
     */
    public function atualizarEstoque($id, $restaurante_id, $quantidade, $tipo = 'ENTRADA')
    {
        if ($tipo == 'ENTRADA') {
            $query = "UPDATE " . $this->table_name . " 
                      SET estoque = estoque + :quantidade 
                      WHERE id = :id AND restaurante_id = :restaurante_id";
        } else {
            $query = "UPDATE " . $this->table_name . " 
                      SET estoque = GREATEST(0, estoque - :quantidade) 
                      WHERE id = :id AND restaurante_id = :restaurante_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":quantidade", $quantidade);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        return $stmt->execute();
    }

    /**
     * Produtos com estoque baixo
     */
    public function estoqueBaixo($restaurante_id)
    {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND estoque <= estoque_minimo 
                  AND ativo = 1
                  ORDER BY estoque ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Contar total de produtos ativos
     */
    public function contarAtivos($restaurante_id)
    {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . "
                  WHERE restaurante_id = :restaurante_id AND ativo = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'] ?? 0;
    }

    /**
     * Listar produtos filtrados por categoria e/ou busca
     */
    public function listarFiltrado($categoria_id = null, $busca = '')
    {
        $this->debugLog("categoria_id=" . var_export($categoria_id, true) . " | busca=" . var_export($busca, true));

        // Blindagem: restaurante_id obrigatório
        if (empty($this->restaurante_id)) {
            $this->debugLog('ERRO: restaurante_id vazio ou nulo');
            throw new Exception("restaurante_id obrigatório não informado no Produto->listarFiltrado");
        }

        $query = "SELECT p.*, c.nome as categoria_nome 
                  FROM " . $this->table_name . " p
                  LEFT JOIN categorias c ON p.categoria_id = c.id
                  WHERE p.restaurante_id = :restaurante_id";

        $restaurante_id = $this->restaurante_id;
        $categoria_id_var = !is_null($categoria_id) ? trim((string) $categoria_id) : null;
        $busca_limpa = trim((string) $busca);
        $tem_categoria = $categoria_id_var !== null && $categoria_id_var !== '';
        $tem_busca = $busca_limpa !== '';

        if ($tem_categoria) {
            $query .= " AND p.categoria_id = :categoria_id";
        }

        if ($tem_busca) {
            $busca_nome_var = "%" . $busca_limpa . "%";
            $busca_descricao_var = $busca_nome_var;
            $query .= " AND (p.nome LIKE :busca_nome OR p.descricao LIKE :busca_descricao)";
        }

        $query .= " ORDER BY p.nome ASC";

        // Logar query e params finais para debug
        $params_debug = [":restaurante_id" => $restaurante_id];
        if ($tem_categoria) $params_debug[":categoria_id"] = $categoria_id_var;
        if ($tem_busca) {
            $params_debug[":busca_nome"] = $busca_nome_var;
            $params_debug[":busca_descricao"] = $busca_descricao_var;
        }
        $this->debugLog("QUERY: " . $query . " | PARAMS: " . var_export($params_debug, true));

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        if ($tem_categoria) {
            $stmt->bindParam(":categoria_id", $categoria_id_var);
        }
        if ($tem_busca) {
            $stmt->bindParam(":busca_nome", $busca_nome_var);
            $stmt->bindParam(":busca_descricao", $busca_descricao_var);
        }
        $stmt->execute();

        return $stmt;
    }
}
