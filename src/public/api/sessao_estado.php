<?php
require_once __DIR__ . '/../app_base.php';

/*
   Estado da sessao (vigia das paginas internas, js/nv_acoes.js).
   Paginas sem atualizacoes automaticas (ex.: caixa) perguntam aqui, a cada minuto, se a sessao
   ainda e valida. O navegador envia ha quanto tempo ninguem mexe (X-Nv-Inativo), por isso esta
   pergunta NAO conta como uso. Sessao expirada: auth_check responde com X-Nv-Sessao: expirada e
   o ecra vai para o PIN (terminal) ou para o login.
   Resposta normal: 204 sem corpo.
*/
include_once APP_BASE . '/src/config/auth_check.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Cache-Control: no-store');
http_response_code(204);
