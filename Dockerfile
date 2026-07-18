FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev postgresql-client libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite mbstring gd \
    && a2enmod rewrite headers expires deflate \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/private/uploads storage/private/mail tmp \
    && chown -R www-data:www-data storage tmp \
    && chmod -R 750 storage tmp

EXPOSE 80
CMD ["apache2-foreground"]
