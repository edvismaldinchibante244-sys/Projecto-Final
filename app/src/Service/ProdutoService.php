<?php

/*
 
   SERVIÇO DE PRODUTOS - SERVICE LAYER
 
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/plano_check.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../Model/Produto.php';

class ProdutoService
{
    private $db;
    private $produto;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->produto = new Produto($this->db);
    }

    /**
     * Listar todos os produtos
     */
    public function listar($restaurante_id)
    {
        $stmt = $this->produto->listar($restaurante_id);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Buscar produto por ID
     */
    public function buscarPorId($id, $restaurante_id)
    {
        return $this->produto->buscarPorId($id, $restaurante_id);
    }

    /**
     * Cadastrar produto
     */
    public function cadastrar($dados)
    {
        $restauranteId = (int)($dados['restaurante_id'] ?? 0);
        $planoRestauranteId = (int)($dados['plano_restaurante_id'] ?? $restauranteId);
        $temMultiFilial = $planoRestauranteId > 0 && plano_tem_funcionalidade_db($planoRestauranteId, 'multi_filial');

        if ($temMultiFilial) {
            $stmtCount = $this->db->prepare("
                SELECT COUNT(*)
                FROM produtos p
                INNER JOIN restaurantes r ON r.id = p.restaurante_id
                WHERE p.ativo = 1
                  AND (p.restaurante_id = :base_restaurante_id OR r.filial_id = :filial_base_id)
            ");
            $stmtCount->bindValue(':base_restaurante_id', $planoRestauranteId, PDO::PARAM_INT);
            $stmtCount->bindValue(':filial_base_id', $planoRestauranteId, PDO::PARAM_INT);
            $stmtCount->execute();
            $totalProdutosAtivos = (int)$stmtCount->fetchColumn();
        } else {
            $totalProdutosAtivos = (int)$this->produto->contarAtivos($restauranteId);
        }

        $verificacaoPlano = plano_verificar_limite_db($planoRestauranteId > 0 ? $planoRestauranteId : $restauranteId, 'produtos', $totalProdutosAtivos);

        if (!$verificacaoPlano['permitido']) {
            return array(
                "success" => false,
                "code" => "LIMITE_EXCEDIDO",
                "message" => function_exists('plano_mensagem_limite_atingido')
                    ? plano_mensagem_limite_atingido($verificacaoPlano, 'produtos')
                    : "Limite do plano atingido. O plano {$verificacaoPlano['plano']} permite até {$verificacaoPlano['limite']} produtos.",
                "upgrade_url" => "configuracoes.php?secao=plano",
                "limite" => $verificacaoPlano['limite'] ?? null,
                "atual" => $verificacaoPlano['atual'] ?? null,
            );
        }

        $categoriaIdValidada = $this->normalizarCategoriaId(
            $dados['categoria_id'] ?? null,
            $restauranteId
        );

        $this->produto->restaurante_id = $restauranteId;
        $this->produto->categoria_id = $categoriaIdValidada;
        $this->produto->nome = $dados['nome'];
        $this->produto->descricao = $dados['descricao'] ?? '';
        $this->produto->preco = $dados['preco'];
        $this->produto->custo = $dados['custo'] ?? 0;
        $this->produto->estoque = $dados['estoque'] ?? 0;
        $this->produto->estoque_minimo = $dados['estoque_minimo'] ?? 5;
        $this->produto->ativo = $dados['ativo'] ?? 1;
        $this->produto->destaque = $dados['destaque'] ?? 0;
        // sanitize imagem path (remove redundant src/public prefix)
        $img = $dados['imagem'] ?? null;
        if ($img) {
            $img = str_replace('src/public/', '', $img);
        }
        $this->produto->imagem = $img;

        $id = $this->produto->cadastrar();

        if ($id) {
            return array("success" => true, "id" => $id, "message" => "Produto cadastrado com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao cadastrar produto.");
    }

    /**
     * Editar produto
     */
    public function editar($dados)
    {
        $restauranteId = (int)($dados['restaurante_id'] ?? 0);
        $produtoId = (int)($dados['id'] ?? 0);

        if ($produtoId <= 0 || $restauranteId <= 0) {
            return array("success" => false, "message" => "Produto ou restaurante inválido.");
        }

        $atual = $this->produto->buscarPorId($produtoId, $restauranteId);
        if (!$atual) {
            return array("success" => false, "message" => "Produto não encontrado neste restaurante.");
        }

        $categoriaIdValidada = $this->normalizarCategoriaId(
            $dados['categoria_id'] ?? null,
            $restauranteId
        );

        // Preserva imagem atual se não veio caminho novo
        $img = $dados['imagem'] ?? null;
        if ($img) {
            $img = str_replace('src/public/', '', (string)$img);
            $img = ltrim(str_replace('\\', '/', $img), '/');
        } else {
            $img = $atual['imagem'] ?? null;
            if ($img) {
                $img = str_replace('src/public/', '', (string)$img);
                $img = ltrim(str_replace('\\', '/', $img), '/');
            }
        }

        $this->produto->id = $produtoId;
        $this->produto->restaurante_id = $restauranteId;
        $this->produto->categoria_id = $categoriaIdValidada;
        $this->produto->nome = $dados['nome'];
        $this->produto->descricao = $dados['descricao'] ?? '';
        $this->produto->preco = $dados['preco'];
        $this->produto->custo = $dados['custo'] ?? 0;
        $this->produto->estoque = $dados['estoque'] ?? 0;
        $this->produto->estoque_minimo = $dados['estoque_minimo'] ?? 5;
        $this->produto->ativo = $dados['ativo'] ?? 1;
        $this->produto->destaque = $dados['destaque'] ?? 0;
        $this->produto->imagem = $img ?: null;

        if ($this->produto->editar()) {
            return array("success" => true, "message" => "Produto atualizado com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao atualizar produto.");
    }

    /**
     * Excluir produto
     */
    public function deletar($id, $restaurante_id)
    {
        if ($this->produto->deletar($id, $restaurante_id)) {
            return array("success" => true, "message" => "Produto excluido com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao excluir produto.");
    }

    /**
     * Atualizar estoque
     */
    public function atualizarEstoque($id, $restaurante_id, $quantidade, $tipo = 'ENTRADA')
    {
        if ($this->produto->atualizarEstoque($id, $restaurante_id, $quantidade, $tipo)) {
            return array("success" => true, "message" => "Estoque atualizado com sucesso!");
        }

        return array("success" => false, "message" => "Erro ao atualizar estoque.");
    }

    /**
     * Listar produtos com estoque baixo
     */
    public function estoqueBaixo($restaurante_id)
    {
        $stmt = $this->produto->estoqueBaixo($restaurante_id);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Contar produtos ativos
     */
    public function contarAtivos($restaurante_id)
    {
        return $this->produto->contarAtivos($restaurante_id);
    }

    /**
     * Processar upload de imagem: fundo sólido, resize e compressão JPEG.
     */
    public function processarImagem($file)
    {
        try {
            if (!isset($file) || !is_array($file)) {
                return null;
            }
            if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return null;
            }

            $tmp_name = (string)($file['tmp_name'] ?? '');
            if ($tmp_name === '' || !is_file($tmp_name) || !is_readable($tmp_name)) {
                error_log('[ProdutoService] tmp inválido: ' . $tmp_name);
                return null;
            }

            // Em alguns ambientes Windows/OneDrive is_uploaded_file falha indevidamente
            $isUpload = @is_uploaded_file($tmp_name);

            $maxBytes = 16 * 1024 * 1024;
            $size = (int)($file['size'] ?? @filesize($tmp_name) ?: 0);
            if ($size <= 0 || $size > $maxBytes) {
                error_log('[ProdutoService] Imagem rejeitada por tamanho: ' . $size);
                return null;
            }

            $upload_dir = $this->resolverDirectorioImagens();
            if ($upload_dir === null) {
                error_log('[ProdutoService] Pasta images/ indisponível para escrita');
                return null;
            }

            $info = @getimagesize($tmp_name);
            $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
            $src = null;

            if (extension_loaded('gd')) {
                if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
                    $src = @imagecreatefromjpeg($tmp_name);
                } elseif ($mime === 'image/png') {
                    $src = @imagecreatefrompng($tmp_name);
                } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                    $src = @imagecreatefromwebp($tmp_name);
                } elseif ($mime === 'image/gif') {
                    $src = @imagecreatefromgif($tmp_name);
                }
            }

            if ($src) {
                $srcW = imagesx($src);
                $srcH = imagesy($src);
                if ($srcW > 0 && $srcH > 0) {
                    $maxSide = 1200;
                    $scale = min(1.0, $maxSide / max($srcW, $srcH));
                    $dstW = max(1, (int)round($srcW * $scale));
                    $dstH = max(1, (int)round($srcH * $scale));
                    $dst = @imagecreatetruecolor($dstW, $dstH);
                    if ($dst !== false) {
                        $bg = imagecolorallocate($dst, 245, 245, 247);
                        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $bg);
                        imagealphablending($dst, true);
                        @imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
                        unset($src); // imagedestroy() deprecated no PHP 8.5
                        $filename = uniqid('p_', true) . '.jpg';
                        $dest = $upload_dir . $filename;
                        $ok = @imagejpeg($dst, $dest, 85);
                        if ($ok && function_exists('imagewebp')) {
                            $mobileScale = min(1.0, 640 / max($dstW, $dstH));
                            $mobileW = max(1, (int)round($dstW * $mobileScale));
                            $mobileH = max(1, (int)round($dstH * $mobileScale));
                            $mobile = @imagecreatetruecolor($mobileW, $mobileH);
                            if ($mobile !== false) {
                                imagecopyresampled($mobile, $dst, 0, 0, 0, 0, $mobileW, $mobileH, $dstW, $dstH);
                                $mobilePath = $upload_dir . pathinfo($filename, PATHINFO_FILENAME) . '.mobile.webp';
                                if (!@imagewebp($mobile, $mobilePath, 78) || !is_file($mobilePath)
                                    || filesize($mobilePath) >= filesize($dest)) {
                                    @unlink($mobilePath);
                                }
                                unset($mobile);
                            }
                        }
                        unset($dst);
                        if ($ok && is_file($dest)) {
                            return 'images/' . $filename;
                        }
                    } else {
                        unset($src);
                    }
                } else {
                    unset($src);
                }
            }

            // Fallback: gravar o ficheiro original (JPG/PNG/WEBP/GIF)
            $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            if (!in_array($ext, ['jpg', 'png', 'webp', 'gif'], true)) {
                if ($mime === 'image/png') {
                    $ext = 'png';
                } elseif ($mime === 'image/webp') {
                    $ext = 'webp';
                } elseif ($mime === 'image/gif') {
                    $ext = 'gif';
                } else {
                    $ext = 'jpg';
                }
            }

            $filename = uniqid('p_', true) . '.' . $ext;
            $dest = $upload_dir . $filename;

            if ($isUpload && @move_uploaded_file($tmp_name, $dest) && is_file($dest)) {
                require_once __DIR__ . '/../config/imagem_otimizar.php';
                imagem_otimizar($dest, 1200); // foto do produto: 1200 px chega para cardapio e PDV
                return 'images/' . $filename;
            }
            if (@copy($tmp_name, $dest) && is_file($dest)) {
                return 'images/' . $filename;
            }
            $bytes = @file_get_contents($tmp_name);
            if ($bytes !== false && @file_put_contents($dest, $bytes) !== false && is_file($dest)) {
                return 'images/' . $filename;
            }

            error_log('[ProdutoService] Falha ao gravar imagem em ' . $dest);
            return null;
        } catch (Throwable $e) {
            error_log('[ProdutoService::processarImagem] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve pasta pública de imagens (V00/src/public/images).
     */
    private function resolverDirectorioImagens(): ?string
    {
        $candidates = [
            // Caminho canónico a partir deste ficheiro: app/src/Service → V00/src/public/images
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR,
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR,
        ];
        if (defined('APP_BASE')) {
            $candidates[] = rtrim((string)APP_BASE, "\\/") . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;
        }

        foreach ($candidates as $dir) {
            $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
            $dir = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir)) {
                continue;
            }

            // Teste real de escrita (is_writable falha por vezes no OneDrive)
            $probe = $dir . '.write_test_' . uniqid('', true);
            $written = @file_put_contents($probe, 'ok');
            if ($written !== false) {
                @unlink($probe);
                return $dir;
            }

            $real = @realpath($dir);
            if ($real && is_dir($real) && is_writable($real)) {
                return rtrim($real, "\\/") . DIRECTORY_SEPARATOR;
            }
        }
        return null;
    }

    /**
     * Mensagem amigável para erros de upload PHP.
     */
    public function mensagemErroUpload($file): ?string
    {
        if (!isset($file) || !is_array($file)) {
            return null;
        }
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_OK || $err === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $map = [
            UPLOAD_ERR_INI_SIZE => 'A imagem é demasiado grande para o servidor. Escolha outra foto ou aguarde a compressão automática e tente de novo.',
            UPLOAD_ERR_FORM_SIZE => 'A imagem excede o limite do formulário.',
            UPLOAD_ERR_PARTIAL => 'O upload da imagem ficou incompleto. Tente novamente.',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária em falta no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Não foi possível gravar a imagem no disco.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do servidor bloqueou o upload.',
        ];
        return $map[$err] ?? 'Falha no upload da imagem.';
    }

    /**
     * Garante que categoria_id exista e pertença ao mesmo restaurante.
     * Se inválida, retorna null para evitar falha no save/update.
     */
    private function normalizarCategoriaId($categoriaId, $restauranteId)
    {
        if (empty($categoriaId)) {
            return null;
        }

        $categoriaId = (int)$categoriaId;
        $restauranteId = (int)$restauranteId;

        if ($categoriaId <= 0 || $restauranteId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id FROM categorias WHERE id = :id AND restaurante_id = :rid LIMIT 1');
        $stmt->execute([
            ':id' => $categoriaId,
            ':rid' => $restauranteId
        ]);

        $categoriaValida = $stmt->fetch(PDO::FETCH_ASSOC);
        return $categoriaValida ? $categoriaId : null;
    }
}
