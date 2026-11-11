<?php

/**
 * ============================================
 * CLASSE DE VENDAS - MODEL
 * ============================================
 */

class Venda
{
    private $conn;
    private $table_name = "vendas";

    public $id;
    public $restaurante_id;
    public $usuario_id;
    public $caixa_id;
    public $mesa_id;
    public $total;
    public $desconto;
    public $total_final;
    public $forma_pagamento;
    public $valor_recebido;
    public $troco;
    public $status;
    public $numero_fatura;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Listar vendas do restaurante
     */
    public function listar($restaurante_id, $data_inicio = null, $data_fim = null)
    {
        $query = "SELECT v.*, u.nome as usuario_nome, m.numero as mesa_numero 
                  FROM " . $this->table_name . " v
                  LEFT JOIN usuarios u ON v.usuario_id = u.id
                  LEFT JOIN mesas m ON v.mesa_id = m.id
                  WHERE v.restaurante_id = :restaurante_id";

        if ($data_inicio && $data_fim) {
            $query .= " AND (v.criado_em >= :data_inicio AND v.criado_em < :data_fim + INTERVAL 1 DAY)";
        }

        $query .= " ORDER BY v.criado_em DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        if ($data_inicio && $data_fim) {
            $stmt->bindParam(":data_inicio", $data_inicio);
            $stmt->bindParam(":data_fim", $data_fim);
        }

        $stmt->execute();
        return $stmt;
    }

    /**
     * Buscar venda por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        $query = "SELECT v.*, u.nome as usuario_nome, m.numero as mesa_numero
                  FROM " . $this->table_name . " v
                  LEFT JOIN usuarios u ON v.usuario_id = u.id
                  LEFT JOIN mesas m ON v.mesa_id = m.id
                  WHERE v.id = :id AND v.restaurante_id = :restaurante_id
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Buscar itens de uma venda
     */
    public function buscarItens($venda_id)
    {
        $query = "SELECT iv.*, COALESCE(p.nome, CONCAT('Produto #', iv.produto_id)) AS produto_nome
                  FROM itens_venda iv
                  LEFT JOIN produtos p ON iv.produto_id = p.id
                  WHERE iv.venda_id = :venda_id
                  ORDER BY COALESCE(p.nome, CONCAT('Produto #', iv.produto_id)) ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":venda_id", $venda_id);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Criar nova venda
     */
    public function criar()
    {
        $this->garantirColunasPagamentoDetalhes();

        $query = "INSERT INTO " . $this->table_name . "
                  SET restaurante_id  = :restaurante_id,
                      usuario_id      = :usuario_id,
                      caixa_id        = :caixa_id,
                      mesa_id         = :mesa_id,
                      total           = :total,
                      desconto        = :desconto,
                      total_final     = :total_final,
                      forma_pagamento = :forma_pagamento,
                      valor_recebido  = :valor_recebido,
                      troco           = :troco,
                      status          = :status,
                      numero_fatura   = :numero_fatura";

        $stmt = $this->conn->prepare($query);

        $stmt->bindValue(":restaurante_id",  (int)$this->restaurante_id, PDO::PARAM_INT);
        $stmt->bindValue(":usuario_id",      (int)$this->usuario_id, PDO::PARAM_INT);
        $stmt->bindValue(":caixa_id",        (int)$this->caixa_id, PDO::PARAM_INT);
        if ($this->mesa_id === null || $this->mesa_id === '' || (int)$this->mesa_id <= 0) {
            $stmt->bindValue(":mesa_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":mesa_id", (int)$this->mesa_id, PDO::PARAM_INT);
        }
        $stmt->bindValue(":total",           (float)$this->total);
        $stmt->bindValue(":desconto",        (float)$this->desconto);
        $stmt->bindValue(":total_final",     (float)$this->total_final);
        $stmt->bindValue(":forma_pagamento", (string)$this->forma_pagamento);
        if ($this->valor_recebido === null || $this->valor_recebido === '') {
            $stmt->bindValue(":valor_recebido", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":valor_recebido", (float)$this->valor_recebido);
        }
        if ($this->troco === null || $this->troco === '') {
            $stmt->bindValue(":troco", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":troco", (float)$this->troco);
        }
        $stmt->bindValue(":status",          (string)$this->status);
        $stmt->bindValue(":numero_fatura",   (string)$this->numero_fatura);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    /** Garante colunas/enum de pagamento (chamar fora de transação). */
    public function garantirSchemaPagamentoPublico(): void
    {
        $this->garantirColunasPagamentoDetalhes();
    }

    private function garantirColunasPagamentoDetalhes()
    {
        static $garantido = false;
        if ($garantido) {
            return;
        }

        // Depois da migracao db/migrations/20261030_1130_promocoes_pagamentos,
        // nao inspecciona nem altera o schema em cada pagamento.
        if (!function_exists('migration_esta_aplicada')) {
            require_once __DIR__ . '/../config/migration_state.php';
        }
        if (migration_esta_aplicada($this->conn, '20261030_1130_promocoes_pagamentos')) {
            $garantido = true;
            return;
        }

        // Nunca correr DDL dentro de transação (MySQL faz commit implícito).
        if ($this->conn->inTransaction()) {
            return;
        }

        $stmt = $this->conn->query("SHOW COLUMNS FROM " . $this->table_name);
        $colunas = [];
        $formaType = '';
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $field = strtolower((string)$row['Field']);
            $colunas[$field] = true;
            if ($field === 'forma_pagamento') {
                $formaType = strtolower((string)($row['Type'] ?? ''));
            }
        }

        if (!isset($colunas['valor_recebido'])) {
            $this->conn->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN valor_recebido DECIMAL(10,2) NULL DEFAULT NULL AFTER forma_pagamento");
        }

        if (!isset($colunas['troco'])) {
            $this->conn->exec("ALTER TABLE " . $this->table_name . " ADD COLUMN troco DECIMAL(10,2) NULL DEFAULT NULL AFTER valor_recebido");
        }

        // EMOLA é usado no caixa; sem isto o INSERT pode falhar ou gravar vazio.
        if ($formaType !== '' && strpos($formaType, 'emola') === false) {
            try {
                $this->conn->exec("
                    ALTER TABLE " . $this->table_name . "
                    MODIFY COLUMN forma_pagamento ENUM('DINHEIRO','MPESA','EMOLA','CARTAO','TRANSFERENCIA') DEFAULT 'DINHEIRO'
                ");
            } catch (Throwable $e) {
                // ignora se já estiver correcto
            }
        }

        $garantido = true;
    }

    /**
     * Adicionar item à venda
     */
    public function adicionarItem($venda_id, $produto_id, $quantidade, $preco_unitario, $promocao_id = null)
    {
        $venda_id = (int)$venda_id;
        $produto_id = (int)$produto_id;
        $promocao_id = (int)$promocao_id > 0 ? (int)$promocao_id : null;
        $quantidade = max(1, (int)round((float)$quantidade));
        $preco_unitario = (float)$preco_unitario;
        $subtotal = round($quantidade * $preco_unitario, 2);

        static $suportaPromocaoPorConexao = [];
        $connectionKey = spl_object_id($this->conn);
        if (!array_key_exists($connectionKey, $suportaPromocaoPorConexao)) {
            $suportaPromocaoPorConexao[$connectionKey] = (bool)$this->conn
                ->query("SHOW COLUMNS FROM itens_venda LIKE 'promocao_id'")
                ->fetch(PDO::FETCH_ASSOC);
        }
        $suportaPromocao = $suportaPromocaoPorConexao[$connectionKey];
        $promocaoSql = $suportaPromocao ? "\n                      promocao_id    = :promocao_id," : '';

        $query = "INSERT INTO itens_venda
                  SET venda_id       = :venda_id,
                      produto_id     = :produto_id," . $promocaoSql . "
                      quantidade     = :quantidade,
                      preco_unitario = :preco_unitario,
                      subtotal       = :subtotal";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(":venda_id", $venda_id, PDO::PARAM_INT);
        $stmt->bindValue(":produto_id", $produto_id, PDO::PARAM_INT);
        if ($suportaPromocao) {
            $stmt->bindValue(":promocao_id", $promocao_id, $promocao_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        $stmt->bindValue(":quantidade", $quantidade, PDO::PARAM_INT);
        $stmt->bindValue(":preco_unitario", $preco_unitario);
        $stmt->bindValue(":subtotal", $subtotal);

        return $stmt->execute();
    }

    /**
     * Prepara (fora de transacao) a linha do contador de faturas deste ano.
     * Opcional mas recomendado: evita que o primeiro pedido do ano tenha de a criar
     * dentro da transacao. Seguro chamar varias vezes.
     */
    public function prepararNumeracaoFatura($restaurante_id): void
    {
        $restaurante_id = (int)$restaurante_id;
        if ($restaurante_id <= 0 || $this->conn->inTransaction()) {
            return;
        }
        $ano = (int)date('Y');
        try {
            $existe = $this->conn->prepare('SELECT 1 FROM fatura_sequencias WHERE restaurante_id = :rid AND ano = :ano LIMIT 1');
            $existe->execute([':rid' => $restaurante_id, ':ano' => $ano]);
            if ($existe->fetchColumn()) {
                return;
            }
            $ins = $this->conn->prepare('INSERT IGNORE INTO fatura_sequencias (restaurante_id, ano, ultimo) VALUES (:rid, :ano, :ultimo)');
            $ins->execute([
                ':rid' => $restaurante_id,
                ':ano' => $ano,
                ':ultimo' => $this->maiorNumeroFaturaDoAno($restaurante_id, $ano),
            ]);
        } catch (Throwable $e) {
            error_log('[Venda::prepararNumeracaoFatura] ' . $e->getMessage());
        }
    }

    /** Maior sufixo numerico ja emitido para FT<ano>xxxxxx neste restaurante. */
    private function maiorNumeroFaturaDoAno(int $restaurante_id, int $ano): int
    {
        $stmt = $this->conn->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(numero_fatura, 7) AS UNSIGNED)), 0)
            FROM " . $this->table_name . "
            WHERE restaurante_id = :rid AND numero_fatura LIKE :prefixo
        ");
        $stmt->execute([':rid' => $restaurante_id, ':prefixo' => 'FT' . $ano . '%']);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Gerar numero de fatura (FT<ano><000001>), sem duplicados.
     *
     * Antes: COUNT(*)+1 sem bloqueio - duas vendas simultaneas recebiam o mesmo
     * numero, apagar uma venda fazia repetir um numero ja emitido, e a contagem
     * nao reiniciava com o ano.
     *
     * Agora: contador atomico por (restaurante, ano) em fatura_sequencias.
     * Dentro de uma transacao, a linha fica bloqueada ate ao commit (vendas
     * simultaneas ficam em fila); se a venda for desfeita, o numero e devolvido.
     */
    public function gerarNumeroFatura($restaurante_id)
    {
        $restaurante_id = (int)$restaurante_id;
        $ano = (int)date('Y');

        try {
            $upd = $this->conn->prepare('
                UPDATE fatura_sequencias
                SET ultimo = LAST_INSERT_ID(ultimo + 1)
                WHERE restaurante_id = :rid AND ano = :ano
            ');
            $upd->execute([':rid' => $restaurante_id, ':ano' => $ano]);

            if ($upd->rowCount() === 0) {
                // Primeira fatura do ano sem preparacao previa: cria a linha aqui.
                // Bloqueio nomeado: so um processo de cada vez cria a linha,
                // evitando deadlocks entre INSERTs simultaneos da mesma chave.
                $lockName = 'nivora_fatura_seq_' . $restaurante_id . '_' . $ano;
                $lock = $this->conn->prepare('SELECT GET_LOCK(:nome, 10)');
                $lock->execute([':nome' => $lockName]);
                try {
                    $upd->execute([':rid' => $restaurante_id, ':ano' => $ano]);
                    if ($upd->rowCount() === 0) {
                        $semente = $this->maiorNumeroFaturaDoAno($restaurante_id, $ano) + 1;
                        $ins = $this->conn->prepare('
                            INSERT INTO fatura_sequencias (restaurante_id, ano, ultimo)
                            VALUES (:rid, :ano, LAST_INSERT_ID(:semente))
                            ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)
                        ');
                        $ins->execute([':rid' => $restaurante_id, ':ano' => $ano, ':semente' => $semente]);
                    }
                } finally {
                    $rel = $this->conn->prepare('SELECT RELEASE_LOCK(:nome)');
                    $rel->execute([':nome' => $lockName]);
                }
            }

            $numero = (int)$this->conn->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            if ($numero <= 0) {
                throw new RuntimeException('Contador de faturas devolveu um valor invalido.');
            }
            return 'FT' . $ano . str_pad((string)$numero, 6, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            // So recorrer ao metodo antigo se a tabela ainda nao existir
            // (migracao por aplicar). Outros erros sao propagados para a
            // transacao ser desfeita - nunca emitir numeros fora dela.
            if ((int)($e->errorInfo[1] ?? 0) !== 1146) {
                throw $e;
            }
            error_log('[Venda::gerarNumeroFatura] tabela fatura_sequencias em falta; a usar metodo antigo.');
        }

        return 'FT' . $ano . str_pad((string)($this->maiorNumeroFaturaDoAno($restaurante_id, $ano) + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Vendas de hoje
     */
    public function vendasHoje($restaurante_id)
    {
        $query = "SELECT SUM(total_final) as total 
                  FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) 
                  AND status = 'PAGO'";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'] ?? 0;
    }

    /**
     * Contar vendas de hoje
     */
    public function contarVendasHoje($restaurante_id)
    {
        $query = "SELECT COUNT(*) as total 
                  FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND (criado_em >= CURDATE() AND criado_em < CURDATE() + INTERVAL 1 DAY) 
                  AND status = 'PAGO'";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'] ?? 0;
    }

    /**
     * Total de vendas por período
     */
    public function totalVendas($restaurante_id, $data_inicio, $data_fim)
    {
        $query = "SELECT COUNT(*) as total, SUM(total_final) as valor_total 
                  FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND (criado_em >= :data_inicio AND criado_em < :data_fim + INTERVAL 1 DAY)
                  AND status = 'PAGO'";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->bindParam(":data_inicio",    $data_inicio);
        $stmt->bindParam(":data_fim",       $data_fim);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cancelar venda
     */
    public function cancelar($id, $restaurante_id)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET status = 'CANCELADO' 
                  WHERE id = :id AND restaurante_id = :restaurante_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id",             $id);
        $stmt->bindParam(":restaurante_id", $restaurante_id);

        return $stmt->execute();
    }

    /**
     * Últimas vendas (para dashboard)
     */
    public function ultimasVendas($restaurante_id, $limite = 10)
    {
        $query = "SELECT v.*, u.nome as usuario_nome, m.numero as mesa_numero
                  FROM " . $this->table_name . " v
                  LEFT JOIN usuarios u ON v.usuario_id = u.id
                  LEFT JOIN mesas m ON v.mesa_id = m.id
                  WHERE v.restaurante_id = :restaurante_id
                  ORDER BY v.criado_em DESC
                  LIMIT :limite";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->bindParam(":limite",         $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Vendas por dia (para gráfico)
     */
    public function vendasPorDia($restaurante_id, $dias = 7)
    {
        $query = "SELECT DATE(criado_em) as data, SUM(total_final) as total
                  FROM " . $this->table_name . " 
                  WHERE restaurante_id = :restaurante_id 
                  AND criado_em >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
                  AND status = 'PAGO'
                  GROUP BY DATE(criado_em)
                  ORDER BY data ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":restaurante_id", $restaurante_id);
        $stmt->bindParam(":dias",           $dias, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }
}
