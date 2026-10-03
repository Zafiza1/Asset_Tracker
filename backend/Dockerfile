# PHP version must satisfy composer.json (config.platform.php = 8.4.1).
FROM php:8.4-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-client \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (redis is reached through predis, no extension needed)
RUN docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first so they stay cached until composer.json/lock change.
# Production images keep the default; docker-compose (development) passes
# COMPOSER_ARGS="" to also install dev tools (phpunit, pint).
ARG COMPOSER_ARGS="--no-dev"
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist ${COMPOSER_ARGS}

# Application code (.dockerignore keeps .env, vendor and caches out)
COPY . /var/www/html
RUN composer dump-autoload --optimize ${COMPOSER_ARGS} \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# php-fpm listens on 9000 (nginx speaks FastCGI to it)
EXPOSE 9000

CMD ["php-fpm"]
