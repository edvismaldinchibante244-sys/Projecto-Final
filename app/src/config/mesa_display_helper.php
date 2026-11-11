<?php
/**
 * Helpers de exibição / identificação de mesas e balcões.
 */
if (!function_exists('mesa_normalizar_numero')) {
    function mesa_normalizar_numero($numero): string
    {
        $numero = trim((string)$numero);
        $map = [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c',
        ];
        return strtolower(strtr($numero, $map));
    }
}

if (!function_exists('mesa_eh_balcao')) {
    function mesa_eh_balcao($numero): bool
    {
        $n = mesa_normalizar_numero($numero);
        if ($n === '' || $n === '0' || $n === 'balcao' || $n === 'balcão') {
            return true;
        }
        if (preg_match('/^mesa\s*0+$/', $n)) {
            return true;
        }
        if (preg_match('/^mesa\s*(balcao|balcão)$/', $n)) {
            return true;
        }
        if (preg_match('/^(take\s*away|takeaway|para\s+levar|pra\s+levar)$/', $n)) {
            return true;
        }
        if (preg_match('/^mesa\s*(take\s*away|takeaway|para\s+levar|pra\s+levar)$/', $n)) {
            return true;
        }
        // B1, B2, balcao1, balcao 2, balcão 3
        if (preg_match('/^b\d+$/', $n)) {
            return true;
        }
        if (preg_match('/^balcao\s*\d*$/', $n)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('mesa_indice_balcao')) {
    function mesa_indice_balcao($numero): int
    {
        $n = mesa_normalizar_numero($numero);
        if ($n === '0' || $n === 'balcao' || $n === 'balcão' || preg_match('/^mesa\s*0+$/', $n)) {
            return 1;
        }
        if (preg_match('/^b(\d+)$/', $n, $m)) {
            return max(1, (int)$m[1]);
        }
        if (preg_match('/^balcao\s*(\d+)$/', $n, $m)) {
            return max(1, (int)$m[1]);
        }
        return 0;
    }
}

if (!function_exists('mesa_rotulo')) {
    /**
     * Rótulo humano: "Mesa 5", "Balcão / Take-away" ou, para o segundo
     * balcão, o nome configurado (ex.: "Take-away").
     */
    function mesa_rotulo($numero, ?string $nomeSegundoBalcao = null): string
    {
        $raw = trim((string)$numero);
        if (!mesa_eh_balcao($raw)) {
            return 'Mesa ' . $raw;
        }
        $indice = mesa_indice_balcao($raw);
        if ($indice >= 2) {
            $nome = trim((string)$nomeSegundoBalcao);
            return $nome !== '' ? $nome : 'Balcão ' . $indice;
        }
        return 'Balcão / Take-away';
    }
}

if (!function_exists('mesa_proximo_numero_balcao')) {
    /**
     * Gera próximo código único de balcão: B1, B2, B3...
     */
    function mesa_proximo_numero_balcao(PDO $db, int $restauranteId): string
    {
        $stmt = $db->prepare("SELECT numero FROM mesas WHERE restaurante_id = :rid");
        $stmt->execute([':rid' => $restauranteId]);
        $max = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $numero) {
            if (!mesa_eh_balcao((string)$numero)) {
                continue;
            }
            $max = max($max, mesa_indice_balcao((string)$numero));
        }
        return 'B' . ($max + 1);
    }
}

if (!function_exists('mesa_contar_balcoes_lista')) {
    /**
     * Conta balcões numa lista de registos (ou números).
     *
     * @param array $mesas Lista de arrays com chave "numero" ou strings.
     */
    function mesa_contar_balcoes_lista(array $mesas): int
    {
        $total = 0;
        foreach ($mesas as $item) {
            $numero = is_array($item) ? (string)($item['numero'] ?? '') : (string)$item;
            if (mesa_eh_balcao($numero)) {
                $total++;
            }
        }
        return $total;
    }
}

if (!function_exists('mesa_balcao_canonico_lista')) {
    /**
     * Retorna somente o primeiro balcão da lista. A consulta deve vir ordenada por id.
     *
     * @return array|string|null
     */
    function mesa_balcao_canonico_lista(array $mesas)
    {
        foreach ($mesas as $item) {
            $numero = is_array($item) ? (string)($item['numero'] ?? '') : (string)$item;
            if (mesa_eh_balcao($numero)) {
                return $item;
            }
        }

        return null;
    }
}

if (!function_exists('mesa_obter_balcao_canonico')) {
    function mesa_obter_balcao_canonico(PDO $db, int $restauranteId): ?array
    {
        if ($restauranteId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT id, restaurante_id, numero, capacidade, status
            FROM mesas
            WHERE restaurante_id = :rid
            ORDER BY id ASC
        ");
        $stmt->execute([':rid' => $restauranteId]);
        $balcao = mesa_balcao_canonico_lista($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        return is_array($balcao) ? $balcao : null;
    }
}

if (!function_exists('mesa_garantir_balcao_canonico')) {
    /**
     * Garante o canal técnico único de Balcão / Take-away.
     * A capacidade existe apenas por compatibilidade com o schema legado.
     */
    function mesa_garantir_balcao_canonico(PDO $db, int $restauranteId): array
    {
        $existente = mesa_obter_balcao_canonico($db, $restauranteId);
        if ($existente) {
            return $existente + ['created' => false];
        }

        $stmt = $db->prepare("
            INSERT INTO mesas (restaurante_id, numero, capacidade, status)
            VALUES (:rid, 'B1', 1, 'LIVRE')
        ");
        $stmt->execute([':rid' => $restauranteId]);

        return [
            'id' => (int)$db->lastInsertId(),
            'restaurante_id' => $restauranteId,
            'numero' => 'B1',
            'capacidade' => 1,
            'status' => 'LIVRE',
            'created' => true,
        ];
    }
}

if (!function_exists('mesa_filtrar_operacionais')) {
    /**
     * Mantém todas as mesas físicas e no máximo $maxBalcoes balcões
     * (por omissão só o canónico, como sempre foi).
     */
    function mesa_filtrar_operacionais(array $mesas, int $maxBalcoes = 1): array
    {
        $maxBalcoes = max(1, $maxBalcoes);
        $resultado = [];
        $balcoesIncluidos = 0;

        foreach ($mesas as $item) {
            $numero = is_array($item) ? (string)($item['numero'] ?? '') : (string)$item;
            if (!mesa_eh_balcao($numero)) {
                $resultado[] = $item;
                continue;
            }
            if ($balcoesIncluidos < $maxBalcoes) {
                $resultado[] = $item;
                $balcoesIncluidos++;
            }
        }

        return $resultado;
    }
}
