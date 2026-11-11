<?php
declare(strict_types=1);

namespace Nivora\Tests\Support;

use PDO;

/**
 * Monta dados de teste isolados num restaurante ficticio (id aleatorio alto),
 * para que os testes nunca colidam entre si nem com dados reais.
 */
final class Cenario
{
    public int $restauranteId;
    public int $mesaId;
    public int $balcaoId;
    /** @var array<string,int> nome => id */
    public array $produtos = [];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->restauranteId = TestDb::restauranteFicticio();
        $this->mesaId = $this->mesa('5');
        $this->balcaoId = $this->mesa('0'); // mesa 0 = balcao/take-away
    }

    public function mesa(string $numero, string $status = 'LIVRE'): int
    {
        $this->db->prepare('INSERT INTO mesas (restaurante_id, numero, status) VALUES (?, ?, ?)')
            ->execute([$this->restauranteId, $numero, $status]);
        return (int)$this->db->lastInsertId();
    }

    public function produto(string $nome, float $preco, ?int $restauranteId = null): int
    {
        $this->db->prepare('INSERT INTO produtos (restaurante_id, nome, preco, ativo, estoque) VALUES (?, ?, ?, 1, 100)')
            ->execute([$restauranteId ?? $this->restauranteId, $nome, $preco]);
        $id = (int)$this->db->lastInsertId();
        $this->produtos[$nome] = $id;
        return $id;
    }

    public function abrirCaixa(): int
    {
        $this->db->prepare("INSERT INTO caixas (restaurante_id, usuario_id, status, saldo_inicial, data_abertura) VALUES (?, 1, 'ABERTO', 0, NOW())")
            ->execute([$this->restauranteId]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Pedido pronto a pagar. $itens: [[produto_id, qtd, preco, status_item]]
     */
    public function pedido(array $itens, string $status = 'PRONTO', ?int $mesaId = null): int
    {
        $mesaId = $mesaId ?? $this->mesaId;
        $total = 0.0;
        foreach ($itens as [$pid, $qtd, $preco]) {
            $total += $qtd * $preco;
        }
        $this->db->prepare("INSERT INTO pedidos (restaurante_id, numero_pedido, mesa_id, status, total, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
            ->execute([$this->restauranteId, (string)random_int(1000, 9999), $mesaId, $status, $total]);
        $pedidoId = (int)$this->db->lastInsertId();
        $this->db->prepare("UPDATE mesas SET status = 'OCUPADA' WHERE id = ?")->execute([$mesaId]);
        $ins = $this->db->prepare('INSERT INTO itens_pedido (pedido_id, produto_id, quantidade, preco_unitario, subtotal, status) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($itens as $it) {
            [$pid, $qtd, $preco] = $it;
            $ins->execute([$pedidoId, $pid, $qtd, $preco, $qtd * $preco, $it[3] ?? 'pronto']);
        }
        return $pedidoId;
    }

    public function contexto(array $extra = []): array
    {
        return array_merge([
            'restaurante_id' => $this->restauranteId,
            'usuario_id' => 1,
            'perfil' => 'ADMIN',
            'plano_nome' => 'BASICO',
            'estoque_avancado' => false,
            'filial_id' => null,
            'telas_bar_cozinha' => true,
            'contas_abertas' => false,
            'idempotencia_payload' => [],
        ], $extra);
    }

    public function valor(string $sql, array $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
