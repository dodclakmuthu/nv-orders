FROM php:8.3-fpm

# System deps
RUN apt-get update && apt-get install -y \
    git unzip supervisor libzip-dev libicu-dev libonig-dev libpq-dev libssl-dev \
    && docker-php-ext-install pdo_mysql bcmath pcntl zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Workdir
WORKDIR /var/www/html

# Supervisor config path
RUN mkdir -p /var/log/supervisor /etc/supervisor/conf.d

# PHP-FPM config (optional tuning)
COPY ./.docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Horizon supervisor program
COPY ./.docker/supervisor/horizon.conf /etc/supervisor/conf.d/horizon.conf

# Nginx config added in nginx container via volume
