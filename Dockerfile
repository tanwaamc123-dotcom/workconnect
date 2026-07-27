FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libcurl4-openssl-dev libpq-dev postgresql-client libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl pdo_pgsql pdo_sqlite mbstring gd \
    && a2enmod rewrite headers expires deflate \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/private/uploads storage/private/mail storage/private/logs storage/private/backups tmp \
    && chown -R www-data:www-data storage tmp \
    && chmod -R 750 storage tmp

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1/?page=health-live" >/dev/null || exit 1
CMD ["apache2-foreground"]
