FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Default 128M is too tight for Horizon jobs that buffer HTTP responses
# in memory (see docker/worker/Dockerfile for the matching fix/rationale).
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache

RUN composer install --no-dev --optimize-autoloader --no-interaction

CMD ["php-fpm"]
