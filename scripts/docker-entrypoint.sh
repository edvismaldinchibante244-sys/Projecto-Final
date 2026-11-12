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

# Um unico MPM. A imagem base deixa o mpm_event activo e as alteracoes feitas
# durante a construcao da imagem nao ficam a valer nesta pasta, por isso a
# limpeza e feita aqui, no arranque, onde tem mesmo efeito.
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
    ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
fi

# Endereco publico: sem isto o Apache constroi os redireccionamentos com o seu
# proprio nome e porta interna (:8080), que de fora nao existe. O SetEnvIf faz
# o PHP reconhecer que o visitante chegou em HTTPS, atraves do proxy.
dominio="${RAILWAY_PUBLIC_DOMAIN:-}"
if [ -z "$dominio" ] && [ -n "${APP_URL:-}" ]; then
    dominio=$(echo "$APP_URL" | sed -e 's~^https\?://~~' -e 's~/.*$~~')
fi

conf=/etc/apache2/conf-enabled/nivora-proxy.conf
: > "$conf"
if [ -n "$dominio" ]; then
    echo "ServerName $dominio" >> "$conf"
fi
cat >> "$conf" <<'EOF'
UseCanonicalName On
UseCanonicalPhysicalPort Off
SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
EOF

echo "=== MPM activos no arranque ==="
ls /etc/apache2/mods-enabled/ | grep -i mpm || echo "(nenhum)"
apache2ctl -t 2>&1 || true

# Rotinas agendadas. O plano nao permite um servico de cron proprio, por isso
# o despachante corre aqui ao lado do Apache, de 5 em 5 minutos, entregue ao
# cron do sistema (um ciclo em segundo plano nao sobrevive ao exec do Apache).
# O cron nao herda as variaveis do contentor, por isso ficam num ficheiro so
# legivel pelo root, que a tarefa carrega antes de correr.
if [ "${CRON_EM_CONTENTOR:-1}" = "1" ] && [ -f "$APP_DIR/src/public/cron/dispatch.php" ]; then
    env_file=/etc/nivora-cron.env
    : > "$env_file"
    chmod 600 "$env_file"
    # Apenas as variaveis que as rotinas precisam, uma por linha, escapadas.
    for nome in APP_ENV APP_KEY APP_URL APP_TIMEZONE STORAGE_PATH \
                DB_HOST DB_PORT DB_NAME DB_USER DB_PASS \
                EMAIL_PROVIDER BREVO_API_KEY BREVO_FROM RESEND_API_KEY RESEND_FROM \
                USE_SMTP SMTP_HOST SMTP_PORT SMTP_USERNAME SMTP_PASSWORD SMTP_SECURE SMTP_FROM \
                REALTIME_URL REALTIME_SECRET CRON_HTTP_TOKEN; do
        valor=$(printenv "$nome" 2>/dev/null) || continue
        [ -z "$valor" ] && continue
        printf 'export %s=%s\n' "$nome" "$(printf '%s' "$valor" | sed "s/'/'\\\\''/g; s/^/'/; s/\$/'/")" >> "$env_file"
    done

    # Caminho absoluto do PHP: o cron corre com um PATH minimo, que nao inclui
    # /usr/local/bin, onde esta o php desta imagem.
    php_bin=$(command -v php 2>/dev/null || echo /usr/local/bin/php)

    printf 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n' > /etc/cron.d/nivora
    printf '*/5 * * * * root . %s; %s %s/src/public/cron/dispatch.php >> %s/storage/logs/cron.log 2>&1\n' \
        "$env_file" "$php_bin" "$APP_DIR" "$DATA_DIR" >> /etc/cron.d/nivora
    chmod 644 /etc/cron.d/nivora
    service cron start >/dev/null 2>&1 || cron
    echo "Rotinas agendadas activas (cron, de 5 em 5 minutos)."
fi

exec "$@"
