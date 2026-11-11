<?php

/*
   Service de Mesas
   Arquitetura N-Tier
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/plano_check.php';
require_once __DIR__ . '/../config/mesa_display_helper.php';
require_once __DIR__ . '/../config/balcao_canal.php';
require_once __DIR__ . '/../Model/Mesa.php';

class MesaService
{
    private $db;
    private $mesa;

    private function normalizarNumeroMesa(string $numero): string
    {
        return mesa_normalizar_numero($numero);
    }

    private function isBalcaoNumero(string $numero): bool
    {
        return mesa_eh_balcao($numero);
    }

    public function __construct($mesa = null)
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->mesa = $mesa ?? new Mesa($this->db);
    }

    /**
     * Listar mesas
     */
    public function listar($restaurante_id)
    {
        mesa_garantir_balcao_canonico($this->db, (int)$restaurante_id);
        $stmt = $this->mesa->listar($restaurante_id);
        $maxBalcoes = 1;
        if (function_exists('balcao2_config')) {
            $balcao2 = balcao2_config($this->db, (int)$restaurante_id);
            if (!empty($balcao2['ativo'])) {
                $maxBalcoes = 2;
            }
        }
        return mesa_filtrar_operacionais($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $maxBalcoes);
    }

    /**
     * Listar mesas livres
     */
    public function mesasLivres($restaurante_id)
    {
        $stmt = $this->mesa->mesasLivres($restaurante_id);
        return array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn(array $mesa): bool => !mesa_eh_balcao($mesa['numero'] ?? '')
        ));
    }

    /**
     * Atualizar status da mesa
     */
    public function atualizarStatus($id, $restaurante_id, $status)
    {
        $stmt = $this->db->prepare("
            SELECT numero
            FROM mesas
            WHERE id = :id AND restaurante_id = :rid
            LIMIT 1
        ");
        $stmt->execute([':id' => (int)$id, ':rid' => (int)$restaurante_id]);
        $numero = $stmt->fetchColumn();
        if ($numero !== false && mesa_eh_balcao($numero)) {
            $this->mesa->atualizarStatus($id, $restaurante_id, 'LIVRE');
            return array("success" => true, "message" => "O Balcão / Take-away permanece sempre disponível.");
        }

        if ($this->mesa->atualizarStatus($id, $restaurante_id, $status)) {
            return array("success" => true, "message" => "Status atualizado com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao atualizar status.");
    }

    /**
     * Cadastrar nova mesa
     */
    public function cadastrar($dados, $restaurante_id, $plano_restaurante_id = 0)
    {
        $planoRestauranteId = $plano_restaurante_id > 0 ? $plano_restaurante_id : $restaurante_id;
        $temMultiFilial = $planoRestauranteId > 0 && plano_tem_funcionalidade_db($planoRestauranteId, 'multi_filial');
        $tipo = strtolower(trim((string)($dados['tipo'] ?? 'mesa')));
        $numero = trim((string)($dados['numero'] ?? ''));
        $vaiSerBalcao = ($tipo === 'balcao')
            || ($numero === '0')
            || ($this->normalizarNumeroMesa($numero) === 'balcao');

        if ($vaiSerBalcao) {
            $balcao = mesa_garantir_balcao_canonico($this->db, (int)$restaurante_id);
            return array(
                "success" => true,
                "message" => !empty($balcao['created'])
                    ? "Balcão / Take-away criado automaticamente."
                    : "O restaurante já possui o Balcão / Take-away automático.",
                "numero" => (string)($balcao['numero'] ?? 'B1'),
                "id" => (int)($balcao['id'] ?? 0),
                "created" => !empty($balcao['created']),
            );
        }

        if ($temMultiFilial) {
            $stmtCount = $this->db->prepare("
                SELECT m.numero
                FROM mesas m
                INNER JOIN restaurantes r ON r.id = m.restaurante_id
                WHERE m.restaurante_id = :base_restaurante_id OR r.filial_id = :filial_base_id
            ");
            $stmtCount->bindValue(':base_restaurante_id', $planoRestauranteId, PDO::PARAM_INT);
            $stmtCount->bindValue(':filial_base_id', $planoRestauranteId, PDO::PARAM_INT);
            $stmtCount->execute();
            $numerosMesas = $stmtCount->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } else {
            $queryCount = "SELECT numero FROM mesas WHERE restaurante_id = :rid";
            $stmtCount = $this->db->prepare($queryCount);
            $stmtCount->bindParam(':rid', $restaurante_id, PDO::PARAM_INT);
            $stmtCount->execute();
            $numerosMesas = $stmtCount->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        $totalMesas = count(array_filter(
            $numerosMesas,
            static fn($numeroMesa): bool => !mesa_eh_balcao($numeroMesa)
        ));

        $verificacaoPlano = plano_verificar_limite_db($planoRestauranteId, 'mesas', $totalMesas);
        if (!$verificacaoPlano['permitido']) {
            return array(
                "success" => false,
                "code" => "LIMITE_EXCEDIDO",
                "message" => function_exists('plano_mensagem_limite_atingido')
                    ? plano_mensagem_limite_atingido($verificacaoPlano, 'mesas')
                    : "Limite do plano atingido. O plano {$verificacaoPlano['plano']} permite até {$verificacaoPlano['limite']} mesas.",
                "upgrade_url" => "configuracoes.php?secao=plano",
                "limite" => $verificacaoPlano['limite'] ?? null,
                "atual" => $verificacaoPlano['atual'] ?? null,
            );
        }

        // Verificar se número já existe.
        // Usar comparação por número normalizado para capturar variações legadas
        // (ex.: "Balcão", "Mesa 0", "B1", etc.) que colidem semanticamente.
        $query = "SELECT id, numero FROM mesas WHERE restaurante_id = :rid";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':rid', $restaurante_id);
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing = (string)($row['numero'] ?? '');
            if ($this->normalizarNumeroMesa($existing) === $this->normalizarNumeroMesa($numero)) {
                $mensagemDuplicado = $this->isBalcaoNumero($numero)
                    ? "Já existe um balcão com esta identificação"
                    : "Já existe uma mesa com este número";
                return array("success" => false, "message" => $mensagemDuplicado, "conflict_id" => (int)($row['id'] ?? 0), "conflict_numero" => $existing);
            }
        }

        $query2 = "INSERT INTO mesas (restaurante_id, numero, capacidade, status)
                   VALUES (:restaurante_id, :numero, :capacidade, 'LIVRE')";
        $stmt2 = $this->db->prepare($query2);
        $stmt2->bindParam(':restaurante_id', $restaurante_id);
        $capacidade = (int)($dados['capacidade'] ?? 0);
        $stmt2->bindParam(':numero', $numero, PDO::PARAM_STR);
        $stmt2->bindParam(':capacidade', $capacidade, PDO::PARAM_INT);

        if ($stmt2->execute()) {
            $mensagemSucesso = $this->isBalcaoNumero($numero)
                ? (mesa_rotulo($numero) . " cadastrado com sucesso!")
                : "Mesa cadastrada com sucesso!";
            return array("success" => true, "message" => $mensagemSucesso, "numero" => $numero);
        }

        return array("success" => false, "message" => "Erro ao cadastrar mesa");
    }

    public function garantirBalcao($restaurante_id): array
    {
        return mesa_garantir_balcao_canonico($this->db, (int)$restaurante_id);
    }
}
