<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use Throwable;

/**
 * Lista de espera de reservas.
 *
 * Quando nao ha mesa para a hora pedida, o cliente fica aqui em vez de se
 * perder. Se depois uma reserva for cancelada, a vaga e oferecida a quem
 * esta a espera ha mais tempo e que caiba na mesa que se libertou.
 *
 * A fila e por ordem de chegada dentro do mesmo dia. Nao ha prioridades:
 * sao simples de programar e dificeis de explicar a um cliente que ficou
 * para tras.
 */
class ListaEsperaService
{
    private PDO $db;

    /** Margem, em minutos, para considerar que uma vaga serve a espera. */
    private const MARGEM_MINUTOS = 60;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function disponivelNoPlano(int $restauranteId): bool
    {
        if ($restauranteId <= 0 || !function_exists('plano_tem_funcionalidade_db')) {
            return false;
        }

        return (bool)plano_tem_funcionalidade_db($restauranteId, 'reservas_lista_espera');
    }

    /**
     * Poe alguem na fila. Devolve o lugar que ficou a ocupar.
     */
    public function adicionar(int $restauranteId, array $dados): array
    {
        if (!self::disponivelNoPlano($restauranteId)) {
            return ['success' => false, 'message' => 'A lista de espera nao esta disponivel no plano actual.'];
        }

        $nome = trim((string)($dados['nome_cliente'] ?? ''));
        $data = trim((string)($dados['data_reserva'] ?? ''));
        $hora = substr(trim((string)($dados['hora_reserva'] ?? '')), 0, 8);
        $quantidade = (int)($dados['quantidade_pessoas'] ?? 0);

        if ($nome === '' || $data === '' || $hora === '' || $quantidade <= 0) {
            return ['success' => false, 'message' => 'Faltam dados para entrar na lista de espera.'];
        }

        if (strlen($hora) === 5) {
            $hora .= ':00';
        }

        $telefone = trim((string)($dados['telefone_cliente'] ?? ''));

        // O mesmo contacto, para o mesmo dia, nao entra duas vezes: a fila
        // deixaria de significar nada se um cliente insistente a enchesse.
        if ($telefone !== '') {
            $jaEsta = $this->db->prepare("
                SELECT id FROM reservas_espera
                WHERE restaurante_id = :rid
                  AND telefone_cliente = :tel
                  AND data_reserva = :data
                  AND status IN ('AGUARDA', 'AVISADO')
                LIMIT 1
            ");
            $jaEsta->execute([':rid' => $restauranteId, ':tel' => $telefone, ':data' => $data]);
            if ($jaEsta->fetchColumn()) {
                return ['success' => false, 'message' => 'Este contacto ja esta na lista de espera para esse dia.'];
            }
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO reservas_espera
                    (restaurante_id, cliente_id, nome_cliente, email_cliente, telefone_cliente,
                     data_reserva, hora_reserva, quantidade_pessoas, observacoes)
                VALUES
                    (:rid, :cliente, :nome, :email, :tel, :data, :hora, :qtd, :obs)
            ");
            $stmt->execute([
                ':rid' => $restauranteId,
                ':cliente' => !empty($dados['cliente_id']) ? (int)$dados['cliente_id'] : null,
                ':nome' => $nome,
                ':email' => trim((string)($dados['email_cliente'] ?? '')) ?: null,
                ':tel' => $telefone ?: null,
                ':data' => $data,
                ':hora' => $hora,
                ':qtd' => $quantidade,
                ':obs' => trim((string)($dados['observacoes'] ?? '')) ?: null,
            ]);

            $id = (int)$this->db->lastInsertId();
            return [
                'success' => true,
                'message' => 'Ficou na lista de espera.',
                'espera_id' => $id,
                'posicao' => $this->posicao($restauranteId, $id, $data),
            ];
        } catch (Throwable $e) {
            error_log('[LISTA_ESPERA] Falha ao adicionar: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Nao foi possivel entrar na lista de espera.'];
        }
    }

    /** Lugar na fila, contando so quem ainda aguarda nesse dia. */
    public function posicao(int $restauranteId, int $esperaId, string $data): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) + 1 FROM reservas_espera
            WHERE restaurante_id = :rid
              AND data_reserva = :data
              AND status = 'AGUARDA'
              AND criado_em < (SELECT criado_em FROM reservas_espera WHERE id = :id)
        ");
        $stmt->execute([':rid' => $restauranteId, ':data' => $data, ':id' => $esperaId]);
        return (int)$stmt->fetchColumn();
    }

    /** A fila de um dia, por ordem de chegada. */
    public function listar(int $restauranteId, string $data = '', bool $incluirResolvidas = false): array
    {
        $sql = "
            SELECT id, nome_cliente, email_cliente, telefone_cliente, data_reserva, hora_reserva,
                   quantidade_pessoas, observacoes, status, avisado_em, reserva_id, criado_em
            FROM reservas_espera
            WHERE restaurante_id = :rid
        ";
        $params = [':rid' => $restauranteId];

        if ($data !== '') {
            $sql .= " AND data_reserva = :data";
            $params[':data'] = $data;
        } else {
            $sql .= " AND data_reserva >= CURDATE()";
        }

        if (!$incluirResolvidas) {
            $sql .= " AND status IN ('AGUARDA', 'AVISADO')";
        }

        $sql .= " ORDER BY data_reserva ASC, criado_em ASC LIMIT 200";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Procura quem aproveita uma vaga que se libertou.
     *
     * Serve quem cabe na mesa e queria uma hora proxima da que vagou. Sem a
     * margem de horas, um cancelamento as 20h nunca serviria quem pediu
     * 20h30, o que na pratica deixaria a funcionalidade sem efeito.
     */
    public function proximoDaFila(int $restauranteId, string $data, string $hora, int $capacidadeMesa): ?array
    {
        if (strlen($hora) === 5) {
            $hora .= ':00';
        }

        $stmt = $this->db->prepare("
            SELECT * FROM reservas_espera
            WHERE restaurante_id = :rid
              AND data_reserva = :data
              AND status = 'AGUARDA'
              AND quantidade_pessoas <= :cap
              AND ABS(TIME_TO_SEC(hora_reserva) - TIME_TO_SEC(:hora)) <= :margem
            ORDER BY criado_em ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':rid' => $restauranteId,
            ':data' => $data,
            ':cap' => $capacidadeMesa,
            ':hora' => $hora,
            // TIMESTAMPDIFF devolve nulo entre valores do tipo TIME, por isso
            // a comparacao e feita em segundos.
            ':margem' => self::MARGEM_MINUTOS * 60,
        ]);

        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha ?: null;
    }

    /** Marca que o cliente foi contactado sobre uma vaga. */
    public function marcarAvisado(int $esperaId, int $restauranteId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE reservas_espera
            SET status = 'AVISADO', avisado_em = NOW()
            WHERE id = :id AND restaurante_id = :rid AND status = 'AGUARDA'
        ");
        $stmt->execute([':id' => $esperaId, ':rid' => $restauranteId]);
        return $stmt->rowCount() > 0;
    }

    /** Liga a espera a reserva que dela nasceu. */
    public function marcarConvertida(int $esperaId, int $restauranteId, int $reservaId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE reservas_espera
            SET status = 'CONVERTIDA', reserva_id = :reserva
            WHERE id = :id AND restaurante_id = :rid AND status IN ('AGUARDA', 'AVISADO')
        ");
        $stmt->execute([':reserva' => $reservaId, ':id' => $esperaId, ':rid' => $restauranteId]);
        return $stmt->rowCount() > 0;
    }

    public function cancelar(int $esperaId, int $restauranteId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE reservas_espera
            SET status = 'CANCELADA'
            WHERE id = :id AND restaurante_id = :rid AND status IN ('AGUARDA', 'AVISADO')
        ");
        $stmt->execute([':id' => $esperaId, ':rid' => $restauranteId]);
        return $stmt->rowCount() > 0;
    }

    public function obter(int $esperaId, int $restauranteId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM reservas_espera WHERE id = :id AND restaurante_id = :rid");
        $stmt->execute([':id' => $esperaId, ':rid' => $restauranteId]);
        $linha = $stmt->fetch(PDO::FETCH_ASSOC);
        return $linha ?: null;
    }
}
