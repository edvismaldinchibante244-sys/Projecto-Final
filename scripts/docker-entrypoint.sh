#!/bin/sh
# Prepara o volume antes de levantar o Apache.
#
# Tudo o que o sistema grava vive em /data. As pastas publicas (uploads e
# images) passam a ser atalhos para la, para o volume cobrir tudo de uma vez
# sem partir os enderecos das imagens ja gravadas na base de dados.
set -e

DATA_DIR="${DATA_DIR:-/data}"
APP_DIR="/var/www/html"
PUBLIC_DIR="$APP_DIR/src/public"

mkdir -p \
    "$DATA_DIR/storage/logs" \
    "$DATA_DIR/storage/sessions" \
    "$DATA_DIR/storage/backups" \
    "$DATA_DIR/storage/trabalhos" \
    "$DATA_DIR/storage/2fa" \
    "$DATA_DIR/storage/security" \
    "$DATA_DIR/storage/support_attachments" \
    "$DATA_DIR/uploads" \
    "$DATA_DIR/images"

# As imagens e pastas que vem no codigo (fundo da entrada, .htaccess das
# pastas de upload) sao copiadas para o volume a cada arranque. Os ficheiros
# enviados pelos clientes tem nomes proprios e nao sao tocados.
for pasta in uploads images; do
    if [ -d "$PUBLIC_DIR/$pasta" ] && [ ! -L "$PUBLIC_DIR/$pasta" ]; then
        cp -r "$PUBLIC_DIR/$pasta/." "$DATA_DIR/$pasta/" 2>/dev/null || true
        rm -rf "$PUBLIC_DIR/$pasta"
    fi
    ln -sfn "$DATA_DIR/$pasta" "$PUBLIC_DIR/$pasta"
done

chown -R www-data:www-data "$DATA_DIR"

# Railway entrega a porta na variavel PORT.
port="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
