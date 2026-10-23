FROM php:8.2-cli-alpine

# Install system dependencies and build extensions
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    sqlite \
    sqlite-dev \
    libpq-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    bash \
    && docker-php-ext-install \
    bcmath \
    pdo_mysql \
    pdo_sqlite \
    zip \
    intl \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

# Install dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-audit

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
