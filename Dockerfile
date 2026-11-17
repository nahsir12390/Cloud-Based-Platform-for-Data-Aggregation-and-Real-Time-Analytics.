FROM php:8.4-apache-bookworm AS runtime
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning \
    DB_CONNECTION=pgsql SESSION_DRIVER=database CACHE_STORE=database \
    QUEUE_CONNECTION=database PORT=10000 COMPOSER_ALLOW_SUPERUSER=1
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libsqlite3-dev libonig-dev libxml2-dev unzip supervisor \
    && docker-php-ext-install -j2 pdo_pgsql pdo_sqlite mbstring pcntl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/branchflow.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/branchflow.conf
RUN printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf
EXPOSE 10000
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
