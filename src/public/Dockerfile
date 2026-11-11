FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libonig-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring curl gd \
    && a2enmod rewrite headers access_compat \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --working-dir=/var/www/html/app \
    && sed -ri 's!/var/www/html!/var/www/html/src/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '\n<Directory /var/www/html/src/public>\n AllowOverride All\n Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

CMD ["sh", "-c", "port=${PORT:-8080}; sed -ri \"s/^Listen .*/Listen ${port}/\" /etc/apache2/ports.conf; sed -ri \"s/<VirtualHost \\*:80>/<VirtualHost *:${port}>/\" /etc/apache2/sites-available/000-default.conf; exec apache2-foreground"]
