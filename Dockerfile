# Nivora Restaurant — imagem de producao (Railway, ou qualquer servidor Docker).
#
# Os ficheiros que o sistema grava (logs, sessoes, backups, trabalhos, 2FA,
# logotipos, comprovativos, QR e imagens) ficam todos em /data, para bastar
# montar um volume ai e nada se perder entre deploys.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring curl gd zip opcache \
    && a2enmod rewrite headers access_compat \
    && rm -rf /var/lib/apt/lists/*

# OPcache ligado (exigido pela verificacao de producao do sistema).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
    } > /usr/local/etc/php/conf.d/nivora-opcache.ini \
    && { \
        echo 'expose_php=Off'; \
        echo 'upload_max_filesize=8M'; \
        echo 'post_max_size=12M'; \
        echo 'max_execution_time=60'; \
    } > /usr/local/etc/php/conf.d/nivora.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --working-dir=/var/www/html/app \
    && sed -ri 's!/var/www/html!/var/www/html/src/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '\n<Directory /var/www/html/src/public>\n Options -Indexes +FollowSymLinks\n AllowOverride All\n Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf \
    && chmod +x /var/www/html/scripts/docker-entrypoint.sh

ENV STORAGE_PATH=/data/storage

ENTRYPOINT ["/var/www/html/scripts/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
