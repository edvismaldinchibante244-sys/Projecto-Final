<?php

require_once __DIR__ . '/plano_check.php';
require_once __DIR__ . '/restaurante_context.php';

if (!function_exists('filiais_restaurantes_colunas')) {
    function filiais_restaurantes_colunas(PDO $db): array
    {
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $cache = [];
        $stmt = $db->query('SHOW COLUMNS FROM restaurantes');
        while ($coluna = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cache[] = $coluna['Field'];
        }

        return $cache;
    }
}

if (!function_exists('filiais_limpar_texto')) {
    function filiais_limpar_texto($valor, int $limite = 255): string
    {
        $valor = trim((string)$valor);
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;

        if (function_exists('mb_substr')) {
            return mb_substr($valor, 0, $limite);
        }

        return substr($valor, 0, $limite);
    }
}

if (!function_exists('filiais_normalizar_status')) {
    function filiais_normalizar_status($status): string
    {
        $status = strtoupper(trim((string)$status));
        return $status === 'INATIVO' ? 'INATIVO' : 'ATIVO';
    }
}

if (!function_exists('filiais_obter_restaurante')) {
    function filiais_obter_restaurante(PDO $db, int $restauranteId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM restaurantes WHERE id = ? LIMIT 1');
        $stmt->execute([$restauranteId]);
        $restaurante = $stmt->fetch(PDO::FETCH_ASSOC);

        return $restaurante ?: null;
    }
}

if (!function_exists('filiais_obter_contexto')) {
    function filiais_obter_contexto(PDO $db, int $restauranteAtualId, int $matrizSessionId = 0): array
    {
        if ($restauranteAtualId <= 0) {
            throw new RuntimeException('Restaurante atual invalido.');
        }

        $restauranteAtual = filiais_obter_restaurante($db, $restauranteAtualId);
        if (!$restauranteAtual) {
            throw new RuntimeException('Restaurante atual nao encontrado.');
        }

        $restauranteAtualEhMatriz = ((int)($restauranteAtual['is_matriz'] ?? 1)) === 1;
        $matrizId = $restauranteAtualEhMatriz
            ? (int)$restauranteAtual['id']
            : (int)($restauranteAtual['filial_id'] ?? 0);

        if (!$restauranteAtualEhMatriz && $matrizId <= 0 && $matrizSessionId > 0) {
            $matrizId = $matrizSessionId;
        }

        if ($matrizId <= 0) {
            throw new RuntimeException('Matriz vinculada a esta filial nao foi encontrada.');
        }

        $matriz = filiais_obter_restaurante($db, $matrizId);
        if (!$matriz) {
            throw new RuntimeException('Matriz principal nao encontrada.');
        }

        if (((int)($matriz['is_matriz'] ?? 0)) !== 1) {
            throw new RuntimeException('O restaurante principal informado nao e uma matriz valida.');
        }

        if (!$restauranteAtualEhMatriz && (int)($restauranteAtual['filial_id'] ?? 0) !== $matrizId) {
            throw new RuntimeException('A filial atual nao pertence a matriz informada.');
        }

        return [
            'restaurante_atual' => $restauranteAtual,
            'restaurante_atual_eh_matriz' => $restauranteAtualEhMatriz,
            'matriz' => $matriz,
            'matriz_id' => $matrizId,
            'tem_multi_filial' => plano_tem_funcionalidade_db($matrizId, 'multi_filial'),
            'plano' => plano_get_dados($matrizId),
        ];
    }
}

if (!function_exists('filiais_exigir_matriz')) {
    function filiais_exigir_matriz(array $contexto, string $mensagem = 'Somente a matriz pode gerir filiais.'): void
    {
        if (empty($contexto['restaurante_atual_eh_matriz'])) {
            throw new RuntimeException($mensagem);
        }
    }
}

if (!function_exists('filiais_obter_por_id')) {
    function filiais_obter_por_id(PDO $db, int $matrizId, int $filialId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM restaurantes WHERE id = ? AND filial_id = ? AND is_matriz = 0 LIMIT 1');
        $stmt->execute([$filialId, $matrizId]);
        $filial = $stmt->fetch(PDO::FETCH_ASSOC);

        return $filial ?: null;
    }
}

if (!function_exists('filiais_contar')) {
    function filiais_contar(PDO $db, int $matrizId): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM restaurantes WHERE filial_id = ? AND is_matriz = 0');
        $stmt->execute([$matrizId]);

        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('filiais_validar_dados')) {
    function filiais_validar_dados(array $dados, bool $incluirStatus = true): array
    {
        $nome = filiais_limpar_texto($dados['nome_filial'] ?? $dados['nome'] ?? '', 120);
        if ($nome === '') {
            throw new InvalidArgumentException('O nome da filial e obrigatorio.');
        }

        $email = filiais_limpar_texto($dados['email_filial'] ?? $dados['email'] ?? '', 160);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um email valido para a filial.');
        }

        $telefone = filiais_limpar_texto($dados['telefone_filial'] ?? $dados['telefone'] ?? '', 40);
        $endereco = filiais_limpar_texto($dados['endereco_filial'] ?? $dados['endereco'] ?? '', 180);
        $cidade = filiais_limpar_texto($dados['cidade_filial'] ?? $dados['cidade'] ?? '', 80);

        $payload = [
            'nome' => $nome,
            'email' => $email,
            'telefone' => $telefone,
            'endereco' => $endereco,
            'cidade' => $cidade,
        ];

        if ($incluirStatus) {
            $payload['status'] = filiais_normalizar_status($dados['status'] ?? 'ATIVO');
        }

        return $payload;
    }
}

if (!function_exists('filiais_email_em_uso')) {
    function filiais_email_em_uso(PDO $db, string $email, int $ignorarRestauranteId = 0): bool
    {
        if ($email === '') {
            return false;
        }

        $sql = 'SELECT id FROM restaurantes WHERE email_lookup = ?';
        $parametros = [personal_data_email_lookup($email)];

        if ($ignorarRestauranteId > 0) {
            $sql .= ' AND id <> ?';
            $parametros[] = $ignorarRestauranteId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute($parametros);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('filiais_nome_duplicado')) {
    function filiais_nome_duplicado(PDO $db, int $matrizId, string $nome, int $ignorarFilialId = 0): bool
    {
        $sql = 'SELECT id FROM restaurantes WHERE filial_id = ? AND is_matriz = 0 AND LOWER(nome) = LOWER(?)';
        $parametros = [$matrizId, $nome];

        if ($ignorarFilialId > 0) {
            $sql .= ' AND id <> ?';
            $parametros[] = $ignorarFilialId;
        }

        $sql .= ' LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute($parametros);

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('filiais_validar_limite')) {
    function filiais_validar_limite(PDO $db, int $matrizId): array
    {
        $quantidadeAtual = filiais_contar($db, $matrizId);
        return plano_verificar_limite_db($matrizId, 'filiais', $quantidadeAtual);
    }
}

if (!function_exists('filiais_mensagem_limite')) {
    function filiais_mensagem_limite(array $verificacao): string
    {
        $plano = $verificacao['plano'] ?? 'atual';
        $limite = (int)($verificacao['limite'] ?? 0);
        return "Limite de filiais atingido. O plano {$plano} permite ate {$limite} filiais.";
    }
}

if (!function_exists('filiais_criar')) {
    function filiais_criar(PDO $db, int $matrizId, array $dados): array
    {
        $payload = filiais_validar_dados($dados);
        $verificacao = filiais_validar_limite($db, $matrizId);
        if (!$verificacao['permitido']) {
            return [
                'success' => false,
                'message' => filiais_mensagem_limite($verificacao),
                'code' => 'LIMITE_FILIAIS',
            ];
        }

        if (filiais_nome_duplicado($db, $matrizId, $payload['nome'])) {
            return [
                'success' => false,
                'message' => 'Ja existe uma filial com este nome na sua matriz.',
                'code' => 'FILIAL_DUPLICADA',
            ];
        }

        if (filiais_email_em_uso($db, $payload['email'])) {
            return [
                'success' => false,
                'message' => 'Este email ja esta associado a outro restaurante ou filial.',
                'code' => 'EMAIL_EM_USO',
            ];
        }

        $matriz = filiais_obter_restaurante($db, $matrizId);
        if (!$matriz) {
            return [
                'success' => false,
                'message' => 'Matriz principal nao encontrada para concluir a criacao.',
            ];
        }

        $colunasTabela = filiais_restaurantes_colunas($db);
        $colunas = ['nome', 'email', 'email_lookup', 'telefone', 'endereco', 'cidade', 'filial_id', 'is_matriz', 'status'];
        $placeholders = [':nome', ':email', ':email_lookup', ':telefone', ':endereco', ':cidade', ':filial_id', ':is_matriz', ':status'];
        $parametros = [
            ':nome' => $payload['nome'],
            ':email' => $payload['email'] !== '' ? personal_data_encrypt($payload['email']) : null,
            ':email_lookup' => $payload['email'] !== '' ? personal_data_email_lookup($payload['email']) : null,
            ':telefone' => $payload['telefone'] !== '' ? personal_data_encrypt($payload['telefone']) : null,
            ':endereco' => $payload['endereco'] !== '' ? personal_data_encrypt($payload['endereco']) : null,
            ':cidade' => $payload['cidade'] !== '' ? $payload['cidade'] : null,
            ':filial_id' => $matrizId,
            ':is_matriz' => 0,
            ':status' => $payload['status'],
        ];

        foreach (['plano', 'plano_id', 'data_inicio', 'data_fim'] as $colunaOpcional) {
            if (in_array($colunaOpcional, $colunasTabela, true) && array_key_exists($colunaOpcional, $matriz)) {
                $colunas[] = $colunaOpcional;
                $placeholder = ':' . $colunaOpcional;
                $placeholders[] = $placeholder;
                $parametros[$placeholder] = $matriz[$colunaOpcional];
            }
        }

        if (in_array('created_at', $colunasTabela, true)) {
            $colunas[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        $sql = 'INSERT INTO restaurantes (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $db->prepare($sql);
        $stmt->execute($parametros);
        $filialId = (int)$db->lastInsertId();

        try {
            filiais_sincronizar_cardapio_da_matriz($db, $matrizId, $filialId);
        } catch (Throwable $e) {
            error_log('[FILIAIS][CRIAR][SYNC_CARDAPIO] ' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'Filial criada com sucesso.',
            'filial_id' => $filialId,
        ];
    }
}

if (!function_exists('filiais_atualizar')) {
    function filiais_atualizar(PDO $db, int $matrizId, int $filialId, array $dados): array
    {
        if ($filialId <= 0) {
            return [
                'success' => false,
                'message' => 'Filial invalida.',
            ];
        }

        $filial = filiais_obter_por_id($db, $matrizId, $filialId);
        if (!$filial) {
            return [
                'success' => false,
                'message' => 'Filial nao encontrada para atualizacao.',
            ];
        }

        $payload = filiais_validar_dados($dados);
        $payload['status'] = filiais_normalizar_status($dados['status'] ?? ($filial['status'] ?? 'ATIVO'));

        if (filiais_nome_duplicado($db, $matrizId, $payload['nome'], $filialId)) {
            return [
                'success' => false,
                'message' => 'Ja existe outra filial com este nome na sua matriz.',
                'code' => 'FILIAL_DUPLICADA',
            ];
        }

        if (filiais_email_em_uso($db, $payload['email'], $filialId)) {
            return [
                'success' => false,
                'message' => 'Este email ja esta associado a outro restaurante ou filial.',
                'code' => 'EMAIL_EM_USO',
            ];
        }

        $colunasTabela = filiais_restaurantes_colunas($db);
        $set = [
            'nome = :nome',
            'email = :email',
            'email_lookup = :email_lookup',
            'telefone = :telefone',
            'endereco = :endereco',
            'cidade = :cidade',
            'status = :status',
        ];
        $parametros = [
            ':nome' => $payload['nome'],
            ':email' => $payload['email'] !== '' ? personal_data_encrypt($payload['email']) : null,
            ':email_lookup' => $payload['email'] !== '' ? personal_data_email_lookup($payload['email']) : null,
            ':telefone' => $payload['telefone'] !== '' ? personal_data_encrypt($payload['telefone']) : null,
            ':endereco' => $payload['endereco'] !== '' ? personal_data_encrypt($payload['endereco']) : null,
            ':cidade' => $payload['cidade'] !== '' ? $payload['cidade'] : null,
            ':status' => $payload['status'],
            ':id' => $filialId,
            ':filial_id' => $matrizId,
        ];

        if (in_array('updated_at', $colunasTabela, true)) {
            $set[] = 'updated_at = NOW()';
        }

        $sql = 'UPDATE restaurantes SET ' . implode(', ', $set) . ' WHERE id = :id AND filial_id = :filial_id AND is_matriz = 0';
        $stmt = $db->prepare($sql);
        $stmt->execute($parametros);

        return [
            'success' => true,
            'message' => 'Filial atualizada com sucesso.',
        ];
    }
}

if (!function_exists('filiais_assumir_contexto')) {
    function filiais_assumir_contexto(PDO $db, int $matrizId, int $filialId): array
    {
        $filial = filiais_obter_por_id($db, $matrizId, $filialId);
        if (!$filial) {
            return [
                'success' => false,
                'message' => 'Filial invalida ou sem acesso.',
            ];
        }

        if (filiais_normalizar_status($filial['status'] ?? 'ATIVO') !== 'ATIVO') {
            return [
                'success' => false,
                'message' => 'Esta filial esta inativa e nao pode ser acessada.',
            ];
        }

        // BLOQUEADO (legado/lifecycle) nao e INATIVO: ao entrar, reabre como ATIVO se a matriz estiver ATIVA.
        $statusRaw = strtoupper(trim((string)($filial['status'] ?? 'ATIVO')));
        if ($statusRaw === 'BLOQUEADO') {
            $matriz = filiais_obter_restaurante($db, $matrizId);
            $matrizStatus = strtoupper(trim((string)($matriz['status'] ?? 'ATIVO')));
            if ($matrizStatus === 'ATIVO') {
                try {
                    $db->prepare("UPDATE restaurantes SET status = 'ATIVO' WHERE id = ? AND filial_id = ? LIMIT 1")
                        ->execute([$filialId, $matrizId]);
                    $filial['status'] = 'ATIVO';
                } catch (Throwable $e) {
                    error_log('[FILIAIS][ASSUMIR][REATIVAR] ' . $e->getMessage());
                }
            }
        }

        $_SESSION['matriz_id'] = $matrizId;
        $_SESSION['filial_id'] = $filialId;
        $_SESSION['filial_nome'] = (string)($filial['nome'] ?? '');
        $_SESSION['restaurante_id'] = $filialId;
        $_SESSION['restaurante_selecionado'] = $filialId;
        $_SESSION['restaurante_base_id'] = session_restaurante_auth_id() > 0
            ? session_restaurante_auth_id()
            : $matrizId;
        $_SESSION['last_presence_ping'] = 0;

        $sync = ['categorias_criadas' => 0, 'produtos_criados' => 0, 'produtos_atualizados' => 0];
        try {
            $sync = filiais_sincronizar_cardapio_da_matriz($db, $matrizId, $filialId);
        } catch (Throwable $e) {
            error_log('[FILIAIS][ASSUMIR][SYNC_CARDAPIO] ' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'Contexto da filial ativado.',
            'filial' => $filial,
            'cardapio_sync' => $sync,
        ];
    }
}

if (!function_exists('filiais_retornar_para_matriz')) {
    function filiais_retornar_para_matriz(int $matrizId): array
    {
        if ($matrizId <= 0) {
            return [
                'success' => false,
                'message' => 'Matriz invalida para restaurar o contexto.',
            ];
        }

        $_SESSION['restaurante_id'] = $matrizId;
        $_SESSION['restaurante_base_id'] = session_restaurante_auth_id() > 0
            ? session_restaurante_auth_id()
            : $matrizId;
        unset($_SESSION['matriz_id'], $_SESSION['filial_id'], $_SESSION['filial_nome'], $_SESSION['restaurante_selecionado']);
        $_SESSION['last_presence_ping'] = 0;

        return [
            'success' => true,
            'message' => 'Contexto da matriz restaurado.',
        ];
    }
}

if (!function_exists('filiais_sincronizar_cardapio_da_matriz')) {
    /**
     * Copia categorias e produtos activos da matriz para a filial (sem apagar os ja existentes).
     * Match por nome. Produtos novos entram com estoque 0 (stock local da filial).
     *
     * @return array{categorias_criadas:int,produtos_criados:int,produtos_atualizados:int}
     */
    function filiais_sincronizar_cardapio_da_matriz(PDO $db, int $matrizId, int $filialId): array
    {
        $resultado = [
            'categorias_criadas' => 0,
            'produtos_criados' => 0,
            'produtos_atualizados' => 0,
        ];

        if ($matrizId <= 0 || $filialId <= 0 || $matrizId === $filialId) {
            return $resultado;
        }

        $filial = filiais_obter_por_id($db, $matrizId, $filialId);
        if (!$filial) {
            return $resultado;
        }

        // --- Categorias ---
        $mapaCategorias = []; // matriz_categoria_id => filial_categoria_id
        $stmtCatMatriz = $db->prepare("
            SELECT id, nome, descricao, ativo
            FROM categorias
            WHERE restaurante_id = :rid
            ORDER BY id ASC
        ");
        $stmtCatMatriz->execute([':rid' => $matrizId]);
        $catsMatriz = $stmtCatMatriz->fetchAll(PDO::FETCH_ASSOC);

        $stmtCatFind = $db->prepare("
            SELECT id FROM categorias
            WHERE restaurante_id = :rid AND LOWER(TRIM(nome)) = LOWER(TRIM(:nome))
            LIMIT 1
        ");
        $stmtCatIns = $db->prepare("
            INSERT INTO categorias (restaurante_id, nome, descricao, ativo, filial_id)
            VALUES (:rid, :nome, :descricao, :ativo, :filial_id)
        ");

        foreach ($catsMatriz as $cat) {
            $stmtCatFind->execute([
                ':rid' => $filialId,
                ':nome' => (string)$cat['nome'],
            ]);
            $catFilialId = (int)($stmtCatFind->fetchColumn() ?: 0);
            if ($catFilialId <= 0) {
                try {
                    $stmtCatIns->execute([
                        ':rid' => $filialId,
                        ':nome' => (string)$cat['nome'],
                        ':descricao' => $cat['descricao'] ?? null,
                        ':ativo' => (int)($cat['ativo'] ?? 1),
                        ':filial_id' => $filialId,
                    ]);
                } catch (Throwable $e) {
                    // fallback sem coluna filial_id
                    $stmtCatIns2 = $db->prepare("
                        INSERT INTO categorias (restaurante_id, nome, descricao, ativo)
                        VALUES (:rid, :nome, :descricao, :ativo)
                    ");
                    $stmtCatIns2->execute([
                        ':rid' => $filialId,
                        ':nome' => (string)$cat['nome'],
                        ':descricao' => $cat['descricao'] ?? null,
                        ':ativo' => (int)($cat['ativo'] ?? 1),
                    ]);
                }
                $catFilialId = (int)$db->lastInsertId();
                $resultado['categorias_criadas']++;
            }
            $mapaCategorias[(int)$cat['id']] = $catFilialId;
        }

        // --- Produtos ---
        $colsProd = [];
        foreach ($db->query('SHOW COLUMNS FROM produtos') as $c) {
            $colsProd[] = $c['Field'];
        }

        $selectProd = ['id', 'nome', 'descricao', 'preco', 'ativo'];
        foreach (['categoria_id', 'custo', 'estoque_minimo', 'imagem', 'destaque', 'categoria'] as $colOp) {
            if (in_array($colOp, $colsProd, true)) {
                $selectProd[] = $colOp;
            }
        }

        $stmtProdMatriz = $db->prepare(
            'SELECT ' . implode(', ', $selectProd) . '
             FROM produtos
             WHERE restaurante_id = :rid AND ativo = 1
             ORDER BY id ASC'
        );
        $stmtProdMatriz->execute([':rid' => $matrizId]);
        $prodsMatriz = $stmtProdMatriz->fetchAll(PDO::FETCH_ASSOC);

        $stmtProdFind = $db->prepare("
            SELECT id FROM produtos
            WHERE restaurante_id = :rid AND LOWER(TRIM(nome)) = LOWER(TRIM(:nome))
            LIMIT 1
        ");

        foreach ($prodsMatriz as $prod) {
            $stmtProdFind->execute([
                ':rid' => $filialId,
                ':nome' => (string)$prod['nome'],
            ]);
            $prodFilialId = (int)($stmtProdFind->fetchColumn() ?: 0);
            $categoriaFilial = null;
            if (!empty($prod['categoria_id']) && isset($mapaCategorias[(int)$prod['categoria_id']])) {
                $categoriaFilial = $mapaCategorias[(int)$prod['categoria_id']];
            }

            if ($prodFilialId > 0) {
                // Actualiza dados de cardapio (preco/descricao/activo), mantém estoque local
                $sets = [
                    'descricao = :descricao',
                    'preco = :preco',
                    'ativo = :ativo',
                ];
                $params = [
                    ':descricao' => $prod['descricao'] ?? null,
                    ':preco' => $prod['preco'] ?? 0,
                    ':ativo' => (int)($prod['ativo'] ?? 1),
                    ':id' => $prodFilialId,
                    ':rid' => $filialId,
                ];
                if (in_array('custo', $colsProd, true)) {
                    $sets[] = 'custo = :custo';
                    $params[':custo'] = $prod['custo'] ?? 0;
                }
                if (in_array('estoque_minimo', $colsProd, true)) {
                    $sets[] = 'estoque_minimo = :estoque_minimo';
                    $params[':estoque_minimo'] = $prod['estoque_minimo'] ?? 0;
                }
                if ($categoriaFilial && in_array('categoria_id', $colsProd, true)) {
                    $sets[] = 'categoria_id = :categoria_id';
                    $params[':categoria_id'] = $categoriaFilial;
                }
                if (in_array('imagem', $colsProd, true)) {
                    $sets[] = 'imagem = :imagem';
                    $params[':imagem'] = $prod['imagem'] ?? null;
                }
                if (in_array('destaque', $colsProd, true)) {
                    $sets[] = 'destaque = :destaque';
                    $params[':destaque'] = (int)($prod['destaque'] ?? 0);
                }
                $sqlUp = 'UPDATE produtos SET ' . implode(', ', $sets) . ' WHERE id = :id AND restaurante_id = :rid';
                $db->prepare($sqlUp)->execute($params);
                $resultado['produtos_atualizados']++;
                continue;
            }

            $insertCols = ['restaurante_id', 'nome', 'descricao', 'preco', 'ativo'];
            $insertVals = [':rid', ':nome', ':descricao', ':preco', ':ativo'];
            $paramsIns = [
                ':rid' => $filialId,
                ':nome' => (string)$prod['nome'],
                ':descricao' => $prod['descricao'] ?? null,
                ':preco' => $prod['preco'] ?? 0,
                ':ativo' => (int)($prod['ativo'] ?? 1),
            ];
            if (in_array('estoque', $colsProd, true)) {
                $insertCols[] = 'estoque';
                $insertVals[] = ':estoque';
                $paramsIns[':estoque'] = 0;
            }
            if (in_array('custo', $colsProd, true)) {
                $insertCols[] = 'custo';
                $insertVals[] = ':custo';
                $paramsIns[':custo'] = $prod['custo'] ?? 0;
            }
            if (in_array('estoque_minimo', $colsProd, true)) {
                $insertCols[] = 'estoque_minimo';
                $insertVals[] = ':estoque_minimo';
                $paramsIns[':estoque_minimo'] = $prod['estoque_minimo'] ?? 0;
            }
            if ($categoriaFilial && in_array('categoria_id', $colsProd, true)) {
                $insertCols[] = 'categoria_id';
                $insertVals[] = ':categoria_id';
                $paramsIns[':categoria_id'] = $categoriaFilial;
            }
            if (in_array('imagem', $colsProd, true)) {
                $insertCols[] = 'imagem';
                $insertVals[] = ':imagem';
                $paramsIns[':imagem'] = $prod['imagem'] ?? null;
            }
            if (in_array('destaque', $colsProd, true)) {
                $insertCols[] = 'destaque';
                $insertVals[] = ':destaque';
                $paramsIns[':destaque'] = (int)($prod['destaque'] ?? 0);
            }
            if (in_array('filial_id', $colsProd, true)) {
                $insertCols[] = 'filial_id';
                $insertVals[] = ':filial_id';
                $paramsIns[':filial_id'] = $filialId;
            }
            if (in_array('categoria', $colsProd, true) && !empty($prod['categoria'])) {
                $insertCols[] = 'categoria';
                $insertVals[] = ':categoria';
                $paramsIns[':categoria'] = $prod['categoria'];
            }

            $sqlIns = 'INSERT INTO produtos (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
            $db->prepare($sqlIns)->execute($paramsIns);
            $resultado['produtos_criados']++;
        }

        return $resultado;
    }
}

if (!function_exists('filiais_sincronizar_plano_status_matriz_para_filiais')) {
    function filiais_sincronizar_plano_status_matriz_para_filiais(PDO $db, int $matrizId): int
    {
        if ($matrizId <= 0) {
            return 0;
        }

        $colunas = filiais_restaurantes_colunas($db);
        if (!in_array('is_matriz', $colunas, true) || !in_array('filial_id', $colunas, true)) {
            return 0;
        }

        $matriz = filiais_obter_restaurante($db, $matrizId);
        if (!$matriz || (int)($matriz['is_matriz'] ?? 0) !== 1) {
            return 0;
        }

        $set = [];
        $params = [':matriz_id' => $matrizId];

        if (in_array('status', $colunas, true)) {
            $set[] = 'status = :status';
            $params[':status'] = (string)($matriz['status'] ?? 'ATIVO');
        }

        if (in_array('plano', $colunas, true) && array_key_exists('plano', $matriz)) {
            $set[] = 'plano = :plano';
            $params[':plano'] = $matriz['plano'];
        }

        if (in_array('plano_id', $colunas, true) && array_key_exists('plano_id', $matriz)) {
            $set[] = 'plano_id = :plano_id';
            $params[':plano_id'] = $matriz['plano_id'];
        }

        if (in_array('data_inicio', $colunas, true) && array_key_exists('data_inicio', $matriz)) {
            $set[] = 'data_inicio = :data_inicio';
            $params[':data_inicio'] = $matriz['data_inicio'];
        }

        if (in_array('data_fim', $colunas, true) && array_key_exists('data_fim', $matriz)) {
            $set[] = 'data_fim = :data_fim';
            $params[':data_fim'] = $matriz['data_fim'];
        }

        if (in_array('updated_at', $colunas, true)) {
            $set[] = 'updated_at = NOW()';
        }

        if (empty($set)) {
            return 0;
        }

        $sql = 'UPDATE restaurantes SET ' . implode(', ', $set)
            . ' WHERE filial_id = :matriz_id AND is_matriz = 0';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
