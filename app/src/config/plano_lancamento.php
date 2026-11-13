<?php

/**
 * Preco de lancamento.
 *
 * A tabela de precos (planos.php) nunca muda: e a referencia de valor e o que
 * aparece rasurado ao lado do preco promocional. O desconto vive aqui.
 *
 * Quem adere durante a campanha mantem o desconto enquanto for cliente, por
 * isso a percentagem fica gravada no restaurante (restaurantes.desconto_lancamento).
 * Acabada a campanha, esses clientes continuam a pagar o que lhes foi prometido
 * e os novos passam a pagar a tabela.
 *
 * Configuracao por variaveis de ambiente:
 *   LANCAMENTO_ATE       data (YYYY-MM-DD) do ultimo dia da campanha. Vazio = sem campanha.
 *   LANCAMENTO_DESCONTO  percentagem, 1 a 70. Omitida = 30.
 */

if (!function_exists('lancamento_fim')) {
    /** Ultimo dia da campanha, ou null se nao houver campanha configurada. */
    function lancamento_fim(): ?DateTimeImmutable
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }

        $bruto = trim((string)(getenv('LANCAMENTO_ATE') ?: ''));
        if ($bruto === '') {
            return $cache = null;
        }

        try {
            $fuso = new DateTimeZone(trim((string)(getenv('APP_TIMEZONE') ?: '')) ?: 'UTC');
            $data = new DateTimeImmutable($bruto . ' 23:59:59', $fuso);
        } catch (Throwable $e) {
            return $cache = null;
        }

        return $cache = $data;
    }
}

if (!function_exists('lancamento_desconto_campanha')) {
    /** Percentagem em vigor hoje para quem ainda nao e cliente. 0 se a campanha acabou. */
    function lancamento_desconto_campanha(): float
    {
        $fim = lancamento_fim();
        if ($fim === null) {
            return 0.0;
        }

        try {
            $agora = new DateTimeImmutable('now', $fim->getTimezone());
        } catch (Throwable $e) {
            return 0.0;
        }

        if ($agora > $fim) {
            return 0.0;
        }

        $pct = (float)(getenv('LANCAMENTO_DESCONTO') ?: 30);
        if ($pct <= 0 || $pct > 70) {
            return 0.0;
        }

        return $pct;
    }
}

if (!function_exists('lancamento_activo')) {
    function lancamento_activo(): bool
    {
        return lancamento_desconto_campanha() > 0;
    }
}

if (!function_exists('lancamento_desconto_restaurante')) {
    /**
     * Desconto a aplicar a um restaurante: o que ficou preso ao aderir e, na
     * falta dele, o da campanha a decorrer. Nunca os dois somados.
     */
    function lancamento_desconto_restaurante(?PDO $db, $restauranteId): float
    {
        $restauranteId = (int)$restauranteId;
        if ($db instanceof PDO && $restauranteId > 0) {
            try {
                $stmt = $db->prepare("SELECT desconto_lancamento FROM restaurantes WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $restauranteId]);
                $guardado = $stmt->fetchColumn();
                if ($guardado !== false && $guardado !== null && (float)$guardado > 0) {
                    return min(70.0, (float)$guardado);
                }
            } catch (Throwable $e) {
                // Coluna ainda sem migracao aplicada: segue para a campanha.
            }
        }

        return lancamento_desconto_campanha();
    }
}

if (!function_exists('lancamento_registar')) {
    /**
     * Prende o desconto da campanha a um restaurante, uma unica vez.
     * Chamado quando o restaurante e criado e tambem no primeiro pagamento,
     * para apanhar contas criadas antes de a campanha comecar.
     */
    function lancamento_registar(?PDO $db, $restauranteId): void
    {
        $restauranteId = (int)$restauranteId;
        $pct = lancamento_desconto_campanha();
        if (!$db instanceof PDO || $restauranteId <= 0 || $pct <= 0) {
            return;
        }

        try {
            $stmt = $db->prepare("UPDATE restaurantes
                SET desconto_lancamento = :pct, desconto_lancamento_em = NOW()
                WHERE id = :id AND (desconto_lancamento IS NULL OR desconto_lancamento <= 0)");
            $stmt->execute([':pct' => $pct, ':id' => $restauranteId]);
        } catch (Throwable $e) {
            // Sem a migracao aplicada nao ha onde guardar; a campanha continua
            // a valer pela data, so nao fica presa ao cliente.
        }
    }
}

if (!function_exists('lancamento_preco')) {
    /** Aplica a percentagem a um valor, arredondando a dezena mais proxima. */
    function lancamento_preco(float $valorTabela, float $desconto): float
    {
        if ($desconto <= 0 || $valorTabela <= 0) {
            return $valorTabela;
        }

        $final = $valorTabela * (1 - ($desconto / 100));

        // Arredonda a dezena para nao mostrar precos como 1.399,30.
        return max(0.0, round($final / 10) * 10);
    }
}

if (!function_exists('lancamento_preco_tabela')) {
    /**
     * Preco de tabela de um plano num ciclo, sem desconto nenhum.
     * Unica porta de entrada ao catalogo: quem precisa do preco a cobrar
     * usa isto e aplica depois lancamento_preco() com o desconto do cliente.
     */
    function lancamento_preco_tabela(string $plano, string $ciclo): float
    {
        static $planos = null;
        if ($planos === null) {
            $planos = require __DIR__ . '/planos.php';
        }

        $plano = strtoupper(trim($plano));
        if ($plano === 'ENTERPRISE') {
            $plano = 'EMPRESARIAL';
        }

        $chave = ['MENSAL' => 'mensal', 'TRIMESTRAL' => 'trimestral', 'ANUAL' => 'anual'][strtoupper(trim($ciclo))] ?? 'mensal';

        return (float)($planos[$plano]['precos'][$chave] ?? 0);
    }
}

if (!function_exists('lancamento_meses_ciclo')) {
    /** Quantos meses cobre um ciclo de facturacao. */
    function lancamento_meses_ciclo(string $ciclo): int
    {
        return ['MENSAL' => 1, 'TRIMESTRAL' => 3, 'ANUAL' => 12][strtoupper(trim($ciclo))] ?? 1;
    }
}

if (!function_exists('lancamento_precos_plano')) {
    /**
     * Precos de um plano nos tres ciclos, com e sem desconto.
     * Devolve ['tabela' => [...], 'final' => [...], 'desconto' => float].
     */
    function lancamento_precos_plano(array $precosTabela, float $desconto): array
    {
        $final = [];
        foreach (['mensal', 'trimestral', 'anual'] as $ciclo) {
            $valor = (float)($precosTabela[$ciclo] ?? 0);
            $final[$ciclo] = lancamento_preco($valor, $desconto);
        }

        return [
            'tabela' => $precosTabela,
            'final' => $final,
            'desconto' => $desconto,
        ];
    }
}
