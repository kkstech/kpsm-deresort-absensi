FROM node:22-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY public/fonts ./public/fonts
RUN npm run build

FROM php:8.4-apache AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates default-mysql-client libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev libpng-dev libxml2-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath dom exif gd intl mbstring opcache pcntl pdo_mysql xml xmlreader xmlwriter zip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && printf '%s\n' '<Directory /var/www/html/public>' '    AllowOverride All' '    Require all granted' '</Directory>' > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' 'opcache.enable=1' 'opcache.memory_consumption=128' 'opcache.max_accelerated_files=20000' 'opcache.validate_timestamps=0' > "$PHP_INI_DIR/conf.d/opcache.ini" \
    && printf '%s\n' 'upload_max_filesize=100M' 'post_max_size=105M' 'max_execution_time=0' > "$PHP_INI_DIR/conf.d/database-backup.ini"

FROM php-base AS vendor
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi

FROM php-base AS runtime
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html .
COPY --from=frontend --chown=www-data:www-data /build/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/deresort-entrypoint
RUN chmod 755 /usr/local/bin/deresort-entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=mysql \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=file \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    FILESYSTEM_DISK=local \
    TRUSTED_PROXIES=REMOTE_ADDR

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s --start-period=30s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/up") === false ? 1 : 0);'

ENTRYPOINT ["deresort-entrypoint"]
CMD ["apache2-foreground"]
