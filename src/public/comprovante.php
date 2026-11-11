<?php
require_once __DIR__ . '/app_base.php';
// Proteção da página
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/Model/Venda.php';

// Conectar ao banco
$database = new Database();
$db = $database->getConnection();

$venda = new Venda($db);

// Buscar venda
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$auto_print = isset($_GET['auto_print']) && $_GET['auto_print'] === '1';
$paper_width = isset($_GET['paper']) && (int)$_GET['paper'] === 58 ? 58 : 80;

if (!$id) {
    header("Location: vendas.php");
    exit;
}

$dados_venda = $venda->buscarPorId($id, $_SESSION['restaurante_id']);

if (!$dados_venda) {
    header("Location: vendas.php");
    exit;
}

// Buscar itens da venda
$itens = $venda->buscarItens($id);

$restaurante = [
    'nome' => 'RestauranteSaaS',
    'logo' => '',
];

try {
    $stmtRestaurante = $db->prepare("SELECT nome, logo FROM restaurantes WHERE id = :id LIMIT 1");
    $stmtRestaurante->bindValue(':id', (int)$_SESSION['restaurante_id'], PDO::PARAM_INT);
    $stmtRestaurante->execute();
    $restauranteDb = $stmtRestaurante->fetch(PDO::FETCH_ASSOC);

    if (is_array($restauranteDb)) {
        $restaurante['nome'] = trim((string)($restauranteDb['nome'] ?? '')) !== ''
            ? (string)$restauranteDb['nome']
            : $restaurante['nome'];
        $restaurante['logo'] = trim((string)($restauranteDb['logo'] ?? ''));
    }
} catch (Throwable $e) {
    // Mantem fallback silencioso no comprovante.
}

function esc($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function restaurante_logo_url(?string $logoPath): string
{
    $logoPath = trim((string)$logoPath);
    if ($logoPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $logoPath)) {
        return $logoPath;
    }

    return ltrim(str_replace('\\', '/', $logoPath), '/');
}

$logoUrl = restaurante_logo_url($restaurante['logo'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprovante #<?php echo esc($dados_venda['numero_fatura']); ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background:
                radial-gradient(circle at top right, rgba(255, 107, 53, 0.16), transparent 24%),
                radial-gradient(circle at top left, rgba(247, 147, 30, 0.1), transparent 20%),
                linear-gradient(180deg, #f7f8fc 0%, #eef2f9 100%);
            display: flex;
            justify-content: center;
            padding: 18px 10px;
            color: #1f2937;
        }

        .comprovante {
            background: #fff;
            width: 300px;
            padding: 14px;
            border-radius: 20px;
            border: 1px solid rgba(17, 24, 39, 0.08);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
        }

        .header {
            text-align: center;
            padding: 16px 12px 14px;
            margin-bottom: 12px;
            border-radius: 18px;
            background:
                radial-gradient(circle at top right, rgba(247, 147, 30, 0.24), transparent 34%),
                linear-gradient(135deg, #171a2a 0%, #242945 100%);
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .header::after {
            content: '';
            position: absolute;
            inset: auto -30px -30px auto;
            width: 110px;
            height: 110px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.12), transparent 70%);
            pointer-events: none;
        }

        .logo-wrap {
            width: 72px;
            height: 72px;
            margin: 0 auto 10px;
            border-radius: 20px;
            padding: 8px;
            background: linear-gradient(145deg, #ffffff 0%, #f4f4f4 100%);
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow:
                0 10px 20px rgba(0, 0, 0, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 14px;
            display: block;
        }

        .header h1 {
            font-size: 1.3rem;
            font-weight: 800;
            margin-bottom: 4px;
            letter-spacing: -0.03em;
        }

        .header p {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.5;
        }

        .brand-chip {
            display: inline-block;
            margin: 2px 0 8px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 9px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #fff3ea;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
        }

        .info-venda {
            padding: 10px 10px 4px;
            margin-bottom: 10px;
            font-size: 10.5px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid rgba(148, 163, 184, 0.18);
        }

        .info-venda div {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            color: #475569;
        }

        .info-venda span:last-child,
        .info-venda strong {
            text-align: right;
            color: #111827;
        }

        .itens-titulo {
            font-size: 10px;
            font-weight: 700;
            text-align: center;
            border: 1px dashed rgba(100, 116, 139, 0.4);
            color: #475569;
            border-radius: 999px;
            padding: 6px 0;
            margin-bottom: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .item {
            font-size: 10.5px;
            margin-bottom: 8px;
            padding: 8px 10px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid rgba(226, 232, 240, 0.9);
        }

        .item-nome {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .item-detalhe {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: #64748b;
        }

        .totais {
            padding: 10px 10px 4px;
            margin-top: 10px;
            font-size: 11px;
            border-radius: 14px;
            background: linear-gradient(180deg, #fff7f2 0%, #fff 100%);
            border: 1px solid rgba(255, 107, 53, 0.14);
        }

        .totais div {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .total-final {
            font-size: 15px !important;
            font-weight: 800;
            border-top: 1px dashed rgba(255, 107, 53, 0.32);
            padding-top: 8px;
            margin-top: 6px;
            color: #111827;
        }

        .pagamento {
            text-align: center;
            margin-top: 12px;
            padding: 10px 8px;
            background: linear-gradient(135deg, #fff 0%, #f8fafc 100%);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 14px;
            font-size: 10.5px;
            color: #334155;
        }

        .footer {
            text-align: center;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px dashed rgba(100, 116, 139, 0.35);
            font-size: 9.5px;
            color: #777;
            line-height: 1.65;
        }

        .btn-imprimir {
            display: block;
            width: 100%;
            padding: 10px;
            background: #FF6B35;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 14px;
            box-shadow: 0 16px 28px rgba(255, 107, 53, 0.24);
        }

        .btn-voltar {
            display: block;
            width: 100%;
            padding: 9px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
            margin-top: 8px;
            text-decoration: none;
            text-align: center;
        }

        .muted-divider {
            height: 1px;
            margin: 10px 0;
            background: linear-gradient(90deg, transparent 0%, rgba(148, 163, 184, 0.4) 50%, transparent 100%);
        }

        @media print {
            @page {
                size: <?php echo $paper_width; ?>mm auto;
                margin: 0;
            }

            body {
                background: white;
                padding: 0;
            }

            .comprovante {
                box-shadow: none;
                border: none;
                border-radius: 0;
                width: 100%;
            }

            .btn-imprimir,
            .btn-voltar {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="comprovante">

        <!-- CABEÇALHO -->
        <div class="header">
            <?php if ($logoUrl !== ''): ?>
                <div class="logo-wrap">
                    <img src="<?php echo esc($logoUrl); ?>" alt="Logotipo do restaurante">
                </div>
            <?php endif; ?>
            <h1><?php echo esc($restaurante['nome']); ?></h1>
            <div class="brand-chip">Comprovante Oficial</div>
            <p>
                Comprovante de Venda<br>
                <?php echo date('d/m/Y H:i:s', strtotime($dados_venda['criado_em'])); ?>
            </p>
        </div>

        <!-- INFORMAÇÕES DA VENDA -->
        <div class="info-venda">
            <div>

                <?php if ($auto_print): ?>
                    <script nonce="<?php echo function_exists('security_csp_nonce') ? security_csp_nonce() : ''; ?>">
                        (function () {
                            function dispararImpressao() {
                                try {
                                    window.focus();
                                    window.print();
                                } catch (e) {
                                    console.error('Falha ao imprimir comprovante', e);
                                }
                            }
                            if (document.readyState === 'complete') {
                                setTimeout(dispararImpressao, 300);
                            } else {
                                window.addEventListener('load', function () {
                                    setTimeout(dispararImpressao, 300);
                                });
                            }
                        })();
                    </script>
                <?php endif; ?>
                <span>Fatura Nº:</span>
                <strong><?php echo esc($dados_venda['numero_fatura']); ?></strong>
            </div>
            <div>
                <span>Atendente:</span>
                <span><?php echo esc($dados_venda['usuario_nome']); ?></span>
            </div>
            <?php if ($dados_venda['mesa_numero']): ?>
                <div>
                    <span>Mesa:</span>
                    <span>Mesa <?php echo esc($dados_venda['mesa_numero']); ?></span>
                </div>
            <?php else: ?>
                <div class="balcao-label">
                    <span>Local:</span>
                    <span>Balcão</span>
                </div>
            <?php endif; ?>
            <div>
                <span>Status:</span>
                <strong style="color: <?php echo $dados_venda['status'] == 'PAGO' ? 'green' : 'red'; ?>">
                    <?php echo esc($dados_venda['status']); ?>
                </strong>
            </div>
        </div>

        <div class="muted-divider"></div>

        <!-- ITENS -->
        <div class="itens-titulo">--- ITENS DO PEDIDO ---</div>

        <?php while ($item = $itens->fetch(PDO::FETCH_ASSOC)): ?>
            <div class="item">
                <div class="item-nome"><?php echo esc($item['produto_nome']); ?></div>
                <div class="item-detalhe">
                    <span><?php echo esc($item['quantidade']); ?>x <?php echo number_format($item['preco_unitario'], 2, ',', '.'); ?> MZN</span>
                    <strong><?php echo number_format($item['subtotal'], 2, ',', '.'); ?> MZN</strong>
                </div>
            </div>
        <?php endwhile; ?>

        <!-- TOTAIS -->
        <div class="totais">
            <div>
                <span>Subtotal:</span>
                <span><?php echo number_format($dados_venda['total'], 2, ',', '.'); ?> MZN</span>
            </div>
            <?php if ($dados_venda['desconto'] > 0): ?>
                <div>
                    <span>Desconto:</span>
                    <span style="color: red;">- <?php echo number_format($dados_venda['desconto'], 2, ',', '.'); ?> MZN</span>
                </div>
            <?php endif; ?>
            <?php if (isset($dados_venda['valor_recebido']) && $dados_venda['valor_recebido'] !== null): ?>
                <div>
                    <span>Valor Recebido:</span>
                    <span><?php echo number_format($dados_venda['valor_recebido'], 2, ',', '.'); ?> MZN</span>
                </div>
            <?php endif; ?>
            <?php if (isset($dados_venda['troco']) && $dados_venda['troco'] !== null): ?>
                <div>
                    <span>Troco:</span>
                    <strong style="color: <?php echo $dados_venda['troco'] < 0 ? 'red' : 'green'; ?>;">
                        <?php echo number_format($dados_venda['troco'], 2, ',', '.'); ?> MZN
                    </strong>
                </div>
            <?php endif; ?>
            <div class="total-final">
                <span>TOTAL:</span>
                <span><?php echo number_format($dados_venda['total_final'], 2, ',', '.'); ?> MZN</span>
            </div>
        </div>

        <!-- FORMA DE PAGAMENTO -->
        <div class="pagamento">
            💳 Pagamento: <strong>
                <?php
                $formas = [
                    'DINHEIRO'      => '💵 Dinheiro',
                    'MPESA'         => '📱 M-Pesa',
                    'EMOLA'         => '📱 e-Mola - 879778409',
                    'CARTAO'        => '💳 Cartão',
                    'TRANSFERENCIA' => '🏦 Transferência'
                ];
                echo esc($formas[$dados_venda['forma_pagamento']] ?? $dados_venda['forma_pagamento']);
                ?>
            </strong>
        </div>

        <!-- RODAPÉ -->
        <div class="footer">
            <p>Obrigado pela sua preferência!</p>
            <p>Volte sempre 😊</p>
            <p style="margin-top: 8px; font-size: 10px;">
                Sistema RestauranteSaaS<br>
                Desenvolvido para Moçambique 🇲🇿
            </p>
        </div>

        <!-- BOTÕES (não aparecem na impressão) -->
        <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir Comprovante</button>
        <a href="vendas.php" class="btn-voltar">← Voltar ao PDV</a>

    </div>

</body>

</html>



