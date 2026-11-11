<?php
require_once __DIR__ . '/app_base.php';
// Painel visual para relatório de reservas e ocupação
// Endpoint: /src/public/relatorio_reservas.php
include_once APP_BASE . '/src/config/auth_check.php';
include_once APP_BASE . '/src/config/database.php';
include_once APP_BASE . '/src/config/input_validation.php';

$restaurante_id = $_SESSION['restaurante_id'] ?? 0;
if ($restaurante_id <= 0) {
    header('Location: login.php');
    exit;
}

// Parâmetros de filtro
$data_inicio = input_iso_date($_GET['data_inicio'] ?? date('Y-m-01'));
$data_fim = input_iso_date($_GET['data_fim'] ?? date('Y-m-d'));
if ($data_inicio === null || $data_fim === null || $data_inicio > $data_fim) {
    header('Location: relatorio_reservas.php');
    exit;
}

// Chama a API criada
$api_url = __DIR__ . '/../api/relatorio_reservas.php';
$_GET['restaurante_id'] = $restaurante_id;
$_GET['data_inicio'] = $data_inicio;
$_GET['data_fim'] = $data_fim;
ob_start();
include $api_url;
$json = ob_get_clean();
$dados = json_decode($json, true);

function status_badge($status)
{
    $map = [
        'pendente' => 'warning',
        'confirmado' => 'success',
        'no-show' => 'danger',
        'cancelado' => 'secondary',
    ];
    return $map[$status] ?? 'light';
}
?>
<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="js/nv_acoes.js?v=<?php echo (int)@filemtime(__DIR__ . '/js/nv_acoes.js'); ?>"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Reservas e Ocupação</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --rr-primary: #FF6B35;
            --rr-secondary: #F7931E;
            --rr-text: #1e293b;
            --rr-muted: #64748b;
            --rr-border: #e2e8f0;
            --rr-bg: #f8fafc;
            --rr-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            overflow-x: hidden;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background:
                radial-gradient(circle at top right, rgba(255, 107, 53, 0.08), transparent 28%),
                linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
            color: var(--rr-text);
            min-height: 100vh;
        }

        .reservas-shell {
            max-width: 1240px;
        }

        .reservas-header {
            background: #fff;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 22px;
            padding: 20px 22px;
            box-shadow: var(--rr-shadow);
            margin-bottom: 20px;
        }

        .reservas-header h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            margin: 0;
            font-size: clamp(1.4rem, 2.2vw, 2rem);
        }

        .reservas-header p {
            margin: 8px 0 0;
            color: var(--rr-muted);
        }

        .reservas-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.12), rgba(247, 147, 30, 0.12));
            border: 1px solid rgba(255, 107, 53, 0.18);
            color: var(--rr-text);
            font-weight: 700;
        }

        .reservas-form {
            background: #fff;
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            padding: 18px;
            box-shadow: var(--rr-shadow);
            margin-bottom: 20px;
        }

        .reservas-form label {
            font-weight: 700;
            color: var(--rr-text);
            margin-bottom: 6px;
        }

        .reservas-form .form-control {
            border-radius: 12px;
            border: 2px solid var(--rr-border);
            padding: 11px 14px;
            font-size: 14px;
        }

        .reservas-form .form-control:focus {
            border-color: var(--rr-primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.1);
        }

        .reservas-form .btn {
            border-radius: 12px;
            font-weight: 700;
            padding: 11px 18px;
        }

        .summary-grid {
            margin-bottom: 20px;
        }

        .summary-card {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 18px;
            box-shadow: var(--rr-shadow);
            min-height: 100%;
        }

        .summary-card h6 {
            color: var(--rr-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .summary-card .fs-4 {
            font-family: 'Space Grotesk', sans-serif;
        }

        .table-shell {
            background: #fff;
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 18px;
            box-shadow: var(--rr-shadow);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .table-shell h5 {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            color: var(--rr-text);
        }

        .table-shell .table {
            margin-bottom: 0;
        }

        .table-shell .table thead th {
            background: #f8fafc;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.35px;
            color: var(--rr-muted);
            border-bottom: 1px solid var(--rr-border);
        }

        .table-shell .table tbody td {
            vertical-align: middle;
        }

        .badge {
            white-space: normal;
            line-height: 1.2;
        }

        @media (max-width: 991px) {
            .reservas-header {
                padding: 18px;
            }

            .reservas-form {
                padding: 16px;
            }

            .summary-grid>.col-md-3 {
                flex: 0 0 50%;
                max-width: 50%;
            }
        }

        @media (max-width: 768px) {
            .reservas-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 12px;
            }

            .reservas-chip {
                width: 100%;
                justify-content: center;
            }

            .reservas-form {
                padding: 14px;
            }

            .reservas-form .col-auto {
                width: 100%;
            }

            .reservas-form .btn {
                width: 100%;
            }

            .summary-grid>.col-md-3 {
                flex: 0 0 100%;
                max-width: 100%;
            }

            .table-shell {
                border-radius: 16px;
            }
        }

        @media (max-width: 576px) {
            .reservas-shell {
                padding-left: 12px;
                padding-right: 12px;
            }

            .reservas-header {
                padding: 16px;
                border-radius: 18px;
            }

            .reservas-header h2 {
                font-size: 1.25rem;
            }

            .reservas-header p {
                font-size: 13px;
            }

            .reservas-form {
                padding: 12px;
                border-radius: 16px;
            }

            .reservas-form .form-control {
                font-size: 13px;
                padding: 10px 12px;
            }

            .table-shell .table thead th,
            .table-shell .table tbody td {
                padding: 10px 12px;
                font-size: 12px;
                white-space: normal;
            }
        }

        /* Responsividade para possível menu lateral futuro */
        .sidebar.sidebar-hidden {
            left: -100vw !important;
            transition: left 0.3s cubic-bezier(.4, 0, .2, 1);
        }
    </style>
</head>

<body>
    <!-- Botão de alternância do menu lateral para mobile (padrão visual) -->

    <div class="container-fluid py-4 reservas-shell">
        <div class="reservas-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2>Relatório de Reservas e Ocupação</h2>
                <p>Visão resumida das reservas confirmadas, no-show e ocupação por mesa.</p>
            </div>
            <div class="reservas-chip">
                <i class="fas fa-calendar-check"></i>
                <?php echo htmlspecialchars($data_inicio); ?> até <?php echo htmlspecialchars($data_fim); ?>
            </div>
        </div>

        <form class="reservas-form row g-3 align-items-end" method="get">
            <div class="col-auto">
                <label>Início</label>
                <input type="date" name="data_inicio" class="form-control" value="<?= htmlspecialchars($data_inicio) ?>">
            </div>
            <div class="col-auto">
                <label>Fim</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($data_fim) ?>">
            </div>
            <div class="col-auto align-self-end">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>
        <?php if (!empty($dados['success'])): ?>
            <div class="row g-3 summary-grid mb-4">
                <div class="col-md-3">
                    <div class="card card-body text-center summary-card">
                        <h6>Total Reservas</h6>
                        <span class="fs-4 fw-bold"><?= $dados['total_reservas'] ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-body text-center summary-card">
                        <h6>Confirmadas</h6>
                        <span class="fs-4 text-success fw-bold"><?= $dados['status']['confirmado'] ?? 0 ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-body text-center summary-card">
                        <h6>No-show</h6>
                        <span class="fs-4 text-danger fw-bold"><?= $dados['no_show'] ?></span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-body text-center summary-card">
                        <h6>Taxa Ocupação</h6>
                        <span class="fs-4 fw-bold"><?= $dados['taxa_ocupacao'] ?>%</span>
                    </div>
                </div>
            </div>
            <div class="table-shell mb-4">
                <div class="p-3 p-md-4 border-bottom">
                    <h5 class="mb-0">Ocupação por Mesa</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Mesa</th>
                                <th>Reservas Confirmadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados['ocupacao_mesas'] as $m): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['mesa_atribuida']) ?></td>
                                    <td><?= $m['total'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="table-shell">
                <div class="p-3 p-md-4 border-bottom">
                    <h5 class="mb-0">Reservas Detalhadas</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Hora</th>
                                <th>Cliente</th>
                                <th>Pessoas</th>
                                <th>Status</th>
                                <th>Mesa</th>
                                <th>Contato</th>
                                <th>Obs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dados['reservas'] as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['data_reserva']) ?></td>
                                    <td><?= htmlspecialchars($r['hora_reserva']) ?></td>
                                    <td><?= htmlspecialchars($r['nome_cliente']) ?></td>
                                    <td><?= (int)$r['quantidade_pessoas'] ?></td>
                                    <td><span class="badge bg-<?= status_badge($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                    <td><?= htmlspecialchars($r['mesa_atribuida']) ?></td>
                                    <td><?= htmlspecialchars($r['telefone_cliente']) ?><br><?= htmlspecialchars($r['email_cliente']) ?></td>
                                    <td><?= htmlspecialchars($r['observacoes']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">Erro ao carregar relatório.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>


