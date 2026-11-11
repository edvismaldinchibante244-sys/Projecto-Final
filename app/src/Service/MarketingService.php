<?php

/*
   Service: MarketingService
   Lógica de negócio para marketing e fidelidade
 */

namespace App\Service;

class MarketingService
{
    private $db;
    private $modelMarketing;

    public function __construct($database, $modelMarketing)
    {
        $this->db = $database;
        $this->modelMarketing = $modelMarketing;
    }

    /**
     * Processar desconto de cupom
     */
    public function processarCupom($restaurante_id, $codigo, $valor_venda)
    {
        $cupom = $this->modelMarketing->validarCupom($restaurante_id, $codigo);

        if (!$cupom) {
            return ['sucesso' => false, 'mensagem' => 'Cupom inválido ou expirado'];
        }

        // Calcular desconto
        if ($cupom['tipo'] === 'percentual') {
            $desconto = ($valor_venda * $cupom['valor_desconto']) / 100;
        } else {
            $desconto = min($cupom['valor_desconto'], $valor_venda); // Não exceder valor da venda
        }

        // Usar cupom
        $this->modelMarketing->usarCupom($cupom['id']);

        return [
            'sucesso' => true,
            'desconto' => $desconto,
            'valor_final' => $valor_venda - $desconto,
            'cupom_id' => $cupom['id']
        ];
    }

    /**
     * Processar ganho de pontos na venda
     */
    public function processarGanhoPontos($cliente_id, $restaurante_id, $valor_venda, $venda_id)
    {
        // Calcular pontos: 1 ponto a cada MZN 10
        $pontos_ganhos = intval($valor_venda / 10);

        if ($pontos_ganhos > 0) {
            return $this->modelMarketing->creditarPontos(
                $cliente_id,
                $restaurante_id,
                $pontos_ganhos,
                'Compra realizada',
                $venda_id
            );
        }

        return false;
    }

    /**
     * Gerar cupom personalizado
     */
    public function gerarCupomPersonalizado($restaurante_id, $cliente_id, $tipo_promocao)
    {
        $codigo = strtoupper(substr(uniqid(), -8));
        $valor_desconto = 0;

        switch ($tipo_promocao) {
            case 'aniversario':
                $valor_desconto = 15; // 15% de desconto
                $dias_validade = 7;
                break;
            case 'reativacao':
                $valor_desconto = 10;
                $dias_validade = 14;
                break;
            case 'vip_reward':
                $valor_desconto = 20;
                $dias_validade = 30;
                break;
            default:
                $valor_desconto = 5;
                $dias_validade = 7;
        }

        $dados_cupom = [
            'restaurante_id' => $restaurante_id,
            'cliente_id' => $cliente_id,
            'codigo' => $codigo,
            'descricao' => "Promoção {$tipo_promocao}",
            'tipo' => 'percentual',
            'valor_desconto' => $valor_desconto,
            'quantidade_total' => 1,
            'data_inicio' => date('Y-m-d'),
            'data_fim' => date('Y-m-d', strtotime("+{$dias_validade} days"))
        ];

        $this->modelMarketing->criarCupom($dados_cupom);

        return $codigo;
    }

    /**
     * Executar campanhas automáticas noturnas
     * (Deve ser chamada via cron job)
     */
    public function executarCampanhasNoturnas($restaurante_id)
    {
        $resultados = [];

        // Campaign 1: Aniversariantes do mês
        $query_aniversario = "SELECT id, email, telefone, nome FROM clientes 
                             WHERE restaurante_id = :restaurante_id 
                             AND data_nascimento IS NOT NULL
                             AND MONTH(data_nascimento) = MONTH(CURDATE())
                             AND DAY(data_nascimento) = DAY(CURDATE())";

        $stmt_aniver = $this->db->prepare($query_aniversario);
        $stmt_aniver->execute([':restaurante_id' => $restaurante_id]);
        $aniversariantes = $stmt_aniver->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($aniversariantes as $cliente) {
            $cupom = $this->gerarCupomPersonalizado($restaurante_id, $cliente['id'], 'aniversario');
            $resultados['aniversario'][] = $cupom;
            // TODO: Enviar WhatsApp/SMS com cupom
        }

        // Campaign 2: Clientes inativos (última visita > 30 dias)
        $query_inativo = "SELECT id, telefone FROM clientes 
                         WHERE restaurante_id = :restaurante_id 
                         AND data_ultima_visita < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                         AND ativo = TRUE
                         AND bloqueado = FALSE";

        $stmt_inativo = $this->db->prepare($query_inativo);
        $stmt_inativo->execute([':restaurante_id' => $restaurante_id]);
        $inativos = $stmt_inativo->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($inativos as $cliente) {
            $cupom = $this->gerarCupomPersonalizado($restaurante_id, $cliente['id'], 'reativacao');
            $resultados['reativacao'][] = $cupom;
            // TODO: Enviar campanha de reativação
        }

        return $resultados;
    }

    /**
     * Calcular benefício de pontos gastos
     */
    public function converterPontosEmDesconto($pontos)
    {
        // 1 ponto = MZN 1 em desconto
        return $pontos;
    }

    /**
     * Atualizar nível de fidelidade
     */
    public function atualizarNivelFidelidade($cliente_id, $restaurante_id)
    {
        $saldo = $this->modelMarketing->obterSaldoPontos($cliente_id, $restaurante_id);

        if (!$saldo) return false;

        $novo_nivel = 'bronze';
        if ($saldo['saldo_pontos'] >= 1000) {
            $novo_nivel = 'platina';
        } elseif ($saldo['saldo_pontos'] >= 500) {
            $novo_nivel = 'ouro';
        } elseif ($saldo['saldo_pontos'] >= 250) {
            $novo_nivel = 'prata';
        }

        $query = "UPDATE pontos_fidelidade 
                  SET nivel_cliente = :nivel 
                  WHERE cliente_id = :cliente_id 
                  AND restaurante_id = :restaurante_id";

        $stmt = $this->db->prepare($query);
        return $stmt->execute([
            ':nivel' => $novo_nivel,
            ':cliente_id' => $cliente_id,
            ':restaurante_id' => $restaurante_id
        ]);
    }
}
