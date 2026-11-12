<?php
require_once __DIR__ . '/storage_path.php';
/**
 * Encerramento de contas — cumpre os Termos de Uso (seccoes 4, 5 e 7):
 *  - o restaurante pede o cancelamento e ele so produz efeito no fim do periodo pago;
 *  - depois de cancelado tem ENC_DIAS_EXPORTAR dias para exportar e os dados sao apagados
 *    ENC_DIAS_ELIMINAR dias depois disso, com aviso ENC_DIAS_AVISO dias antes;
 *  - ao apagar fica uma declaracao de eliminacao (tabela eliminacao_declaracoes);
 *  - "Exportar todos os dados": ZIP com CSV por tabela, disponivel mesmo bloqueado/cancelado.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/personal_data.php';
require_once __DIR__ . '/audit.php';

if (!defined('ENC_DIAS_EXPORTAR')) {
    define('ENC_DIAS_EXPORTAR', 30);
    define('ENC_DIAS_ELIMINAR', 90);
    define('ENC_DIAS_AVISO', 14);
}

if (!function_exists('enc_estado')) {
    /** Estado do encerramento de um restaurante (null se nao existir). */
    function enc_estado(PDO $db, int $rid): ?array
    {
        $s = $db->prepare('SELECT id, nome, status, cancelamento_pedido_em, cancelamento_motivo, cancelamento_efetivo_em,
                cancelado_em, eliminacao_agendada_em, eliminacao_aviso_em,
                DATE(COALESCE((SELECT MAX(rp.data_fim) FROM restaurante_planos rp WHERE rp.restaurante_id = r.id AND rp.status = \'ATIVO\'), r.data_fim)) AS data_fim
            FROM restaurantes r WHERE id = ? LIMIT 1');
        $s->execute([$rid]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Data em que o cancelamento produz efeito: fim do periodo pago (ou hoje, se ja venceu). */
    function enc_data_efeito(?string $dataFim, ?DateTimeImmutable $hoje = null): string
    {
        $hoje = $hoje ?? new DateTimeImmutable('today');
        if ($dataFim) {
            $fim = new DateTimeImmutable($dataFim);
            if ($fim >= $hoje) {
                return $fim->format('Y-m-d');
            }
        }
        return $hoje->format('Y-m-d');
    }

    function enc_pedir_cancelamento(PDO $db, int $rid, int $uid, string $motivo = ''): array
    {
        $e = enc_estado($db, $rid);
        if (!$e) {
            throw new InvalidArgumentException('Restaurante nao encontrado.');
        }
        if (strtoupper((string)$e['status']) === 'CANCELADO') {
            throw new InvalidArgumentException('Esta conta ja esta cancelada.');
        }
        $efeito = enc_data_efeito($e['data_fim'] ?? null);
        $motivo = mb_substr(trim(preg_replace('/\s+/', ' ', $motivo) ?? ''), 0, 500);
        $db->prepare('UPDATE restaurantes SET cancelamento_pedido_em = NOW(), cancelamento_pedido_por = ?, cancelamento_motivo = ?, cancelamento_efetivo_em = ? WHERE id = ?')
            ->execute([$uid, $motivo !== '' ? $motivo : null, $efeito, $rid]);
        audit_log($db, 'plano_cancelamento_pedido', ['restaurante_id' => $rid, 'efeito_em' => $efeito], 'WARN');
        return ['efeito_em' => $efeito];
    }

    function enc_desistir_cancelamento(PDO $db, int $rid): void
    {
        $e = enc_estado($db, $rid);
        if (!$e || strtoupper((string)$e['status']) === 'CANCELADO') {
            throw new InvalidArgumentException('O cancelamento ja produziu efeito. Contacte o suporte para reactivar a conta.');
        }
        $db->prepare('UPDATE restaurantes SET cancelamento_pedido_em = NULL, cancelamento_pedido_por = NULL, cancelamento_motivo = NULL, cancelamento_efetivo_em = NULL WHERE id = ?')
            ->execute([$rid]);
        audit_log($db, 'plano_cancelamento_desistido', ['restaurante_id' => $rid]);
    }

    /* ---------------- Exportacao ---------------- */

    /** Tabelas do restaurante a exportar (as que existirem). */
    function enc_tabelas_exportar(): array
    {
        return [
            'produtos' => 'produtos', 'categorias' => 'categorias', 'mesas' => 'mesas',
            'vendas' => 'vendas', 'pedidos' => 'pedidos', 'clientes' => 'clientes', 'reservas' => 'reservas',
            'ingredientes' => 'estoque_insumos', 'ingredientes_saldos' => 'estoque_saldos',
            'ingredientes_movimentos' => 'estoque_movimentos', 'receitas' => 'estoque_fichas_tecnicas',
            'equipa' => 'usuarios', 'turnos' => 'funcionarios_turnos', 'caixa_turnos' => 'caixa_turnos',
            'cupons' => 'cupons_desconto', 'promocoes' => 'promocoes', 'filiais' => 'filiais',
        ];
    }

    /** Tabelas filhas (sem restaurante_id) ligadas a uma tabela-mae do restaurante. */
    function enc_tabelas_filhas(): array
    {
        return [
            'vendas_itens' => ['itens_venda', 'venda_id', 'vendas'],
            'pedidos_itens' => ['itens_pedido', 'pedido_id', 'pedidos'],
            'pedidos_itens_app' => ['pedido_itens', 'pedido_id', 'pedidos'],
            'receitas_itens' => ['estoque_ficha_itens', 'ficha_id', 'estoque_fichas_tecnicas'],
        ];
    }

    /** Colunas que nunca saem do sistema (segredos e indices tecnicos). */
    function enc_coluna_secreta(string $col): bool
    {
        return (bool)preg_match('/^(senha|password|pin_hash|token|token_hash|two_factor_secret)$|_lookup$|_hash$|secret/i', $col);
    }

    function enc_tabela_existe(PDO $db, string $t): bool
    {
        $s = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$t]);
        return (int)$s->fetchColumn() > 0;
    }

    function enc_colunas(PDO $db, string $t): array
    {
        $s = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$t]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Escreve um CSV (separador ; e BOM, para abrir bem no Excel em portugues). Devolve n.º de linhas. */
    function enc_csv(PDO $db, string $sql, array $params, string $destino): int
    {
        $st = $db->prepare($sql);
        $st->execute($params);
        $f = fopen($destino, 'wb');
        if ($f === false) {
            throw new RuntimeException('Não foi possível escrever em ' . dirname($destino) . '.');
        }
        fwrite($f, "\xEF\xBB\xBF");
        $n = 0;
        $cab = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($cab === null) {
                $cab = array_values(array_filter(array_keys($row), fn($c) => !enc_coluna_secreta((string)$c)));
                fputcsv($f, $cab, ';', '"', '');
            }
            $linha = [];
            foreach ($cab as $c) {
                $v = $row[$c];
                if (is_string($v) && function_exists('personal_data_is_encrypted') && personal_data_is_encrypted($v)) {
                    $v = personal_data_decrypt($v);
                }
                $linha[] = $v;
            }
            fputcsv($f, $linha, ';', '"', '');
            $n++;
        }
        fclose($f);
        return $n;
    }

    /**
     * Escritor de ZIP simples, sem a extensao "zip" do PHP (vem desligada no XAMPP).
     * Usa compressao deflate quando existe zlib (sempre presente no PHP para Windows); senao guarda sem comprimir.
     *  - new EncZipSimples()            : em memoria (ficheiros pequenos; conteudo() devolve o ZIP);
     *  - new EncZipSimples($destino)    : escreve direto no disco, aos bocados (ficheiros grandes, pouca memoria).
     */
    final class EncZipSimples
    {
        private array $ficheiros = [];
        /** @var resource|null */
        private $fh = null;
        private string $destino = '';
        private string $central = '';
        private int $total = 0;
        private int $dosHora;
        private int $dosData;

        public function __construct(?string $destino = null)
        {
            $t = getdate();
            $this->dosHora = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
            $this->dosData = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
            if ($destino !== null) {
                $fh = @fopen($destino, 'w+b');
                if ($fh === false) {
                    throw new RuntimeException('Não foi possível gravar o ficheiro temporário em ' . dirname($destino) . '.');
                }
                $this->fh = $fh;
                $this->destino = $destino;
            }
        }

        public function addFromString(string $nome, string $dados): void
        {
            if ($this->fh === null) {
                $this->ficheiros[] = [$nome, $dados];
                return;
            }
            $src = fopen('php://temp', 'w+b');
            fwrite($src, $dados);
            rewind($src);
            $this->escreverEntrada($nome, $src);
            fclose($src);
        }

        public function addFile(string $caminho, string $nome): void
        {
            if ($this->fh === null) {
                $this->ficheiros[] = [$nome, (string)file_get_contents($caminho)];
                return;
            }
            $src = @fopen($caminho, 'rb');
            if ($src === false) {
                throw new RuntimeException('Não foi possível ler ' . basename($caminho) . '.');
            }
            $this->escreverEntrada($nome, $src);
            fclose($src);
        }

        /** Escreve uma entrada lendo a origem aos bocados de 1 MB (compressao e CRC incrementais). */
        private function escreverEntrada(string $nome, $src): void
        {
            $fh = $this->fh;
            $offset = $this->total;
            $deflate = function_exists('deflate_init') ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : false;
            $metodo = $deflate ? 8 : 0;
            $cab = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, $metodo, $this->dosHora, $this->dosData, 0, 0, 0, strlen($nome), 0) . $nome;
            fwrite($fh, $cab);
            $crc = hash_init('crc32b');
            $orig = 0;
            $comp = 0;
            while (!feof($src)) {
                $bloco = fread($src, 1048576);
                if ($bloco === false || $bloco === '') {
                    break;
                }
                hash_update($crc, $bloco);
                $orig += strlen($bloco);
                $saida = $deflate ? deflate_add($deflate, $bloco, ZLIB_NO_FLUSH) : $bloco;
                $comp += strlen($saida);
                fwrite($fh, $saida);
            }
            if ($deflate) {
                $saida = deflate_add($deflate, '', ZLIB_FINISH);
                $comp += strlen($saida);
                fwrite($fh, $saida);
            }
            $crcV = (int)hexdec(hash_final($crc));
            // Volta ao cabecalho para gravar o CRC e os tamanhos ja conhecidos.
            fseek($fh, $offset + 14);
            fwrite($fh, pack('VVV', $crcV, $comp, $orig));
            fseek($fh, 0, SEEK_END);
            $this->total = $offset + strlen($cab) + $comp;
            $this->central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $metodo, $this->dosHora, $this->dosData, $crcV, $comp, $orig, strlen($nome), 0, 0, 0, 0, 32, $offset) . $nome;
            $this->ficheiros[] = [$nome, null];
        }

        public function gravar(string $destino): void
        {
            if ($this->fh !== null) {
                $n = count($this->ficheiros);
                fwrite($this->fh, $this->central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($this->central), $this->total, 0));
                fclose($this->fh);
                $this->fh = null;
                if ($destino !== $this->destino && !@rename($this->destino, $destino)) {
                    throw new RuntimeException('Não foi possível gravar o ficheiro em ' . dirname($destino) . '.');
                }
                return;
            }
            if (file_put_contents($destino, $this->conteudo()) === false) {
                throw new RuntimeException('Não foi possível gravar o ficheiro temporário em ' . dirname($destino) . '.');
            }
        }

        public function conteudo(): string
        {
            $out = '';
            $central = '';
            foreach ($this->ficheiros as [$nome, $dados]) {
                $crc = crc32($dados);
                $metodo = 0;
                $comp = $dados;
                if (function_exists('gzdeflate')) {
                    $z = gzdeflate($dados, 6);
                    if ($z !== false && strlen($z) < strlen($dados)) {
                        $metodo = 8;
                        $comp = $z;
                    }
                }
                $offset = strlen($out);
                $out .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, $metodo, $this->dosHora, $this->dosData, $crc, strlen($comp), strlen($dados), strlen($nome), 0) . $nome . $comp;
                $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $metodo, $this->dosHora, $this->dosData, $crc, strlen($comp), strlen($dados), strlen($nome), 0, 0, 0, 0, 32, $offset) . $nome;
            }
            $fim = "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($this->ficheiros), count($this->ficheiros), strlen($central), strlen($out), 0);
            return $out . $central . $fim;
        }
    }

    /* ---------------- Exportacao legivel (Excel + resumo) ---------------- */

    /** Nome legivel de cada conjunto de dados, pela ordem em que aparece no Excel. */
    function enc_titulos_folhas(): array
    {
        return [
            'vendas' => 'Vendas', 'vendas_itens' => 'Itens das vendas', 'pedidos' => 'Pedidos',
            'pedidos_itens' => 'Itens dos pedidos', 'pedidos_itens_app' => 'Itens dos pedidos (app)',
            'caixa_turnos' => 'Caixa e turnos', 'produtos' => 'Produtos', 'categorias' => 'Categorias',
            'mesas' => 'Mesas', 'clientes' => 'Clientes', 'reservas' => 'Reservas',
            'equipa' => 'Equipa', 'turnos' => 'Turnos da equipa', 'ingredientes' => 'Ingredientes',
            'ingredientes_saldos' => 'Stock de ingredientes', 'ingredientes_movimentos' => 'Movimentos de stock',
            'receitas' => 'Fichas técnicas', 'receitas_itens' => 'Itens das fichas técnicas',
            'cupons' => 'Cupões', 'promocoes' => 'Promoções', 'filiais' => 'Filiais',
        ];
    }

    /** Colunas tecnicas que nao interessam ao cliente na versao legivel (continuam nos CSV). */
    function enc_coluna_tecnica(string $c): bool
    {
        return (bool)preg_match('/^(restaurante_id|created_at|qrcode|qr_sessao|imagem|foto|super_admin|tentativas_login|bloqueado_ate|pin_.*|two_factor_.*|meta_json|.*_json)$/', $c);
    }

    function enc_rotulo_coluna(string $c): string
    {
        static $r = [
            'id' => 'N.º', 'nome' => 'Nome', 'descricao' => 'Descrição', 'preco' => 'Preço (MZN)', 'custo' => 'Custo (MZN)',
            'estoque' => 'Stock', 'estoque_minimo' => 'Stock mínimo', 'ativo' => 'Ativo', 'destaque' => 'Destaque',
            'criado_em' => 'Criado em', 'atualizado_em' => 'Atualizado em', 'status' => 'Estado', 'numero' => 'Número',
            'capacidade' => 'Lugares', 'usuario_id' => 'Funcionário', 'garcom_id' => 'Empregado de mesa',
            'produto_id' => 'Produto', 'categoria_id' => 'Categoria', 'parent_id' => 'Categoria-mãe', 'mesa_id' => 'Mesa',
            'cliente_id' => 'Cliente', 'venda_id' => 'N.º da venda', 'pedido_id' => 'N.º do pedido', 'caixa_id' => 'Caixa',
            'turno_id' => 'Turno', 'filial_id' => 'Filial', 'numero_fatura' => 'N.º da fatura', 'total' => 'Total (MZN)',
            'desconto' => 'Desconto (MZN)', 'total_final' => 'Total a pagar (MZN)', 'forma_pagamento' => 'Forma de pagamento',
            'valor_recebido' => 'Valor recebido (MZN)', 'troco' => 'Troco (MZN)', 'observacao' => 'Observação',
            'quantidade' => 'Quantidade', 'preco_unitario' => 'Preço unitário (MZN)', 'subtotal' => 'Subtotal (MZN)',
            'data_abertura' => 'Aberto em', 'data_fechamento' => 'Fechado em', 'numero_pedido' => 'N.º do pedido',
            'cliente_nome' => 'Nome do cliente', 'cliente_telefone' => 'Telefone do cliente', 'endereco_entrega' => 'Endereço de entrega',
            'origem' => 'Origem', 'tipo_pedido' => 'Tipo de pedido', 'fonte' => 'Fonte', 'email' => 'Email', 'telefone' => 'Telefone',
            'perfil' => 'Perfil', 'ultimo_acesso' => 'Último acesso', 'endereco' => 'Endereço', 'cidade' => 'Cidade',
            'provincia' => 'Província', 'pais' => 'País', 'documento' => 'Documento', 'data_nascimento' => 'Data de nascimento',
            'ticket_medio' => 'Gasto médio (MZN)', 'notas_internas' => 'Notas internas', 'categoria' => 'Categoria (texto)',
            'iniciado_preparo_em' => 'Preparação iniciada em', 'pronto_em' => 'Pronto em', 'entregue_em' => 'Entregue em',
            'conta_em' => 'Conta pedida em', 'pago_em' => 'Pago em', 'cancelado_em' => 'Cancelado em', 'valor' => 'Valor (MZN)',
            'promocao_id' => 'Promoção', 'insumo_id' => 'Ingrediente', 'ficha_id' => 'Ficha técnica', 'unidade' => 'Unidade',
            'observacoes' => 'Observações', 'data' => 'Data', 'data_saida' => 'Data de saída', 'hora_entrada' => 'Hora de entrada',
            'hora_saida' => 'Hora de saída', 'cargo' => 'Cargo', 'turno' => 'Turno', 'data_reserva' => 'Data da reserva',
            'hora_reserva' => 'Hora da reserva', 'quantidade_pessoas' => 'Pessoas', 'mesa_atribuida' => 'Mesa atribuída',
            'nome_cliente' => 'Nome do cliente', 'email_cliente' => 'Email do cliente', 'telefone_cliente' => 'Telefone do cliente',
            'confirmacao_whatsapp' => 'Confirmado por WhatsApp', 'confirmacao_data' => 'Confirmado em', 'responsavel' => 'Responsável',
            'responsavel_abertura' => 'Aberto por', 'responsavel_fechamento' => 'Fechado por', 'abertura_manual' => 'Abertura manual',
            'fechamento_manual' => 'Fecho manual', 'motivo' => 'Motivo', 'motivo_intervencao' => 'Motivo da intervenção',
            'tipo' => 'Tipo', 'tipo_cliente' => 'Tipo de cliente', 'frequencia_media_dias' => 'Frequência média (dias)',
            'data_primeira_visita' => 'Primeira visita', 'data_ultima_visita' => 'Última visita', 'score_fidelidade' => 'Pontos de fidelidade',
            'bloqueado' => 'Bloqueado', 'motivo_bloqueio' => 'Motivo do bloqueio', 'app_ativo' => 'Usa a app', 'ultimo_login_em' => 'Último acesso à app',
            'sku' => 'Código', 'unidade_base' => 'Unidade', 'estoque_minimo_base' => 'Stock mínimo', 'saldo_base' => 'Quantidade em stock',
            'custo_medio' => 'Custo médio (MZN)', 'quantidade_base' => 'Quantidade', 'custo_unitario_base' => 'Custo unitário (MZN)',
            'custo_total' => 'Custo total (MZN)', 'unidade_origem' => 'Unidade de origem', 'produto_nome' => 'Nome do produto',
            'destino' => 'Destino', 'rendimento_por_produto' => 'Rendimento por produto', 'data_inicio' => 'Início', 'data_fim' => 'Fim',
            'codigo' => 'Código', 'percentual' => 'Percentagem', 'validade' => 'Validade',
        ];
        return $r[$c] ?? ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $c)));
    }

    function enc_coluna_dinheiro(string $c): bool
    {
        return (bool)preg_match('/^(preco|custo|custo_.*|total|total_final|desconto|valor|valor_.*|troco|preco_unitario|subtotal|ticket_medio|.*_mzn)$/', $c);
    }

    /** Le um conjunto de dados para memoria: [colunas, linhas], sem segredos e com dados pessoais decifrados. */
    function enc_ler(PDO $db, string $sql, array $params): array
    {
        $st = $db->prepare($sql);
        $st->execute($params);
        $cols = [];
        $linhas = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $m = $st->getColumnMeta($i);
            if (!enc_coluna_secreta((string)$m['name'])) {
                $cols[] = (string)$m['name'];
            }
        }
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $l = [];
            foreach ($cols as $c) {
                $v = $row[$c];
                if (is_string($v) && function_exists('personal_data_is_encrypted') && personal_data_is_encrypted($v)) {
                    $v = personal_data_decrypt($v);
                }
                $l[$c] = $v;
            }
            $linhas[] = $l;
        }
        return [$cols, $linhas];
    }

    /** Uma linha de CSV legivel (aspas so quando precisas). */
    function enc_csv_linha(array $cols, array $linha, array $mapas): string
    {
        $campo = static function ($v): string {
            $v = (string)$v;
            return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        };
        $vals = [];
        foreach ($cols as $c) {
            $x = enc_valor_legivel($c, $linha[$c] ?? null, $mapas);
            if ($x['tipo'] === 'd' || $x['tipo'] === 'dd') {
                $ts = (int)round(($x['v'] - 25569) * 86400);
                $vals[] = gmdate($x['tipo'] === 'd' ? 'd/m/Y H:i' : 'd/m/Y', $ts);
            } elseif ($x['tipo'] === 'm') {
                $vals[] = number_format((float)$x['v'], 2, '.', '');
            } else {
                $vals[] = $campo($x['v']);
            }
        }
        return implode(',', $vals) . "\r\n";
    }

    /** CSV legivel completo (cabecalhos em portugues). Usado em testes e dados pequenos. */
    function enc_csv_de_linhas(array $cols, array $linhas, array $mapas = []): string
    {
        $cols = array_values(array_filter($cols, fn($c) => !enc_coluna_tecnica($c)));
        $cab = array_map(static fn($c) => preg_match('/[",\r\n]/', $r = enc_rotulo_coluna($c)) ? '"' . str_replace('"', '""', $r) . '"' : $r, $cols);
        $out = "\xEF\xBB\xBF" . implode(',', $cab) . "\r\n";
        foreach ($linhas as $l) {
            $out .= enc_csv_linha($cols, $l, $mapas);
        }
        return $out;
    }

    /**
     * Le uma consulta aos bocados ($lote linhas de cada vez) e chama $porLinha para cada linha,
     * ja sem segredos e com dados pessoais decifrados. Assim a exportacao de um restaurante com
     * anos de vendas usa pouca memoria. $aoComecar recebe as colunas antes da primeira linha.
     * Devolve o numero de linhas.
     */
    function enc_iterar(PDO $db, string $sql, array $params, callable $aoComecar, callable $porLinha, int $lote = 2000): int
    {
        $n = 0;
        $cols = null;
        for ($offset = 0; ; $offset += $lote) {
            $st = $db->prepare($sql . ' LIMIT ' . (int)$lote . ' OFFSET ' . (int)$offset);
            $st->execute($params);
            if ($cols === null) {
                $cols = [];
                for ($i = 0; $i < $st->columnCount(); $i++) {
                    $m = $st->getColumnMeta($i);
                    if (!enc_coluna_secreta((string)$m['name'])) {
                        $cols[] = (string)$m['name'];
                    }
                }
                $aoComecar($cols);
            }
            $lidas = 0;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $lidas++;
                $l = [];
                foreach ($cols as $c) {
                    $v = $row[$c];
                    if (is_string($v) && function_exists('personal_data_is_encrypted') && personal_data_is_encrypted($v)) {
                        $v = personal_data_decrypt($v);
                    }
                    $l[$c] = $v;
                }
                $porLinha($l);
                $n++;
            }
            $st->closeCursor();
            if ($lidas < $lote) {
                return $n;
            }
        }
    }

    /** Uma folha de Excel escrita aos bocados para um ficheiro temporario. */
    final class EncFolha
    {
        private $fh;
        private string $tmp;
        private array $larg;
        private int $linha = 1;

        public function __construct(string $tmp, array $rotulos)
        {
            $this->tmp = $tmp;
            $fh = @fopen($tmp, 'w+b');
            if ($fh === false) {
                throw new RuntimeException('Não foi possível escrever em ' . dirname($tmp) . '.');
            }
            $this->fh = $fh;
            $this->larg = array_map(static fn($c) => mb_strlen($c) + 2, $rotulos);
            $xml = '<row r="1">';
            foreach ($rotulos as $j => $c) {
                $xml .= '<c r="' . enc_coluna_letra($j) . '1" t="inlineStr" s="1"><is><t>' . enc_xml($c) . '</t></is></c>';
            }
            fwrite($fh, $xml . '</row>');
        }

        /** @param array<int, array{tipo:string, v:mixed}> $celulas */
        public function linha(array $celulas): void
        {
            static $estilo = ['s' => 0, 'n' => 0, 'm' => 2, 'd' => 3, 'dd' => 4];
            $r = ++$this->linha;
            $xml = '<row r="' . $r . '">';
            foreach ($celulas as $j => $cel) {
                $ref = enc_coluna_letra($j) . $r;
                if ($cel['tipo'] === 's') {
                    if ($cel['v'] === '') {
                        continue;
                    }
                    $this->larg[$j] = max($this->larg[$j], min(60, mb_strlen($cel['v']) + 2));
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . enc_xml($cel['v']) . '</t></is></c>';
                } else {
                    $this->larg[$j] = max($this->larg[$j], $cel['tipo'] === 'd' ? 18 : 12);
                    $xml .= '<c r="' . $ref . '" s="' . $estilo[$cel['tipo']] . '"><v>' . (0 + $cel['v']) . '</v></c>';
                }
            }
            fwrite($this->fh, $xml . '</row>');
        }

        public function linhas(): int
        {
            return $this->linha - 1;
        }

        /** Fecha a folha e devolve o caminho do XML completo da folha. */
        public function fechar(): string
        {
            $colsXml = '';
            foreach ($this->larg as $j => $w) {
                $colsXml .= '<col min="' . ($j + 1) . '" max="' . ($j + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $ult = enc_coluna_letra(max(0, count($this->larg) - 1)) . $this->linha;
            $final = $this->tmp . '.xml';
            $out = fopen($final, 'wb');
            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<dimension ref="A1:' . $ult . '"/>'
                . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                . '<cols>' . $colsXml . '</cols><sheetData>');
            rewind($this->fh);
            stream_copy_to_stream($this->fh, $out);
            fclose($this->fh);
            @unlink($this->tmp);
            fwrite($out, '</sheetData>' . ($this->linha > 1 ? '<autoFilter ref="A1:' . $ult . '"/>' : '') . '</worksheet>');
            fclose($out);
            return $final;
        }
    }

    /** Monta o .xlsx a partir das folhas ja escritas em disco ([titulo => caminho do XML]). */
    function enc_xlsx_montar(array $folhas, string $destino): void
    {
        $z = new EncZipSimples($destino);
        $ct = '';
        $wb = '';
        $rels = '';
        $usados = [];
        $n = 0;
        foreach ($folhas as $titulo => $caminho) {
            $n++;
            $nome = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', (string)$titulo), 0, 31);
            while (isset($usados[mb_strtolower($nome)])) {
                $nome = mb_substr($nome, 0, 28) . ' ' . $n;
            }
            $usados[mb_strtolower($nome)] = true;
            $z->addFile($caminho, "xl/worksheets/sheet$n.xml");
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . enc_xml($nome) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $k = $n + 1;
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wb . '</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
            . '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E5F"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $z->gravar($destino);
    }

    /** Apaga uma pasta temporaria de exportacao (so ficheiros, um nivel). */
    function enc_apagar_pasta(string $dir): void
    {
        foreach ((array)glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*') as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    /** Mapas id => nome para mostrar nomes em vez de numeros. */
    function enc_mapas_nomes(array $dados): array
    {
        $m = ['pessoa' => [], 'produto' => [], 'categoria' => [], 'mesa' => [], 'cliente' => [], 'promocao' => [], 'insumo' => [], 'filial' => []];
        $fontes = ['equipa' => ['pessoa', 'nome'], 'produtos' => ['produto', 'nome'], 'categorias' => ['categoria', 'nome'],
            'mesas' => ['mesa', 'numero'], 'clientes' => ['cliente', 'nome'], 'promocoes' => ['promocao', 'nome'],
            'ingredientes' => ['insumo', 'nome'], 'filiais' => ['filial', 'nome']];
        foreach ($fontes as $k => [$mapa, $col]) {
            foreach ($dados[$k][1] ?? [] as $l) {
                if (isset($l['id'], $l[$col]) && $l[$col] !== '') {
                    $m[$mapa][(string)$l['id']] = $mapa === 'mesa' ? 'Mesa ' . $l[$col] : (string)$l[$col];
                }
            }
        }
        return $m;
    }

    /** Converte um valor para a versao legivel: ['tipo' => s|n|d|m, 'v' => ...]. */
    function enc_valor_legivel(string $c, $v, array $mapas): array
    {
        if ($v === null || $v === '') {
            return ['tipo' => 's', 'v' => ''];
        }
        $ref = ['usuario_id' => 'pessoa', 'garcom_id' => 'pessoa', 'produto_id' => 'produto', 'categoria_id' => 'categoria',
            'parent_id' => 'categoria', 'mesa_id' => 'mesa', 'cliente_id' => 'cliente', 'promocao_id' => 'promocao',
            'insumo_id' => 'insumo', 'filial_id' => 'filial', 'mesa_atribuida' => 'mesa', 'responsavel' => 'pessoa',
            'responsavel_abertura' => 'pessoa', 'responsavel_fechamento' => 'pessoa'];
        if (isset($ref[$c]) && isset($mapas[$ref[$c]][(string)$v])) {
            return ['tipo' => 's', 'v' => $mapas[$ref[$c]][(string)$v]];
        }
        if (preg_match('/telefone|documento|nuit|sku|codigo|numero|fatura|^nif$|^bi$/', $c)) {
            return ['tipo' => 's', 'v' => (string)$v];
        }
        if (preg_match('/^(ativo|destaque|bloqueado|app_ativo|two_factor_enabled|abertura_manual|fechamento_manual|confirmacao_whatsapp)$/', $c)) {
            return ['tipo' => 's', 'v' => ((string)$v === '1') ? 'Sim' : 'Não'];
        }
        if (preg_match('/^(status|perfil|forma_pagamento|tipo|tipo_.*|origem|fonte|cargo|destino|turno)$/', $c) && is_string($v)) {
            static $fixos = ['MPESA' => 'M-Pesa', 'EMOLA' => 'e-Mola', 'CARTAO' => 'Cartão', 'TRANSFERENCIA' => 'Transferência',
                'BALCAO' => 'Balcão', 'GARCOM' => 'Empregado de mesa', 'GERENTE' => 'Gerente', 'COZINHA' => 'Cozinha',
                'ADMIN' => 'Administrador', 'EM_PREPARO' => 'Em preparação', 'CONCLUIDO' => 'Concluído', 'CONCLUIDA' => 'Concluída'];
            if (isset($fixos[strtoupper($v)])) {
                return ['tipo' => 's', 'v' => $fixos[strtoupper($v)]];
            }
            $t = mb_strtolower(str_replace('_', ' ', $v));
            return ['tipo' => 's', 'v' => mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1)];
        }
        if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $p)) {
            if ($p[1] === '0000') {
                return ['tipo' => 's', 'v' => ''];
            }
            $ts = gmmktime((int)($p[4] ?? 0), (int)($p[5] ?? 0), (int)($p[6] ?? 0), (int)$p[2], (int)$p[3], (int)$p[1]);
            return ['tipo' => isset($p[4]) ? 'd' : 'dd', 'v' => $ts / 86400 + 25569];
        }
        if (is_numeric($v) && !preg_match('/^0\d/', (string)$v) && strlen((string)$v) < 16) {
            return ['tipo' => enc_coluna_dinheiro($c) ? 'm' : 'n', 'v' => $v + 0];
        }
        return ['tipo' => 's', 'v' => (string)$v];
    }

    function enc_xml(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    function enc_coluna_letra(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    function enc_fmt_mzn(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' MZN';
    }

    /** Pagina LEIA-ME com o resumo do restaurante e a explicacao de cada folha. */
    function enc_leia_me_html(string $restaurante, array $resumo, string $ficheiroXlsx): string
    {
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $cont = (array)($resumo['contagens'] ?? []);
        $fat = (float)($resumo['faturado'] ?? 0);
        $periodo = (string)($resumo['periodo'] ?? '—');
        $n = fn($k) => (int)($cont[$k] ?? 0);
        $explica = [
            'vendas' => 'Cada venda registada no caixa: fatura, total, desconto, forma de pagamento e quem vendeu.',
            'vendas_itens' => 'Os produtos de cada venda (ligue pelo «N.º da venda»).',
            'pedidos' => 'Pedidos de mesa, balcão, entrega e app, com os tempos de preparação.',
            'pedidos_itens' => 'Os produtos de cada pedido (ligue pelo «N.º do pedido»).',
            'pedidos_itens_app' => 'Os produtos dos pedidos feitos pela app.',
            'caixa_turnos' => 'Aberturas e fechos de caixa, com o funcionário responsável.',
            'produtos' => 'O seu menu: preços, custos, stock e categoria.',
            'categorias' => 'As categorias do menu.', 'mesas' => 'As mesas e o número de lugares.',
            'clientes' => 'Os seus clientes e os contactos que registou.', 'reservas' => 'Reservas de mesas.',
            'equipa' => 'Os utilizadores do sistema (sem senhas nem PIN).', 'turnos' => 'Entradas e saídas da equipa.',
            'ingredientes' => 'Ingredientes e insumos do stock.', 'ingredientes_saldos' => 'Quantidade atual de cada ingrediente.',
            'ingredientes_movimentos' => 'Entradas, saídas e acertos de stock.', 'receitas' => 'Fichas técnicas dos pratos.',
            'receitas_itens' => 'Ingredientes de cada ficha técnica.', 'cupons' => 'Cupões de desconto.',
            'promocoes' => 'Promoções.', 'filiais' => 'Filiais do restaurante.',
        ];
        $linhas = '';
        foreach (enc_titulos_folhas() as $k => $t) {
            if (!array_key_exists($k, $cont)) {
                continue;
            }
            $c = $n($k);
            $linhas .= '<tr><td><b>' . $h($t) . '</b></td><td class="n">' . $c . '</td><td>' . $h($c ? ($explica[$k] ?? '') : 'Sem registos.') . '</td></tr>';
        }
        $card = fn($r, $v) => '<div class="k"><span>' . $h($r) . '</span><b>' . $h($v) . '</b></div>';
        return '<!DOCTYPE html><html lang="pt"><head><meta charset="utf-8"><title>Os seus dados — ' . $h($restaurante) . '</title>'
            . '<style>body{font-family:Segoe UI,Arial,sans-serif;color:#1d2b33;max-width:860px;margin:32px auto;padding:0 20px;line-height:1.5}'
            . 'h1{margin:0 0 4px;color:#1f4e5f}.sub{color:#5b6b73;margin:0 0 24px}.ks{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:28px}'
            . '.k{border:1px solid #dde3e6;border-radius:10px;padding:12px}.k span{display:block;font-size:13px;color:#5b6b73}.k b{font-size:18px}'
            . 'table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px;border-bottom:1px solid #e6ebee;vertical-align:top}'
            . 'th{background:#f3f6f7}.n{text-align:right;white-space:nowrap}.caixa{background:#f3f6f7;border-radius:10px;padding:14px 18px;margin:24px 0}'
            . '@media print{.ks{grid-template-columns:repeat(4,1fr)}}@media(max-width:640px){.ks{grid-template-columns:1fr 1fr}}</style></head><body>'
            . '<h1>Os dados de ' . $h($restaurante) . '</h1><p class="sub">Exportado do Nivora em ' . date('d/m/Y \à\s H:i') . '</p>'
            . '<div class="ks">' . $card('Vendas', (string)(int)($resumo['vendas'] ?? 0)) . $card('Faturado', enc_fmt_mzn($fat))
            . $card('Período das vendas', $periodo) . $card('Produtos no menu', (string)$n('produtos')) . '</div>'
            . '<div class="caixa"><b>Como abrir:</b> abra o ficheiro <b>' . $h($ficheiroXlsx) . '</b> no Excel, LibreOffice ou Google Sheets. '
            . 'Cada separador (em baixo) é um assunto. Os nomes de funcionários, produtos, mesas e clientes já aparecem por extenso.</div>'
            . '<h2>O que está em cada separador</h2><table><tr><th>Separador</th><th class="n">Registos</th><th>O que contém</th></tr>' . $linhas . '</table>'
            . '<div class="caixa"><b>Pasta «tecnico»:</b> tem os mesmos dados em CSV (separador vírgula, UTF-8), uma tabela por ficheiro. '
            . 'Serve para importar noutro sistema (direito à portabilidade). Valores em dinheiro em meticais (MZN).</div>'
            . '<p class="sub">Por segurança, senhas, PIN e códigos de verificação nunca são exportados.</p></body></html>';
    }

    /**
     * Cria o ZIP com todos os dados do restaurante. Devolve o caminho do ficheiro temporario.
     * Le cada tabela aos bocados (2000 linhas de cada vez) e escreve direto no disco, por isso
     * funciona com anos de vendas sem esgotar a memoria do PHP.
     */
    function enc_exportar_zip(PDO $db, int $rid): string
    {
        $e = enc_estado($db, $rid);
        if (!$e) {
            throw new InvalidArgumentException('Restaurante nao encontrado.');
        }
        $base = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : (nv_storage());
        if (!is_dir($base) && !@mkdir($base, 0700, true)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária (' . $base . ').');
        }
        $base = rtrim($base, '/\\') . DIRECTORY_SEPARATOR;
        $tmp = $base . 'nv_exp_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária (' . $base . ').');
        }
        $zipPath = $base . 'nv_export_' . bin2hex(random_bytes(6)) . '.zip';

        try {
            // 1) Conjuntos de dados a exportar.
            $conjuntos = [];
            foreach (enc_tabelas_exportar() as $nome => $t) {
                if (enc_tabela_existe($db, $t) && in_array('restaurante_id', enc_colunas($db, $t), true)) {
                    $conjuntos[$nome] = ["SELECT * FROM `$t` WHERE restaurante_id = ? ORDER BY 1", [$rid]];
                }
            }
            foreach (enc_tabelas_filhas() as $nome => [$t, $fk, $pai]) {
                if (!enc_tabela_existe($db, $t) || !enc_tabela_existe($db, $pai)) {
                    continue;
                }
                $cols = enc_colunas($db, $t);
                if (in_array($fk, $cols, true) && !in_array('restaurante_id', $cols, true)) {
                    $conjuntos[$nome] = ["SELECT c.* FROM `$t` c JOIN `$pai` p ON p.id = c.`$fk` WHERE p.restaurante_id = ? ORDER BY 1", [$rid]];
                }
            }

            // 2) Nomes para mostrar em vez de numeros (tabelas pequenas: equipa, produtos, mesas...).
            $pequenas = [];
            foreach (['equipa', 'produtos', 'categorias', 'mesas', 'clientes', 'promocoes', 'ingredientes', 'filiais'] as $k) {
                if (isset($conjuntos[$k])) {
                    try {
                        $pequenas[$k] = enc_ler($db, $conjuntos[$k][0], $conjuntos[$k][1]);
                    } catch (Throwable $ex) {
                        error_log('enc_exportar_zip mapas ' . $k . ': ' . $ex->getMessage());
                    }
                }
            }
            $mapas = enc_mapas_nomes($pequenas);
            unset($pequenas);

            // 3) Cada conjunto: CSV (tecnico/) e folha de Excel, linha a linha.
            $titulos = enc_titulos_folhas();
            $contagens = [];
            $resumo = ['vendas' => 0, 'faturado' => 0.0, 'dmin' => null, 'dmax' => null];
            $folhas = [];
            $csvs = [];
            foreach ($conjuntos as $nome => [$sql, $params]) {
                $csvPath = $tmp . DIRECTORY_SEPARATOR . $nome . '.csv';
                $csv = @fopen($csvPath, 'wb');
                if ($csv === false) {
                    throw new RuntimeException('Não foi possível escrever em ' . $tmp . '.');
                }
                $vis = [];
                $folha = null;
                try {
                    $n = enc_iterar($db, $sql, $params,
                        static function (array $cols) use (&$vis, &$folha, $csv, $tmp, $nome): void {
                            $vis = array_values(array_filter($cols, static fn($c) => !enc_coluna_tecnica($c)));
                            $rot = array_map('enc_rotulo_coluna', $vis);
                            fwrite($csv, "\xEF\xBB\xBF" . implode(',', array_map(static fn($r) => preg_match('/[",\r\n]/', $r) ? '"' . str_replace('"', '""', $r) . '"' : $r, $rot)) . "\r\n");
                            if ($vis) {
                                $folha = new EncFolha($tmp . DIRECTORY_SEPARATOR . 'folha_' . $nome, $rot);
                            }
                        },
                        static function (array $l) use (&$vis, &$folha, &$resumo, $csv, $mapas, $nome): void {
                            fwrite($csv, enc_csv_linha($vis, $l, $mapas));
                            if ($folha) {
                                $cel = [];
                                foreach ($vis as $c) {
                                    $cel[] = enc_valor_legivel($c, $l[$c], $mapas);
                                }
                                $folha->linha($cel);
                            }
                            if ($nome === 'vendas') {
                                $resumo['vendas']++;
                                if (!preg_match('/CANCEL|ESTORN|ANUL/i', (string)($l['status'] ?? ''))) {
                                    $resumo['faturado'] += (float)($l['total_final'] ?? $l['total'] ?? 0);
                                }
                                $d = substr((string)($l['criado_em'] ?? ''), 0, 10);
                                if ($d !== '') {
                                    $resumo['dmin'] = $resumo['dmin'] === null ? $d : min($resumo['dmin'], $d);
                                    $resumo['dmax'] = $resumo['dmax'] === null ? $d : max($resumo['dmax'], $d);
                                }
                            }
                        });
                    $contagens[$nome] = $n;
                    fclose($csv);
                    $csvs[$nome] = $csvPath;
                    if ($folha) {
                        $xml = $folha->fechar();
                        if ($n > 0) {
                            $folhas[$nome] = [$titulos[$nome] ?? $nome, $xml];
                        }
                    }
                } catch (Throwable $ex) {
                    // Uma tabela com problema nao impede a exportacao das outras.
                    error_log('enc_exportar_zip ' . $nome . ': ' . $ex->getMessage());
                    if (is_resource($csv)) {
                        fclose($csv);
                    }
                    @unlink($csvPath);
                }
            }

            // 4) Excel (separadores pela ordem dos titulos) e LEIA-ME.
            $ordenadas = [];
            foreach (array_keys($titulos + $folhas) as $k) {
                if (isset($folhas[$k])) {
                    $ordenadas[$folhas[$k][0]] = $folhas[$k][1];
                }
            }
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$e['nome']) ?: 'restaurante')), '-') ?: 'restaurante';
            $xlsxNome = 'Dados-' . $slug . '.xlsx';
            $xlsxPath = $tmp . DIRECTORY_SEPARATOR . 'dados.xlsx';
            if ($ordenadas) {
                enc_xlsx_montar($ordenadas, $xlsxPath);
            }
            $periodo = $resumo['dmin'] ? date('d/m/Y', strtotime($resumo['dmin'])) . ' a ' . date('d/m/Y', strtotime($resumo['dmax'])) : '—';
            $leia = enc_leia_me_html((string)$e['nome'], [
                'contagens' => $contagens, 'vendas' => $resumo['vendas'], 'faturado' => $resumo['faturado'], 'periodo' => $periodo,
            ], $xlsxNome);

            // 5) ZIP final (escrito direto no disco).
            $usarExtensao = class_exists('ZipArchive') && getenv('NV_FORCAR_ZIP_SIMPLES') !== '1';
            $zip = null;
            if ($usarExtensao) {
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    $zip = null;
                }
            }
            if ($zip === null) {
                $zip = new EncZipSimples($zipPath);
            }
            $zip->addFromString('LEIA-ME.html', $leia);
            if ($ordenadas) {
                $zip->addFile($xlsxPath, $xlsxNome);
            }
            foreach ($csvs as $nome => $caminho) {
                $zip->addFile($caminho, "tecnico/$nome.csv");
            }
            if ($zip instanceof EncZipSimples) {
                $zip->gravar($zipPath);
            } elseif (!$zip->close()) {
                throw new RuntimeException('Não foi possível gravar o ficheiro temporário em ' . $base . '.');
            }
        } catch (Throwable $ex) {
            @unlink($zipPath);
            enc_apagar_pasta($tmp);
            throw $ex;
        }
        enc_apagar_pasta($tmp);
        if (function_exists('audit_log')) {
            audit_log($db, 'dados_exportados_zip', ['restaurante_id' => $rid, 'tabelas' => count($contagens)]);
        }
        return $zipPath;
    }


    /* ---------------- Eliminacao ---------------- */

    /** Apaga todos os dados de um restaurante. Devolve [tabela => linhas]. Nao gera declaracao. */
    function enc_apagar_dados(PDO $db, array $rids): array
    {
        $rids = array_values(array_filter(array_map('intval', $rids), fn($i) => $i > 0));
        if (!$rids) {
            return [];
        }
        $in = implode(',', $rids);
        $tabelas = $db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'restaurante_id'
              AND TABLE_NAME NOT IN ('restaurantes', 'eliminacao_declaracoes', 'plano_reembolsos', 'audit_logs')")->fetchAll(PDO::FETCH_COLUMN);
        $det = [];
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $db->beginTransaction();
        try {
            foreach (enc_tabelas_filhas() + ['tickets_msgs' => ['support_ticket_messages', 'ticket_id', 'support_tickets']] as [$t, $fk, $pai]) {
                if (in_array($t, $tabelas, true) || !enc_tabela_existe($db, $t) || !enc_tabela_existe($db, $pai)) {
                    continue;
                }
                if (!in_array($fk, enc_colunas($db, $t), true)) {
                    continue;
                }
                $n = $db->exec("DELETE FROM `$t` WHERE `$fk` IN (SELECT id FROM `$pai` WHERE restaurante_id IN ($in))");
                if ($n) {
                    $det[$t] = $n;
                }
            }
            foreach ($tabelas as $t) {
                $n = $db->exec("DELETE FROM `$t` WHERE restaurante_id IN ($in)");
                if ($n) {
                    $det[$t] = $n;
                }
            }
            $n = $db->exec("DELETE FROM restaurantes WHERE id IN ($in)");
            $det['restaurantes'] = $n;
            $db->commit();
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $ex;
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $det;
    }

    /** Apaga um restaurante cancelado e guarda a declaracao de eliminacao. Devolve a declaracao. */
    function enc_eliminar_com_declaracao(PDO $db, int $rid): array
    {
        $e = enc_estado($db, $rid);
        if (!$e) {
            throw new InvalidArgumentException('Restaurante nao encontrado.');
        }
        $em = $db->prepare('SELECT email_lookup FROM restaurantes WHERE id = ?');
        $em->execute([$rid]);
        $lookup = $em->fetchColumn() ?: null;
        $det = enc_apagar_dados($db, [$rid]);
        $total = array_sum($det);
        $codigo = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', ...array_map(fn() => random_int(0, 0xffff), range(1, 8)));
        $agora = date('Y-m-d H:i:s');
        $hash = hash('sha256', implode('|', [$codigo, $rid, $e['nome'], $agora, json_encode($det)]));
        $db->prepare('INSERT INTO eliminacao_declaracoes (codigo, restaurante_id, restaurante_nome, email_lookup, cancelado_em, eliminado_em, linhas_apagadas, detalhe_json, hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$codigo, $rid, $e['nome'], $lookup, $e['cancelado_em'], $agora, $total, json_encode($det), $hash]);
        audit_log($db, 'restaurante_dados_eliminados', ['restaurante_id' => $rid, 'linhas' => $total, 'declaracao' => $codigo], 'WARN');
        return ['codigo' => $codigo, 'restaurante' => $e['nome'], 'eliminado_em' => $agora, 'linhas' => $total, 'hash' => $hash];
    }

    /* ---------------- Rotina diaria ---------------- */

    function enc_email_admin(PDO $db, int $rid): ?string
    {
        $s = $db->prepare("SELECT email FROM usuarios WHERE restaurante_id = ? AND perfil = 'ADMIN' AND COALESCE(ativo,1) = 1 ORDER BY id LIMIT 1");
        $s->execute([$rid]);
        $v = $s->fetchColumn();
        if ($v === false || $v === null) {
            $s = $db->prepare('SELECT email FROM restaurantes WHERE id = ?');
            $s->execute([$rid]);
            $v = $s->fetchColumn();
        }
        $v = $v ? personal_data_decrypt((string)$v) : null;
        return ($v && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : null;
    }

    function enc_enviar(PDO $db, int $rid, string $assunto, string $html): bool
    {
        $para = enc_email_admin($db, $rid);
        if (!$para || !function_exists('saas_enviar_email')) {
            return false;
        }
        try {
            return (bool)saas_enviar_email($para, $assunto, "<html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:620px'>{$html}<hr><p style='font-size:12px;color:#888'>Email automatico.</p></body></html>");
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Rotina diaria. Devolve um registo do que fez (ou faria, com $simular).
     * 1) cancelamentos cuja data chegou -> status CANCELADO + data de eliminacao;
     * 2) aviso ENC_DIAS_AVISO dias antes de apagar;
     * 3) eliminacao com declaracao.
     */
    function enc_rotina_diaria(PDO $db, bool $simular = false, ?string $hoje = null): array
    {
        $hoje = $hoje ?? date('Y-m-d');
        $log = [];
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $s = $db->prepare("SELECT id, nome FROM restaurantes WHERE cancelamento_efetivo_em IS NOT NULL AND cancelamento_efetivo_em < ? AND cancelado_em IS NULL");
        $s->execute([$hoje]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $elim = (new DateTimeImmutable($hoje))->modify('+' . (ENC_DIAS_EXPORTAR + ENC_DIAS_ELIMINAR) . ' days')->format('Y-m-d');
            $log[] = "Cancelado #{$r['id']} {$r['nome']} (apagar em $elim)";
            if ($simular) {
                continue;
            }
            $db->prepare("UPDATE restaurantes SET status = 'CANCELADO', cancelado_em = NOW(), eliminacao_agendada_em = ? WHERE id = ?")->execute([$elim, $r['id']]);
            audit_log($db, 'plano_cancelamento_efetivado', ['restaurante_id' => (int)$r['id'], 'eliminacao_em' => $elim], 'WARN');
            enc_enviar($db, (int)$r['id'], 'A sua conta foi encerrada - exporte os seus dados',
                "<h2>Conta encerrada</h2><p>A conta <strong>{$esc($r['nome'])}</strong> foi encerrada, como pediu.</p>"
                . '<p>Tem <strong>' . ENC_DIAS_EXPORTAR . ' dias</strong> para exportar os seus dados: entre no sistema com a conta de administrador e abra <strong>Meus dados</strong>.</p>'
                . '<p>Os dados serao apagados definitivamente a <strong>' . date('d/m/Y', strtotime($elim)) . '</strong>, e enviaremos a declaracao de eliminacao.</p>');
        }

        $limiteAviso = (new DateTimeImmutable($hoje))->modify('+' . ENC_DIAS_AVISO . ' days')->format('Y-m-d');
        $s = $db->prepare("SELECT id, nome, eliminacao_agendada_em FROM restaurantes WHERE status = 'CANCELADO' AND eliminacao_agendada_em IS NOT NULL AND eliminacao_agendada_em <= ? AND eliminacao_agendada_em > ? AND eliminacao_aviso_em IS NULL");
        $s->execute([$limiteAviso, $hoje]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $log[] = "Aviso #{$r['id']} {$r['nome']} (apaga a {$r['eliminacao_agendada_em']})";
            if ($simular) {
                continue;
            }
            enc_enviar($db, (int)$r['id'], 'Os seus dados vao ser apagados',
                "<h2>Ultimo aviso</h2><p>Os dados de <strong>{$esc($r['nome'])}</strong> serao apagados definitivamente a <strong>" . date('d/m/Y', strtotime((string)$r['eliminacao_agendada_em'])) . '</strong>.</p><p>Se ainda nao exportou, entre no sistema e abra <strong>Meus dados</strong> antes dessa data.</p>');
            $db->prepare('UPDATE restaurantes SET eliminacao_aviso_em = NOW() WHERE id = ?')->execute([$r['id']]);
        }

        $s = $db->prepare("SELECT id, nome FROM restaurantes WHERE status = 'CANCELADO' AND eliminacao_agendada_em IS NOT NULL AND eliminacao_agendada_em <= ?");
        $s->execute([$hoje]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $log[] = "Eliminar #{$r['id']} {$r['nome']}";
            if ($simular) {
                continue;
            }
            $para = enc_email_admin($db, (int)$r['id']);
            $d = enc_eliminar_com_declaracao($db, (int)$r['id']);
            $log[] = "  declaracao {$d['codigo']} ({$d['linhas']} linhas)";
            if ($para && function_exists('saas_enviar_email')) {
                @saas_enviar_email($para, 'Declaracao de eliminacao de dados',
                    "<html><body style='font-family:Arial,sans-serif'><h2>Declaracao de eliminacao</h2>"
                    . "<p>Declaramos que os dados da conta <strong>{$esc($d['restaurante'])}</strong> foram apagados definitivamente dos nossos sistemas a " . date('d/m/Y H:i', strtotime($d['eliminado_em'])) . ' (' . (int)$d['linhas'] . ' registos).</p>'
                    . "<p>Codigo da declaracao: <strong>{$esc($d['codigo'])}</strong><br>Impressao digital (SHA-256): <code>{$esc($d['hash'])}</code></p>"
                    . '<p>As copias de seguranca sao substituidas automaticamente num ciclo de ate 35 dias, apos o qual os dados deixam de existir tambem nelas.</p></body></html>');
            }
        }
        return $log;
    }
}

if (!function_exists('enc_unidades_rede')) {
    /** Matriz + filiais activas (Empresarial). Devolve [['id' => int, 'nome' => string, 'matriz' => bool], ...]. */
    function enc_unidades_rede(PDO $db, int $matrizId): array
    {
        $st = $db->prepare("
            SELECT id, nome, COALESCE(is_matriz, 0) AS is_matriz
            FROM restaurantes
            WHERE id = :rid1 OR filial_id = :rid2
            ORDER BY COALESCE(is_matriz, 0) DESC, nome
        ");
        $st->execute([':rid1' => $matrizId, ':rid2' => $matrizId]);
        $unidades = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $unidades[] = [
                'id' => (int)$linha['id'],
                'nome' => (string)$linha['nome'],
                'matriz' => (int)$linha['is_matriz'] === 1 || (int)$linha['id'] === $matrizId,
            ];
        }
        return $unidades;
    }
}

if (!function_exists('enc_exportar_zip_rede')) {
    /**
     * Exportacao da rede: um ZIP que contem o ZIP de cada unidade (matriz e filiais).
     * Devolve o caminho do ficheiro temporario criado.
     */
    function enc_exportar_zip_rede(PDO $db, int $matrizId): string
    {
        $unidades = enc_unidades_rede($db, $matrizId);
        if (count($unidades) <= 1) {
            return enc_exportar_zip($db, $matrizId);
        }

        $base = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : (nv_storage());
        $base = rtrim($base, '/\\') . DIRECTORY_SEPARATOR;
        $zipPath = $base . 'nv_export_rede_' . bin2hex(random_bytes(6)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o ficheiro da rede.');
        }

        $temporarios = [];
        $linhasIndice = ["Exportação da rede — " . date('d/m/Y H:i'), ''];
        try {
            foreach ($unidades as $unidade) {
                $etiqueta = ($unidade['matriz'] ? 'matriz' : 'filial') . '-' . $unidade['id'];
                try {
                    $zipUnidade = enc_exportar_zip($db, (int)$unidade['id']);
                    $temporarios[] = $zipUnidade;
                    $zip->addFile($zipUnidade, $etiqueta . '.zip');
                    $linhasIndice[] = $etiqueta . '.zip — ' . $unidade['nome'] . ($unidade['matriz'] ? ' (matriz)' : '');
                } catch (Throwable $e) {
                    error_log('enc_exportar_zip_rede ' . $unidade['id'] . ': ' . $e->getMessage());
                    $linhasIndice[] = $etiqueta . ' — FALHOU: ' . $e->getMessage();
                }
            }
            $zip->addFromString('LEIA-ME.txt', implode("\r\n", $linhasIndice) . "\r\n");
            $zip->close();
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($zipPath);
            foreach ($temporarios as $tmpZip) {
                @unlink($tmpZip);
            }
            throw $e;
        }

        foreach ($temporarios as $tmpZip) {
            @unlink($tmpZip);
        }

        return $zipPath;
    }
}
