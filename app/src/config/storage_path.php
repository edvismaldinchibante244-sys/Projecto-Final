<?php
require_once __DIR__ . '/storage_path.php';
/**
 * Pasta onde o sistema grava ficheiros: logs, sessoes, backups, trabalhos,
 * 2FA, seguranca e anexos de suporte.
 *
 * Por omissao e `app/storage`, ao lado do codigo, como sempre foi. No
 * servidor pode viver fora do codigo (num volume que sobrevive aos deploys),
 * bastando definir STORAGE_PATH nas variaveis de ambiente.
 */

if (!function_exists('nv_storage')) {
    /**
     * @param string $sub Subpasta ou ficheiro dentro da pasta de ficheiros.
     */
    function nv_storage(string $sub = ''): string
    {
        static $base = null;
        if ($base === null) {
            $env = trim((string)(getenv('STORAGE_PATH') ?: ''));
            if ($env === '') {
                $env = defined('APP_BASE')
                    ? APP_BASE . '/storage'
                    : dirname(__DIR__, 2) . '/storage';
            }
            $base = rtrim(str_replace('\\', '/', $env), '/');
        }
        $sub = trim(str_replace('\\', '/', $sub), '/');
        return $sub === '' ? $base : $base . '/' . $sub;
    }
}

if (!function_exists('nv_ficheiro_privado')) {
    /**
     * Caminho absoluto de um ficheiro privado enviado por um utilizador.
     *
     * Os comprovativos de pagamento e os documentos de titularidade sao
     * documentos bancarios de clientes. Viviam dentro da pasta publica, onde o
     * Apache os servia directamente, sem passar pela verificacao de sessao que
     * o comprovativo_arquivo.php faz. Passam a viver no armazenamento privado,
     * fora da raiz do site.
     *
     * O caminho guardado na base de dados nao muda (uploads/...): muda so onde
     * o ficheiro esta, para que os registos antigos continuem validos.
     *
     * @param string $relativo Caminho como esta na base de dados.
     */
    function nv_ficheiro_privado(string $relativo): string
    {
        $relativo = ltrim(str_replace('\\', '/', $relativo), '/');
        return nv_storage('privado') . '/' . $relativo;
    }
}
