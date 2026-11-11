<?php
/**
 * Tema e flags de plano para ecrãs KDS (cozinha / bar).
 * Define: $plano_atual, $planThemeSlug, $kdsTier, $kdsLayout, $kds_*
 */
if (!function_exists('plano_get_dados')) {
    require_once APP_BASE . '/src/config/plano_check.php';
}

$kdsPlanRid = 0;
if (function_exists('session_restaurante_capability_id')) {
    $kdsPlanRid = (int)session_restaurante_capability_id();
}
if ($kdsPlanRid <= 0 && isset($restauranteId)) {
    $kdsPlanRid = (int)$restauranteId;
}
if ($kdsPlanRid <= 0) {
    $kdsPlanRid = (int)($_SESSION['restaurante_id'] ?? 0);
}

$kdsDadosPlano = plano_get_dados($kdsPlanRid);
$plano_atual = function_exists('plano_normalizar_nome')
    ? plano_normalizar_nome($kdsDadosPlano['plano_nome'] ?? 'BASICO')
    : strtoupper((string)($kdsDadosPlano['plano_nome'] ?? 'BASICO'));

$planThemeSlug = strtolower(preg_replace('/[^A-Z]/', '', (string)$plano_atual) ?: 'BASICO');
if ($planThemeSlug === '') {
    $planThemeSlug = 'basico';
}

$kdsPlanLabel = $plano_atual === 'BASICO' ? 'Básico'
    : ($plano_atual === 'PROFISSIONAL' ? 'Profissional' : 'Empresarial');

$kdsTier = $plano_atual === 'EMPRESARIAL'
    ? 'enterprise'
    : ($plano_atual === 'PROFISSIONAL' ? 'professional' : 'basic');
$kdsLayout = $plano_atual === 'EMPRESARIAL'
    ? 'intelligent-command'
    : ($plano_atual === 'PROFISSIONAL' ? 'operational-kds' : 'simple-list');
$kdsHasCriticalColumn = $kdsLayout === 'intelligent-command';

$kdsComandoCompleto = in_array($plano_atual, ['PROFISSIONAL', 'EMPRESARIAL'], true);
$kdsShowProductImages = $kdsComandoCompleto;
$kdsPredictiveEnabled = $plano_atual === 'EMPRESARIAL';

if (!function_exists('kds_partition_preparation_items')) {
    function kds_partition_preparation_items(array $items, int $slaCriticalMinutes, ?int $now = null): array
    {
        $now = $now ?? time();
        $result = ['regular' => [], 'critical' => []];
        foreach ($items as $item) {
            $startedAt = strtotime((string)($item['data_status'] ?? ''));
            $minutes = $startedAt ? max(0, (int)floor(($now - $startedAt) / 60)) : 0;
            $bucket = $minutes > $slaCriticalMinutes ? 'critical' : 'regular';
            $result[$bucket][] = $item;
        }
        return $result;
    }
}

if (!function_exists('kds_product_image_url')) {
    function kds_product_image_url($imagem): string
    {
        $path = trim((string)$imagem);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#^(\./)+#', '', $path);
        $path = str_replace('src/public/', '', $path);
        $path = ltrim($path, '/');
        if (strpos($path, '..') !== false || !preg_match('/\.(png|jpe?g|webp|gif)$/i', $path)) {
            return '';
        }
        if (strpos($path, 'images/') !== 0) {
            $path = 'images/' . $path;
        }
        return is_file(dirname(__DIR__) . '/' . $path) ? $path : '';
    }
}

// Limiares de tempo (minutos)
if ($plano_atual === 'EMPRESARIAL') {
    $kdsSlaWarn = 8;
    $kdsSlaCrit = 15;
    $kdsShowItemId = true;
    $kdsShowPedidoId = true;
    $kdsSoundAlerts = true;
    $kdsShowSummary = true;
    $kdsIntelEnabled = true;
} elseif ($plano_atual === 'PROFISSIONAL') {
    $kdsSlaWarn = 10;
    $kdsSlaCrit = 20;
    $kdsShowItemId = false;
    $kdsShowPedidoId = true;
    $kdsSoundAlerts = true;
    $kdsShowSummary = true;
    $kdsIntelEnabled = false;
} else {
    $kdsSlaWarn = 15;
    $kdsSlaCrit = 30;
    $kdsShowItemId = false;
    $kdsShowPedidoId = false;
    $kdsSoundAlerts = false;
    $kdsShowSummary = false;
    $kdsIntelEnabled = false;
}

// Básico: lista simples (sem quadro KDS escuro de 2 colunas)
$kdsModoSimples = ($plano_atual === 'BASICO');
