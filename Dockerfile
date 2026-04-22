# Image CLI : `php artisan serve` (serveur interne PHP), adapté à Railway.
FROM php:8.2-cli-bookworm

WORKDIR /var/www/html

# libpq-dev + pdo_pgsql : obligatoires pour Laravel avec PostgreSQL (Railway / Docker).
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev \
    zip unzip libpq-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Railway définit PORT dynamiquement ; sans ça, l’app écoute sur 8000 et le proxy renvoie « Application failed to respond ».
ENV PORT=8000
EXPOSE 8000

CMD ["sh", "-c", "exec php artisan serve --host=0.0.0.0 --port ${PORT:-8000}"]
